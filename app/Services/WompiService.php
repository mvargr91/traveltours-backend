<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\Reservas\Reserva;
use App\Models\Seguridad\AuditoriaTabla;

/**
 * Pago en línea con Wompi (Web Checkout).
 *
 * Flujo:
 *  1. iniciarPago(): registra un intento en `pagos` y devuelve los datos del checkout, firmados con el
 *     secreto de integridad para que el monto no se pueda alterar en el navegador.
 *  2. El viajero paga en checkout.wompi.co y vuelve a /pago/resultado?id={transacción}.
 *  3. La transacción llega por dos vías (cualquiera aplica el pago; la segunda no hace nada):
 *     - el portal pide consultarTransaccion() al volver (sirve en local sin webhook);
 *     - Wompi envía el evento transaction.updated al webhook (verificarEvento()).
 *  4. aplicarTransaccion(): si se aprueba, la reserva pasa a "aceptada" y se descuentan los cupos.
 *
 * Llaves en .env: WOMPI_PUBLIC_KEY, WOMPI_PRIVATE_KEY, WOMPI_INTEGRITY_SECRET, WOMPI_EVENTS_SECRET, WOMPI_API_URL.
 */
class WompiService
{
    // Estados de Wompi -> estados de `pagos`.
    private const ESTADOS = [
        'APPROVED' => 'aprobado',
        'DECLINED' => 'rechazado',
        'VOIDED' => 'anulado',
        'ERROR' => 'error',
        'PENDING' => 'pendiente',
    ];

    public static function iniciarPago($reservaId)
    {
        $reserva = DB::table('reservas')->where('id', $reservaId)->first();
        $montoCentavos = (int) round(((float) $reserva->valor_total) * 100);
        $referencia = $reserva->codigo_reserva . '-' . strtoupper(Str::random(6));

        DB::table('pagos')->insert([
            'reserva_id' => $reserva->id,
            'referencia' => $referencia,
            'monto_centavos' => $montoCentavos,
            'moneda' => 'COP',
            'estado' => 'pendiente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $config = config('services.wompi');
        $firma = hash('sha256', $referencia . $montoCentavos . 'COP' . $config['integrity_secret']);

        return [
            'url' => $config['checkout_url'],
            'parametros' => [
                'public-key' => $config['public_key'],
                'currency' => 'COP',
                'amount-in-cents' => $montoCentavos,
                'reference' => $referencia,
                'signature:integrity' => $firma,
                // Wompi rechaza redirect-url con localhost: vuelve al backend y este reenvía al front.
                'redirect-url' => route('publico.pagos.retorno'),
                'customer-data:email' => $reserva->correo,
                'customer-data:full-name' => $reserva->nombre,
            ],
        ];
    }

    // Consulta la transacción en Wompi (endpoint público) y la aplica. Devuelve la transacción o null.
    public static function consultarTransaccion($transaccionId)
    {
        $respuesta = Http::timeout(15)->get(config('services.wompi.api_url') . '/transactions/' . urlencode($transaccionId));
        if (!$respuesta->successful() || !isset($respuesta['data'])) {
            return null;
        }
        $transaccion = $respuesta['data'];
        self::aplicarTransaccion($transaccion);
        return $transaccion;
    }

    /**
     * Firma del evento: SHA256 de los valores de signature.properties (en orden) + timestamp + secreto de eventos.
     * https://docs.wompi.co/docs/colombia/eventos/
     */
    public static function verificarEvento(array $evento)
    {
        $propiedades = $evento['signature']['properties'] ?? null;
        $checksum = $evento['signature']['checksum'] ?? null;
        if (!is_array($propiedades) || !$checksum || !isset($evento['timestamp'])) {
            return false;
        }

        $cadena = '';
        foreach ($propiedades as $ruta) {
            $cadena .= data_get($evento['data'] ?? [], $ruta);
        }
        $cadena .= $evento['timestamp'] . config('services.wompi.events_secret');

        return hash_equals(strtolower($checksum), hash('sha256', $cadena));
    }

    /**
     * Registra el resultado de la transacción en `pagos` y, si se aprobó, confirma la reserva.
     * Es idempotente: el webhook y la consulta del portal pueden llegar ambos, en cualquier orden.
     */
    public static function aplicarTransaccion(array $transaccion)
    {
        $estadoReservaAnterior = null;
        $reservaId = null;

        DB::transaction(function () use ($transaccion, &$estadoReservaAnterior, &$reservaId) {
            $pago = DB::table('pagos')->where('referencia', $transaccion['reference'] ?? '')->lockForUpdate()->first();
            if (!$pago) {
                Log::warning('Wompi: transacción con referencia desconocida ' . ($transaccion['reference'] ?? '(sin referencia)'));
                return;
            }
            // El monto pagado debe ser exactamente el firmado al iniciar el pago.
            if ((int) ($transaccion['amount_in_cents'] ?? 0) !== (int) $pago->monto_centavos || ($transaccion['currency'] ?? '') !== $pago->moneda) {
                Log::warning("Wompi: el monto de la transacción no coincide con el pago {$pago->referencia}");
                return;
            }
            if ($pago->estado === 'aprobado') {
                return;
            }

            $estado = self::ESTADOS[$transaccion['status'] ?? ''] ?? 'pendiente';
            DB::table('pagos')->where('id', $pago->id)->update([
                'estado' => $estado,
                'transaccion_id' => $transaccion['id'] ?? null,
                'metodo_pago' => $transaccion['payment_method_type'] ?? null,
                'respuesta' => json_encode($transaccion),
                'updated_at' => now(),
            ]);

            if ($estado !== 'aprobado') {
                return;
            }

            $reserva = DB::table('reservas')->where('id', $pago->reserva_id)->lockForUpdate()->first();
            if ($reserva->estado !== 'pendiente_pago') {
                // Pagó una reserva que ya no esperaba pago (ej. cancelada): queda registrado para revisión manual.
                Log::warning("Wompi: pago aprobado {$pago->referencia} para la reserva {$reserva->codigo_reserva} en estado {$reserva->estado}");
                return;
            }

            $original = json_encode($reserva);
            self::descontarCupos($reserva);
            DB::table('reservas')->where('id', $reserva->id)->update([
                'estado' => 'aceptada',
                'cupos_descontados' => true,
                'usuario_modificacion_id' => 0,
                'usuario_modificacion_nombre' => 'Pago Wompi',
                'updated_at' => now(),
            ]);

            AuditoriaTabla::crear([
                'externo' => true,
                'id_recurso' => $reserva->id,
                'nombre_recurso' => Reserva::class,
                'descripcion_recurso' => $reserva->codigo_reserva,
                'accion' => 'Modificar',
                'recurso_original' => $original,
                'recurso_resultante' => json_encode(DB::table('reservas')->where('id', $reserva->id)->first()),
            ]);

            $estadoReservaAnterior = $reserva->estado;
            $reservaId = $reserva->id;
        });

        if ($reservaId) {
            NotificadorReservas::estadoCambiado($reservaId, $estadoReservaAnterior);
        }
    }

    private static function descontarCupos($reserva)
    {
        if (!$reserva->disponibilidad_id) {
            return;
        }
        $disponibilidad = DB::table('experiencia_disponibilidad')->where('id', $reserva->disponibilidad_id)->lockForUpdate()->first();
        if (!$disponibilidad) {
            return;
        }
        if ($disponibilidad->cupos_disponibles < $reserva->cantidad_personas) {
            // Ya está pagada: se confirma igual y se avisa para que el proveedor ajuste la capacidad.
            Log::warning("Wompi: la reserva {$reserva->codigo_reserva} se pagó con más personas que cupos disponibles");
        }
        DB::table('experiencia_disponibilidad')->where('id', $disponibilidad->id)->update([
            'cupos_disponibles' => max(0, $disponibilidad->cupos_disponibles - $reserva->cantidad_personas),
            'updated_at' => now(),
        ]);
    }

    // Devuelve los cupos de una reserva pagada que se cancela o rechaza.
    public static function devolverCupos($reservaId)
    {
        $reserva = DB::table('reservas')->where('id', $reservaId)->lockForUpdate()->first();
        if (!$reserva || !$reserva->cupos_descontados || !$reserva->disponibilidad_id) {
            return;
        }
        DB::table('experiencia_disponibilidad')
            ->where('id', $reserva->disponibilidad_id)
            ->increment('cupos_disponibles', $reserva->cantidad_personas);
        DB::table('reservas')->where('id', $reserva->id)->update(['cupos_descontados' => false]);
    }

    public static function configurado()
    {
        $config = config('services.wompi');
        return !empty($config['public_key']) && !empty($config['integrity_secret']);
    }
}
