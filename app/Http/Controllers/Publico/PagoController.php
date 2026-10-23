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
use App\Services\CobroProveedores;
use App\Models\Finanzas\CuentaPasarela;

/**
 * Pago en línea de reservas con la pasarela del proveedor de la experiencia (sin sesión: la reserva se identifica
 * con código + correo). Retorno, consulta y webhook llevan el código de la cuenta (cuentas_pasarela.codigo); sin él
 * (URL anteriores) se usa la cuenta de la plataforma. Las liquidaciones de proveedores usan estas mismas rutas.
 */
class PagoController extends Controller
{
    private const PATRON_TRANSACCION = '/^[A-Za-z0-9\-]{5,60}$/';
    private const PATRON_CUENTA = '/^[a-z0-9]{10,40}$/';

    // URL del checkout de la pasarela. El monto sale de la reserva, nunca del navegador.
    public function iniciar(Request $request, $codigo)
    {
        DB::beginTransaction();
        try {
            $datos = array_merge($request->all(), ['codigo' => $codigo]);
            $validator = Validator::make($datos, [
                'codigo' => 'string|required|max:30',
                'correo' => 'email|required|max:150',
                'concepto' => 'nullable|in:total,abono,saldo',
            ]);
            if ($validator->fails()) {
                return response(get_response_body(format_messages_validator($validator)), Response::HTTP_BAD_REQUEST);
            }

            $reserva = DB::table('reservas')
                ->join('experiencias', 'experiencias.id', '=', 'reservas.experiencia_id')
                ->where('reservas.codigo_reserva', strtoupper(trim($codigo)))
                ->whereRaw('LOWER(reservas.correo) = ?', [strtolower(trim($datos['correo']))])
                ->select('reservas.id', 'reservas.estado', 'reservas.valor_total', 'reservas.valor_pagado', 'experiencias.porcentaje_abono', CuentaPasarela::sqlModalidadEfectiva())
                ->first();
            if (!$reserva) {
                return response(get_response_body(['No encontramos una reserva con ese código y correo.']), Response::HTTP_NOT_FOUND);
            }
            // Sin pasarela activa del proveedor no hay pago en línea: la reserva se paga como acuerde con él.
            if ($reserva->modalidad_pago !== 'pago_en_linea') {
                return response(get_response_body(['El pago en línea no está disponible para esta reserva. Comunícate con el proveedor.']), Response::HTTP_SERVICE_UNAVAILABLE);
            }
            if ((float) $reserva->valor_total <= 0) {
                return response(get_response_body(['La reserva no tiene valor a pagar. Comunícate con el proveedor.']), Response::HTTP_CONFLICT);
            }

            // Total, abono o saldo: lo que la reserva permita pagar ahora (el monto nunca viene del navegador).
            $opciones = PagoService::opcionesDePago($reserva->estado, $reserva->valor_total, $reserva->valor_pagado, $reserva->modalidad_pago, $reserva->porcentaje_abono);
            if (!$opciones) {
                return response(get_response_body(['Esta reserva no tiene un pago pendiente.']), Response::HTTP_CONFLICT);
            }
            $concepto = $datos['concepto'] ?? (isset($opciones['total']) ? 'total' : 'saldo');
            if (!isset($opciones[$concepto])) {
                return response(get_response_body(['Esa forma de pago no está disponible para esta reserva.']), Response::HTTP_CONFLICT);
            }

            $checkout = PagoService::iniciarPago($reserva->id, $opciones[$concepto], $concepto);
            DB::commit();
            return response()->json($checkout, Response::HTTP_OK);
        } catch (Exception $e) {
            DB::rollback();
            Log::error("Pagos: no se pudo iniciar el pago de {$codigo}: " . $e->getMessage());
            return response(get_response_body(['No pudimos iniciar el pago. Inténtalo de nuevo.']), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * El portal (o el panel, para liquidaciones) la llama al volver del checkout (?pasarela=...&cuenta=...):
     * consulta la pasarela con las llaves de esa cuenta, aplica el pago y devuelve el resultado.
     */
    public function transaccion(Request $request, $id)
    {
        try {
            $pasarela = $request->query('pasarela') ?: 'wompi';
            $codigoCuenta = $request->query('cuenta');
            if (!preg_match(self::PATRON_TRANSACCION, $id) || !isset(PagoService::PASARELAS[$pasarela])
                || ($codigoCuenta && !preg_match(self::PATRON_CUENTA, $codigoCuenta))) {
                return response(get_response_body(['Transacción inválida.']), Response::HTTP_BAD_REQUEST);
            }
            $cuenta = CuentaPasarela::resolver($pasarela, $codigoCuenta);
            if (!$cuenta) {
                return response(get_response_body(['No encontramos la pasarela de este pago.']), Response::HTTP_NOT_FOUND);
            }
            $transaccion = PagoService::consultarTransaccion($id, $cuenta);
            if (!$transaccion) {
                return response(get_response_body(['No encontramos esa transacción en la pasarela de pagos.']), Response::HTTP_NOT_FOUND);
            }

            if (CobroProveedores::esReferenciaDeLiquidacion($transaccion['referencia'])) {
                $liquidacion = DB::table('pagos_liquidacion')
                    ->join('liquidaciones', 'liquidaciones.id', '=', 'pagos_liquidacion.liquidacion_id')
                    ->where('pagos_liquidacion.referencia', $transaccion['referencia'])
                    ->where('pagos_liquidacion.cuenta_pasarela_id', $cuenta->id)
                    ->select('liquidaciones.id', 'liquidaciones.codigo', 'liquidaciones.estado')
                    ->first();
                return response()->json([
                    'estado_pago' => $transaccion['estado'],
                    'metodo_pago' => $transaccion['metodo_pago'],
                    'liquidacion' => $liquidacion,
                ], Response::HTTP_OK);
            }

            $reserva = DB::table('pagos')
                ->join('reservas', 'reservas.id', '=', 'pagos.reserva_id')
                ->join('experiencias', 'experiencias.id', '=', 'reservas.experiencia_id')
                ->where('pagos.referencia', $transaccion['referencia'])
                ->where('pagos.cuenta_pasarela_id', $cuenta->id)
                ->select(
                    'reservas.codigo_reserva',
                    'reservas.estado',
                    'reservas.correo',
                    'reservas.fecha',
                    'reservas.hora_inicio',
                    'reservas.cantidad_personas',
                    'reservas.valor_total',
                    'reservas.valor_pagado',
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

    // La pasarela devuelve aquí al pagador; se reenvía a la página de resultado del portal (o del panel, si es el
    // pago de una liquidación) con el id de la transacción y la cuenta con la que se consulta.
    public function retorno(Request $request, $pasarela, $cuenta = null)
    {
        $id = (string) CuentaPasarela::instancia($pasarela)->idDeRetorno($request);
        $parametros = array_filter([
            'id' => preg_match(self::PATRON_TRANSACCION, $id) ? $id : null,
            'pasarela' => $pasarela,
            'cuenta' => $cuenta && preg_match(self::PATRON_CUENTA, $cuenta) ? $cuenta : null,
            'env' => $request->query('env') === 'test' ? 'test' : null,
        ]);
        $ruta = $request->query('destino') === 'liquidacion' ? '/liquidaciones/pago' : '/pago/resultado';
        return redirect()->away(config('app.front_url') . $ruta . '?' . http_build_query($parametros));
    }

    // Webhook de la cuenta (URL de eventos / notificaciones). Solo procesa eventos con firma válida para esa cuenta.
    public function eventos(Request $request, $pasarela, $cuenta = null)
    {
        $cuentaPasarela = CuentaPasarela::resolver($pasarela, $cuenta);
        if (!$cuentaPasarela) {
            Log::warning("Pagos: evento de {$pasarela} para una cuenta desconocida (" . ($cuenta ?: 'plataforma') . ')');
            return response()->json(['ok' => false], Response::HTTP_NOT_FOUND);
        }
        $servicio = $cuentaPasarela->pasarela();
        if (!$servicio->verificarEvento($request)) {
            Log::warning("{$servicio->nombre()}: evento con firma inválida (cuenta {$cuentaPasarela->id})");
            return response()->json(['ok' => false], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $transaccion = $servicio->transaccionDeEvento($request);
            if ($transaccion) {
                PagoService::aplicarTransaccion($transaccion, $cuentaPasarela);
            }
        } catch (Exception $e) {
            // 500 hace que la pasarela reintente el evento más tarde.
            Log::error("{$servicio->nombre()}: error procesando evento: " . $e->getMessage());
            return response()->json(['ok' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        return response()->json(['ok' => true], Response::HTTP_OK);
    }
}
