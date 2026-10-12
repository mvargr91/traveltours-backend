<?php

namespace App\Http\Controllers\Publico;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Services\PagoService;

/**
 * Pago en línea de reservas con la pasarela de la instalación (sin sesión: la reserva se identifica con código + correo).
 */
class PagoController extends Controller
{
    // URL del checkout de la pasarela. El monto sale de la reserva, nunca del navegador.
    public function iniciar(Request $request, $codigo)
    {
        DB::beginTransaction();
        try {
            $datos = array_merge($request->all(), ['codigo' => $codigo]);
            $validator = Validator::make($datos, [
                'codigo' => 'string|required|max:30',
                'correo' => 'email|required|max:150',
            ]);
            if ($validator->fails()) {
                return response(get_response_body(format_messages_validator($validator)), Response::HTTP_BAD_REQUEST);
            }
            if (!PagoService::configurado()) {
                return response(get_response_body(['El pago en línea no está disponible en este momento.']), Response::HTTP_SERVICE_UNAVAILABLE);
            }

            $reserva = DB::table('reservas')
                ->where('codigo_reserva', strtoupper(trim($codigo)))
                ->whereRaw('LOWER(correo) = ?', [strtolower(trim($datos['correo']))])
                ->select('id', 'estado', 'valor_total')
                ->first();
            if (!$reserva) {
                return response(get_response_body(['No encontramos una reserva con ese código y correo.']), Response::HTTP_NOT_FOUND);
            }
            if ($reserva->estado !== 'pendiente_pago') {
                return response(get_response_body(['Esta reserva no tiene un pago pendiente.']), Response::HTTP_CONFLICT);
            }
            if ((float) $reserva->valor_total <= 0) {
                return response(get_response_body(['La reserva no tiene valor a pagar. Comunícate con el proveedor.']), Response::HTTP_CONFLICT);
            }

            $checkout = PagoService::iniciarPago($reserva->id);
            DB::commit();
            return response()->json($checkout, Response::HTTP_OK);
        } catch (Exception $e) {
            DB::rollback();
            Log::error("Pagos: no se pudo iniciar el pago de {$codigo}: " . $e->getMessage());
            return response(get_response_body(['No pudimos iniciar el pago. Inténtalo de nuevo.']), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // El portal la llama al volver del checkout (?id=...): consulta la pasarela, aplica el pago y devuelve el resultado.
    public function transaccion(Request $request, $id)
    {
        try {
            $pasarela = $request->query('pasarela');
            if (!preg_match('/^[A-Za-z0-9\-]{5,60}$/', $id) || ($pasarela && !isset(PagoService::PASARELAS[$pasarela]))) {
                return response(get_response_body(['Transacción inválida.']), Response::HTTP_BAD_REQUEST);
            }
            $transaccion = PagoService::consultarTransaccion($id, $pasarela);
            if (!$transaccion) {
                return response(get_response_body(['No encontramos esa transacción en la pasarela de pagos.']), Response::HTTP_NOT_FOUND);
            }

            $reserva = DB::table('pagos')
                ->join('reservas', 'reservas.id', '=', 'pagos.reserva_id')
                ->join('experiencias', 'experiencias.id', '=', 'reservas.experiencia_id')
                ->where('pagos.referencia', $transaccion['referencia'])
                ->select(
                    'reservas.codigo_reserva',
                    'reservas.estado',
                    'reservas.correo',
                    'reservas.fecha',
                    'reservas.hora_inicio',
                    'reservas.cantidad_personas',
                    'reservas.valor_total',
                    'experiencias.nombre as experiencia_nombre',
                    'experiencias.slug as experiencia_slug',
                )
                ->first();

            return response()->json([
                'estado_pago' => $transaccion['estado'],
                'metodo_pago' => $transaccion['metodo_pago'],
                'reserva' => $reserva,
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            // El detalle (ej. errores de conexión con la pasarela) va al log, no al viajero.
            Log::error("Pagos: no se pudo consultar la transacción {$id}: " . $e->getMessage());
            return response(
                get_response_body(['No pudimos confirmar el pago en este momento. Revisa tu reserva en unos minutos.']),
                Response::HTTP_BAD_GATEWAY
            );
        }
    }

    // La pasarela devuelve aquí al viajero; se reenvía a la página de resultado del portal con el id de la transacción.
    public function retorno(Request $request, $pasarela)
    {
        $id = (string) PagoService::pasarela($pasarela)->idDeRetorno($request);
        $parametros = array_filter([
            'id' => preg_match('/^[A-Za-z0-9\-]{5,60}$/', $id) ? $id : null,
            'pasarela' => $pasarela,
            'env' => $request->query('env') === 'test' ? 'test' : null,
        ]);
        return redirect()->away(rtrim(env('APP_FRONT_URL', ''), '/') . '/pago/resultado?' . http_build_query($parametros));
    }

    // Webhook de la pasarela (URL de eventos / notificaciones de su panel). Solo procesa eventos con firma válida.
    public function eventos(Request $request, $pasarela)
    {
        $servicio = PagoService::pasarela($pasarela);
        if (!$servicio->verificarEvento($request)) {
            Log::warning("{$servicio->nombre()}: evento con firma inválida");
            return response()->json(['ok' => false], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $transaccion = $servicio->transaccionDeEvento($request);
            if ($transaccion) {
                PagoService::aplicarTransaccion($transaccion, $pasarela);
            }
        } catch (Exception $e) {
            // 500 hace que la pasarela reintente el evento más tarde.
            Log::error("{$servicio->nombre()}: error procesando evento: " . $e->getMessage());
            return response()->json(['ok' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        return response()->json(['ok' => true], Response::HTTP_OK);
    }
}
