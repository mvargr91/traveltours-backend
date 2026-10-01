<?php

namespace App\Http\Controllers\Publico;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Services\WompiService;

/**
 * Pago en línea de reservas con Wompi (sin sesión: la reserva se identifica con código + correo).
 */
class PagoController extends Controller
{
    // Datos para abrir el Web Checkout de Wompi. El monto sale de la reserva, nunca del navegador.
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
            if (!WompiService::configurado()) {
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

            $checkout = WompiService::iniciarPago($reserva->id);
            DB::commit();
            return response()->json($checkout, Response::HTTP_OK);
        } catch (Exception $e) {
            DB::rollback();
            Log::error("Wompi: no se pudo iniciar el pago de {$codigo}: " . $e->getMessage());
            return response(get_response_body(['No pudimos iniciar el pago. Inténtalo de nuevo.']), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // El portal la llama al volver del checkout (?id=...): consulta Wompi, aplica el pago y devuelve el resultado.
    public function transaccion($id)
    {
        try {
            if (!preg_match('/^[A-Za-z0-9\-]{5,60}$/', $id)) {
                return response(get_response_body(['Transacción inválida.']), Response::HTTP_BAD_REQUEST);
            }
            $transaccion = WompiService::consultarTransaccion($id);
            if (!$transaccion) {
                return response(get_response_body(['No encontramos esa transacción en Wompi.']), Response::HTTP_NOT_FOUND);
            }

            $reserva = DB::table('pagos')
                ->join('reservas', 'reservas.id', '=', 'pagos.reserva_id')
                ->join('experiencias', 'experiencias.id', '=', 'reservas.experiencia_id')
                ->where('pagos.referencia', $transaccion['reference'] ?? '')
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
                'estado_pago' => $transaccion['status'] ?? null,
                'metodo_pago' => $transaccion['payment_method_type'] ?? null,
                'reserva' => $reserva,
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            // El detalle (ej. errores de conexión con Wompi) va al log, no al viajero.
            Log::error("Wompi: no se pudo consultar la transacción {$id}: " . $e->getMessage());
            return response(
                get_response_body(['No pudimos confirmar el pago en este momento. Revisa tu reserva en unos minutos.']),
                Response::HTTP_BAD_GATEWAY
            );
        }
    }

    // Wompi devuelve aquí al viajero (?id={transacción}&env=test); se reenvía a la página de resultado del portal.
    public function retorno(Request $request)
    {
        $parametros = array_filter([
            'id' => preg_match('/^[A-Za-z0-9\-]{5,60}$/', (string) $request->query('id')) ? $request->query('id') : null,
            'env' => $request->query('env') === 'test' ? 'test' : null,
        ]);
        return redirect()->away(rtrim(env('APP_FRONT_URL', ''), '/') . '/pago/resultado?' . http_build_query($parametros));
    }

    // Webhook de Wompi (URL de eventos del panel de comercios). Solo procesa eventos con firma válida.
    public function eventos(Request $request)
    {
        $evento = $request->all();
        if (!WompiService::verificarEvento($evento)) {
            Log::warning('Wompi: evento con firma inválida', ['evento' => $evento['event'] ?? null]);
            return response()->json(['ok' => false], Response::HTTP_UNAUTHORIZED);
        }

        try {
            if (($evento['event'] ?? '') === 'transaction.updated' && isset($evento['data']['transaction'])) {
                WompiService::aplicarTransaccion($evento['data']['transaction']);
            }
        } catch (Exception $e) {
            // 500 hace que Wompi reintente el evento más tarde.
            Log::error('Wompi: error procesando evento: ' . $e->getMessage());
            return response()->json(['ok' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        return response()->json(['ok' => true], Response::HTTP_OK);
    }
}
