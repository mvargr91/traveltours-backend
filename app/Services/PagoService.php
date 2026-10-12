<?php

namespace App\Services;

use InvalidArgumentException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Reservas\Reserva;
use App\Models\Seguridad\AuditoriaTabla;
use App\Services\Pagos\PasarelaPago;
use App\Services\Pagos\WompiPasarela;
use App\Services\Pagos\MercadoPagoPasarela;

/**
 * Pago en línea de reservas. La pasarela de la instalación se elige con PAGOS_PASARELA en .env; lo propio
 * de cada una (checkout, consulta, webhook) vive en App\Services\Pagos.
 *
 * Flujo:
 *  1. iniciarPago(): registra un intento en `pagos` y devuelve la URL del checkout.
 *  2. El viajero paga en la pasarela y vuelve a /pago/resultado?id={transacción}.
 *  3. La transacción llega por dos vías (cualquiera aplica el pago; la segunda no hace nada):
 *     - el portal pide consultarTransaccion() al volver (sirve en local sin webhook);
 *     - la pasarela envía el evento al webhook.
 *  4. aplicarTransaccion(): si se aprueba, la reserva pasa a "aceptada" y se descuentan los cupos.
 */
class PagoService
{
    public const PASARELAS = [
        'wompi' => WompiPasarela::class,
        'mercadopago' => MercadoPagoPasarela::class,
    ];

    // Pasarela indicada o, sin nombre, la activa de la instalación.
    public static function pasarela($nombre = null): PasarelaPago
    {
        $nombre = $nombre ?: config('services.pagos.pasarela');
        if (!isset(self::PASARELAS[$nombre])) {
            throw new InvalidArgumentException("Pasarela de pagos desconocida: {$nombre}");
        }
        $clase = self::PASARELAS[$nombre];
        return new $clase();
    }

    public static function configurado()
    {
        return isset(self::PASARELAS[config('services.pagos.pasarela')]) && self::pasarela()->configurada();
    }

    public static function iniciarPago($reservaId)
    {
        $pasarela = config('services.pagos.pasarela');
        $reserva = DB::table('reservas')->where('id', $reservaId)->first();
        $montoCentavos = (int) round(((float) $reserva->valor_total) * 100);
        $referencia = $reserva->codigo_reserva . '-' . strtoupper(Str::random(6));

        DB::table('pagos')->insert([
            'reserva_id' => $reserva->id,
            'pasarela' => $pasarela,
            'referencia' => $referencia,
            'monto_centavos' => $montoCentavos,
            'moneda' => 'COP',
            'estado' => 'pendiente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['url' => self::pasarela($pasarela)->crearCheckout($reserva, $referencia, $montoCentavos)];
    }

    // Consulta la transacción en la pasarela y la aplica. Devuelve la transacción normalizada o null.
    public static function consultarTransaccion($transaccionId, $pasarela = null)
    {
        $pasarela = $pasarela ?: config('services.pagos.pasarela');
        $transaccion = self::pasarela($pasarela)->consultarTransaccion($transaccionId);
        if ($transaccion) {
            self::aplicarTransaccion($transaccion, $pasarela);
        }
        return $transaccion;
    }

    /**
     * Registra el resultado de la transacción en `pagos` y, si se aprobó, confirma la reserva.
     * Es idempotente: el webhook y la consulta del portal pueden llegar ambos, en cualquier orden.
     */
    public static function aplicarTransaccion(array $transaccion, $pasarela)
    {
        $estadoReservaAnterior = null;
        $reservaId = null;
        $nombre = self::pasarela($pasarela)->nombre();

        DB::transaction(function () use ($transaccion, $pasarela, $nombre, &$estadoReservaAnterior, &$reservaId) {
            $pago = DB::table('pagos')
                ->where('referencia', $transaccion['referencia'])
                ->where('pasarela', $pasarela)
                ->lockForUpdate()
                ->first();
            if (!$pago) {
                Log::warning("{$nombre}: transacción con referencia desconocida " . ($transaccion['referencia'] ?: '(sin referencia)'));
                return;
            }
            // El monto pagado debe ser exactamente el registrado al iniciar el pago.
            if ($transaccion['monto_centavos'] !== (int) $pago->monto_centavos || $transaccion['moneda'] !== $pago->moneda) {
                Log::warning("{$nombre}: el monto de la transacción no coincide con el pago {$pago->referencia}");
                return;
            }
            if ($pago->estado === 'aprobado') {
                return;
            }

            $estado = $transaccion['estado'];
            DB::table('pagos')->where('id', $pago->id)->update([
                'estado' => $estado,
                'transaccion_id' => $transaccion['id'],
                'metodo_pago' => $transaccion['metodo_pago'],
                'respuesta' => json_encode($transaccion['respuesta']),
                'updated_at' => now(),
            ]);

            if ($estado !== 'aprobado') {
                return;
            }

            $reserva = DB::table('reservas')->where('id', $pago->reserva_id)->lockForUpdate()->first();
            if ($reserva->estado !== 'pendiente_pago') {
                // Pagó una reserva que ya no esperaba pago (ej. cancelada): queda registrado para revisión manual.
                Log::warning("{$nombre}: pago aprobado {$pago->referencia} para la reserva {$reserva->codigo_reserva} en estado {$reserva->estado}");
                return;
            }

            $original = json_encode($reserva);
            self::descontarCupos($reserva);
            DB::table('reservas')->where('id', $reserva->id)->update([
                'estado' => 'aceptada',
                'cupos_descontados' => true,
                'usuario_modificacion_id' => 0,
                'usuario_modificacion_nombre' => "Pago {$nombre}",
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
            Log::warning("Pagos: la reserva {$reserva->codigo_reserva} se pagó con más personas que cupos disponibles");
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
}
