<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Reservas\Reserva;
use App\Models\Seguridad\AuditoriaTabla;
use App\Models\Finanzas\CuentaPasarela;

/**
 * Pago en línea de reservas. Cada proveedor cobra con su propia pasarela (cuentas_pasarela); los tours de la
 * plataforma, con la del administrador. Lo propio de cada pasarela (checkout, consulta, webhook) vive en
 * App\Services\Pagos.
 *
 * Flujo:
 *  1. iniciarPago(): registra un intento en `pagos` (con la cuenta del proveedor) y devuelve la URL del checkout.
 *  2. El viajero paga en la pasarela y vuelve a /pago/resultado?id={transacción}&pasarela=...&cuenta=...
 *  3. La transacción llega por dos vías (cualquiera aplica el pago; la segunda no hace nada):
 *     - el portal pide consultarTransaccion() al volver (sirve en local sin webhook);
 *     - la pasarela envía el evento al webhook de la cuenta.
 *  4. aplicarTransaccion(): si se aprueba, la reserva pasa a "aceptada" y se descuentan los cupos.
 *     Las referencias de liquidaciones (cobro a proveedores) las aplica CobroProveedores.
 *
 * Abono: si la experiencia tiene porcentaje_abono, el viajero puede separar el cupo pagando solo esa parte
 * (la reserva queda aceptada con saldo pendiente) y pagar el saldo después. reservas.valor_pagado lleva la
 * suma de los pagos aprobados; opcionesDePago() dice qué se puede pagar en cada momento.
 */
class PagoService
{
    public const PASARELAS = CuentaPasarela::PASARELAS;

    // Pagos recibidos por fuera del portal y registrados a mano en la reserva (pagos.pasarela = "manual").
    public const MANUAL = 'manual';
    public const MEDIOS_MANUALES = ['efectivo', 'transferencia', 'datafono', 'otro'];
    // Estados de la reserva en los que se le puede registrar un pago manual.
    private const ESTADOS_CON_PAGO_MANUAL = ['pendiente', 'pendiente_pago', 'aceptada', 'finalizada', 'no_asistio'];
    // Dinero devuelto al viajero (lo registra el administrador): va en `pagos` con este concepto y no suma a lo pagado.
    public const REEMBOLSO = 'reembolso';

    /**
     * Lo que se puede pagar en línea ahora mismo: [concepto => monto].
     *  - pendiente_pago: "total" y, si la experiencia lo permite y aún no se ha pagado nada, "abono".
     *  - aceptada (pago en línea) con saldo: "saldo".
     * $modalidad es la efectiva (CuentaPasarela::sqlModalidadEfectiva): sin pasarela activa no hay pago en línea.
     */
    public static function opcionesDePago($estado, $total, $pagado, $modalidad, $porcentajeAbono): array
    {
        $saldo = round((float) $total - (float) $pagado, 2);
        if ($saldo <= 0 || $modalidad !== 'pago_en_linea') {
            return [];
        }
        if ($estado === 'pendiente_pago') {
            $opciones = [];
            $abono = round((float) $total * (int) $porcentajeAbono / 100);
            if ((float) $pagado <= 0 && $abono > 0 && $abono < $saldo) {
                $opciones['abono'] = $abono;
            }
            return $opciones + ['total' => $saldo];
        }
        if ($estado === 'aceptada') {
            return ['saldo' => $saldo];
        }
        return [];
    }

    // $monto y $concepto salen de opcionesDePago(), nunca del navegador. Cobra con la pasarela del proveedor.
    public static function iniciarPago($reservaId, $monto, $concepto = 'total')
    {
        $reserva = DB::table('reservas')->where('id', $reservaId)->first();
        $cuenta = CuentaPasarela::activaDe($reserva->proveedor_id);
        if (!$cuenta) {
            throw new \Exception('El proveedor no tiene una pasarela de pago activa.');
        }
        $montoCentavos = (int) round(((float) $monto) * 100);
        $referencia = $reserva->codigo_reserva . '-' . strtoupper(Str::random(6));

        DB::table('pagos')->insert([
            'reserva_id' => $reserva->id,
            'pasarela' => $cuenta->pasarela,
            'cuenta_pasarela_id' => $cuenta->id,
            'concepto' => $concepto,
            'referencia' => $referencia,
            'monto_centavos' => $montoCentavos,
            'moneda' => 'COP',
            'estado' => 'pendiente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['url' => $cuenta->pasarela()->crearCheckout([
            'referencia' => $referencia,
            'monto_centavos' => $montoCentavos,
            'descripcion' => 'Reserva ' . $reserva->codigo_reserva,
            'correo' => $reserva->correo,
            'nombre' => $reserva->nombre,
            'retorno_url' => $cuenta->urlRetorno(),
            'notificacion_url' => $cuenta->urlEventos(),
        ])];
    }

    // Consulta la transacción en la pasarela de la cuenta y la aplica. Devuelve la transacción normalizada o null.
    public static function consultarTransaccion($transaccionId, CuentaPasarela $cuenta)
    {
        $transaccion = $cuenta->pasarela()->consultarTransaccion($transaccionId);
        if ($transaccion) {
            self::aplicarTransaccion($transaccion, $cuenta);
        }
        return $transaccion;
    }

    /**
     * Registra el resultado de la transacción en `pagos` y, si se aprobó, confirma la reserva.
     * Es idempotente: el webhook y la consulta del portal pueden llegar ambos, en cualquier orden.
     * Solo aplica pagos creados con esa misma cuenta: un proveedor no puede tocar los pagos de otro.
     */
    public static function aplicarTransaccion(array $transaccion, CuentaPasarela $cuenta)
    {
        if (CobroProveedores::esReferenciaDeLiquidacion($transaccion['referencia'])) {
            CobroProveedores::aplicarTransaccion($transaccion, $cuenta);
            return;
        }

        $estadoReservaAnterior = null;
        $reservaId = null;
        $confirmada = false;
        $nombre = $cuenta->pasarela()->nombre();

        DB::transaction(function () use ($transaccion, $cuenta, $nombre, &$estadoReservaAnterior, &$reservaId, &$confirmada) {
            $pago = DB::table('pagos')
                ->where('referencia', $transaccion['referencia'])
                ->where('cuenta_pasarela_id', $cuenta->id)
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
            // Se paga para confirmar (pendiente_pago) o para completar el saldo de una reserva ya confirmada con abono.
            if (!in_array($reserva->estado, ['pendiente_pago', 'aceptada'], true)) {
                // Pagó una reserva que ya no esperaba pago (ej. cancelada): queda registrado para revisión manual.
                Log::warning("{$nombre}: pago aprobado {$pago->referencia} para la reserva {$reserva->codigo_reserva} en estado {$reserva->estado}");
                return;
            }

            $original = json_encode($reserva);
            $confirma = $reserva->estado === 'pendiente_pago';
            $cambios = [
                'valor_pagado' => round((float) $reserva->valor_pagado + $pago->monto_centavos / 100, 2),
                'usuario_modificacion_id' => 0,
                'usuario_modificacion_nombre' => "Pago {$nombre}",
                'updated_at' => now(),
            ];
            if ($confirma) {
                // Con el abono o con el pago total el cupo queda separado.
                self::descontarCupos($reserva);
                $cambios += ['estado' => 'aceptada', 'cupos_descontados' => true];
            }
            DB::table('reservas')->where('id', $reserva->id)->update($cambios);

            AuditoriaTabla::crear([
                'externo' => true,
                'id_recurso' => $reserva->id,
                'nombre_recurso' => Reserva::class,
                'descripcion_recurso' => $reserva->codigo_reserva,
                'accion' => 'Modificar',
                'recurso_original' => $original,
                'recurso_resultante' => json_encode(DB::table('reservas')->where('id', $reserva->id)->first()),
            ]);

            if ($confirma) {
                Reserva::registrarHistorial($reserva->id, $reserva->estado, 'aceptada', "Pago {$nombre}");
            }

            $estadoReservaAnterior = $reserva->estado;
            $confirmada = $confirma;
            $reservaId = $reserva->id;
        });

        if ($reservaId) {
            CobroProveedores::sincronizarComision($reservaId);
            $confirmada
                ? NotificadorReservas::estadoCambiado($reservaId, $estadoReservaAnterior)
                : NotificadorReservas::pagoRecibido($reservaId);
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

    // ---------------------- Pagos manuales -------------------------- //

    /**
     * Pagos de la reserva, del más antiguo al más reciente: los aprobados (en línea o manuales) y los
     * manuales anulados. Al viajero ($publico) solo le llegan los aprobados, sin nota ni responsables.
     */
    public static function pagosDeReserva($reservaId, $publico = false)
    {
        return DB::table('pagos')
            ->where('reserva_id', $reservaId)
            ->where(function ($q) use ($publico) {
                $q->where('estado', 'aprobado');
                if (!$publico) {
                    $q->orWhere(fn ($anulados) => $anulados->where('pasarela', self::MANUAL)->where('estado', 'anulado'));
                }
            })
            ->orderBy('id')
            ->get()
            ->map(function ($pago) use ($publico) {
                $fila = [
                    'fecha' => $pago->fecha_pago ?: substr((string) $pago->updated_at, 0, 10),
                    'valor' => round($pago->monto_centavos / 100, 2),
                    'concepto' => $pago->concepto,
                    'pasarela' => $pago->pasarela,
                    'metodo_pago' => $pago->metodo_pago,
                ];
                return $publico ? $fila : $fila + [
                    'id' => $pago->id,
                    'estado' => $pago->estado,
                    'nota' => $pago->nota,
                    'registrado_por' => $pago->registrado_por,
                    'anulado_por' => $pago->anulado_por,
                ];
            });
    }

    /**
     * Registra un pago recibido por fuera del portal. No puede superar el saldo. Una reserva que esperaba el
     * pago en línea (pendiente_pago) queda aceptada, igual que si hubiera pagado en el portal.
     * Devuelve el estado que tenía la reserva, para saber si cambió.
     */
    public static function registrarPagoManual($reservaId, array $datos, $responsable)
    {
        $reserva = DB::table('reservas')->where('id', $reservaId)->lockForUpdate()->first();
        if (!in_array($reserva->estado, self::ESTADOS_CON_PAGO_MANUAL, true)) {
            throw new \Exception('No se pueden registrar pagos en una reserva rechazada o cancelada.');
        }
        $saldo = round((float) $reserva->valor_total - (float) $reserva->valor_pagado, 2);
        $valor = round((float) $datos['valor'], 2);
        if ($valor > $saldo) {
            throw new \Exception('El pago supera el saldo de la reserva ($ ' . number_format($saldo, 0, ',', '.') . ').');
        }

        DB::table('pagos')->insert([
            'reserva_id' => $reserva->id,
            'pasarela' => self::MANUAL,
            'concepto' => $valor < $saldo ? 'abono' : ((float) $reserva->valor_pagado > 0 ? 'saldo' : 'total'),
            'referencia' => $reserva->codigo_reserva . '-M' . strtoupper(Str::random(6)),
            'monto_centavos' => (int) round($valor * 100),
            'moneda' => 'COP',
            'estado' => 'aprobado',
            'metodo_pago' => $datos['metodo_pago'],
            'fecha_pago' => $datos['fecha_pago'],
            'nota' => $datos['nota'] ?? null,
            'registrado_por' => $responsable,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        self::recalcularPagado($reserva, $responsable);

        if ($reserva->estado === 'pendiente_pago') {
            Reserva::cambiarEstado($reserva->id, 'aceptada');
        }

        return $reserva->estado;
    }

    // Anula un pago manual mal registrado: deja de contar en lo pagado y queda el rastro de quién lo anuló.
    public static function anularPagoManual($reservaId, $pagoId, $responsable)
    {
        $reserva = DB::table('reservas')->where('id', $reservaId)->lockForUpdate()->first();
        $pago = DB::table('pagos')->where('id', $pagoId)->where('reserva_id', $reservaId)->lockForUpdate()->first();
        if (!$pago || $pago->pasarela !== self::MANUAL) {
            throw new \Exception('Solo se pueden anular los pagos registrados a mano en esta reserva.');
        }
        if ($pago->estado !== 'aprobado') {
            throw new \Exception('Ese pago ya está anulado.');
        }
        if ($pago->concepto !== self::REEMBOLSO
            && round((float) $reserva->valor_pagado - $pago->monto_centavos / 100, 2) < (float) $reserva->valor_reembolsado) {
            throw new \Exception('No se puede anular: lo pagado quedaría por debajo de lo ya reembolsado. Anula primero el reembolso.');
        }

        DB::table('pagos')->where('id', $pago->id)->update([
            'estado' => 'anulado',
            'anulado_por' => $responsable,
            'anulado_en' => now(),
            'updated_at' => now(),
        ]);
        self::recalcularPagado($reserva, $responsable);
    }

    /**
     * Reembolso manual: el administrador ya devolvió el dinero por fuera del portal (transferencia, efectivo…)
     * y lo deja registrado. No puede superar lo pagado menos lo ya reembolsado. No cambia el estado ni los
     * cupos de la reserva (eso lo hace la cancelación). Se anula con anularPagoManual() como cualquier pago manual.
     */
    public static function registrarReembolso($reservaId, array $datos, $responsable)
    {
        $reserva = DB::table('reservas')->where('id', $reservaId)->lockForUpdate()->first();
        $disponible = round((float) $reserva->valor_pagado - (float) $reserva->valor_reembolsado, 2);
        $valor = round((float) $datos['valor'], 2);
        if ($disponible <= 0) {
            throw new \Exception('Esta reserva no tiene pagos por reembolsar.');
        }
        if ($valor > $disponible) {
            throw new \Exception('El reembolso supera lo pagado por el viajero ($ ' . number_format($disponible, 0, ',', '.') . ').');
        }

        DB::table('pagos')->insert([
            'reserva_id' => $reserva->id,
            'pasarela' => self::MANUAL,
            'concepto' => self::REEMBOLSO,
            'referencia' => $reserva->codigo_reserva . '-R' . strtoupper(Str::random(6)),
            'monto_centavos' => (int) round($valor * 100),
            'moneda' => 'COP',
            'estado' => 'aprobado',
            'metodo_pago' => $datos['metodo_pago'],
            'fecha_pago' => $datos['fecha_pago'],
            'nota' => $datos['nota'] ?? null,
            'registrado_por' => $responsable,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        self::recalcularPagado($reserva, $responsable);
    }

    public static function esReembolso($reservaId, $pagoId)
    {
        return DB::table('pagos')->where('id', $pagoId)->where('reserva_id', $reservaId)->value('concepto') === self::REEMBOLSO;
    }

    // reservas.valor_pagado = suma de los pagos aprobados (en línea y manuales); valor_reembolsado = suma de los reembolsos.
    private static function recalcularPagado($reserva, $responsable)
    {
        $original = json_encode($reserva);
        $aprobados = DB::table('pagos')->where('reserva_id', $reserva->id)->where('estado', 'aprobado');
        $centavos = (clone $aprobados)->where('concepto', '<>', self::REEMBOLSO)->sum('monto_centavos');
        $reembolsados = (clone $aprobados)->where('concepto', self::REEMBOLSO)->sum('monto_centavos');
        DB::table('reservas')->where('id', $reserva->id)->update([
            'valor_pagado' => round($centavos / 100, 2),
            'valor_reembolsado' => round($reembolsados / 100, 2),
            'updated_at' => now(),
        ]);

        AuditoriaTabla::crear([
            'id_recurso' => $reserva->id,
            'nombre_recurso' => Reserva::class,
            'descripcion_recurso' => $reserva->codigo_reserva . ' (pago manual por ' . $responsable . ')',
            'accion' => 'Modificar',
            'recurso_original' => $original,
            'recurso_resultante' => json_encode(DB::table('reservas')->where('id', $reserva->id)->first()),
        ]);
        // Lo pagado y lo reembolsado cambian la base de la comisión del proveedor.
        CobroProveedores::sincronizarComision($reserva->id);
    }

    /**
     * Toma los cupos de la fecha al aceptar una reserva (modalidad solicitud o confirmación manual).
     * A diferencia del pago en línea, aquí aún se puede decir que no: sin cupos suficientes no se acepta.
     */
    public static function tomarCupos($reservaId)
    {
        $reserva = DB::table('reservas')->where('id', $reservaId)->lockForUpdate()->first();
        if (!$reserva || $reserva->cupos_descontados || !$reserva->disponibilidad_id) {
            return;
        }
        $disponibilidad = DB::table('experiencia_disponibilidad')->where('id', $reserva->disponibilidad_id)->lockForUpdate()->first();
        if (!$disponibilidad) {
            return;
        }
        if ($disponibilidad->cupos_disponibles < $reserva->cantidad_personas) {
            throw new \Exception(
                "No hay cupos suficientes para aceptar la reserva: quedan {$disponibilidad->cupos_disponibles} " .
                "y la reserva es de {$reserva->cantidad_personas} persona(s). Amplía los cupos de la fecha o rechaza la reserva."
            );
        }
        DB::table('experiencia_disponibilidad')->where('id', $disponibilidad->id)->update([
            'cupos_disponibles' => $disponibilidad->cupos_disponibles - $reserva->cantidad_personas,
            'updated_at' => now(),
        ]);
        DB::table('reservas')->where('id', $reserva->id)->update(['cupos_descontados' => true]);
    }

    // Devuelve a la fecha los cupos que la reserva había tomado (al cancelarse, rechazarse o eliminarse).
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
