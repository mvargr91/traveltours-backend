<?php

namespace App\Services;

use Throwable;
use Carbon\Carbon;
use App\Mail\ControlProveedoresMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Models\Reservas\Reserva;
use App\Models\Parametrizacion\ParametroConstante;

/**
 * Control de los proveedores para la administración (Finanzas > Control de proveedores):
 *  - finalizarVencidas(): tarea diaria. Las reservas aceptadas cuyo tour ya terminó pasan solas a "finalizada"
 *    (genera la comisión y el correo de gracias al viajero), salvo que el proveedor las cierre antes.
 *  - datos(): una fila por proveedor con lo que hay que vigilar y sus alertas.
 *  - enviarResumenSemanal(): los lunes, el resumen por correo a la administración.
 */
class ControlProveedores
{
    // codigo => [valor por defecto, descripción] (Parametrización > Parámetros).
    public const PARAMETROS = [
        'RESERVAS_DIAS_AUTOFINALIZAR' => ['1', 'Días después del tour para finalizar solas las reservas aceptadas (genera la comisión). 0 = no finalizar solas.'],
        'CONTROL_TASA_CANCELACION_ALERTA' => ['30', 'Porcentaje de reservas canceladas o rechazadas (últimos 90 días) desde el que se alerta un proveedor.'],
        'CONTROL_RESUMEN_SEMANAL' => ['SI', 'SI/NO: enviar los lunes a la administración el resumen del control de proveedores.'],
    ];

    public const DIAS_VENTANA = 90;
    // Con menos reservas que esto la tasa de cancelación no se alerta (muy pocas para sacar conclusiones).
    private const MINIMO_RESERVAS_TASA = 5;
    public const RESPONSABLE_CIERRE = 'Sistema (cierre automático)';
    // El cierre automático envía el correo de gracias solo si el tour terminó hace a lo sumo estos días.
    private const DIAS_CORREO_CIERRE = 7;

    public static function parametro($codigo)
    {
        $valor = ParametroConstante::cargarParametros()[$codigo] ?? null;
        return ($valor === null || $valor === '') ? self::PARAMETROS[$codigo][0] : $valor;
    }

    // Último día del tour: la fecha de regreso de la salida o la fecha + duración en días.
    private static function sqlFin(): string
    {
        return "COALESCE(CASE WHEN d.fecha_fin > r.fecha THEN d.fecha_fin END, "
            . "DATE_ADD(r.fecha, INTERVAL GREATEST(COALESCE(e.duracion_dias, 1), 1) - 1 DAY))";
    }

    private static function reservasConFin()
    {
        return DB::table('reservas as r')
            ->join('experiencias as e', 'e.id', '=', 'r.experiencia_id')
            ->leftJoin('experiencia_disponibilidad as d', 'd.id', '=', 'r.disponibilidad_id');
    }

    // ---------------------- Cierre automático -------------------------- //

    /**
     * Finaliza las reservas aceptadas cuyo tour terminó hace RESERVAS_DIAS_AUTOFINALIZAR días o más.
     * Devuelve cuántas finalizó. Cada una va en su transacción: un error no detiene las demás.
     */
    public static function finalizarVencidas(): int
    {
        $dias = (int) self::parametro('RESERVAS_DIAS_AUTOFINALIZAR');
        if ($dias <= 0) {
            return 0;
        }
        $limite = Carbon::today()->subDays($dias)->toDateString();
        // El correo de gracias solo para tours recientes: la primera vez se cierran también reservas viejas
        // que quedaron abiertas, y un "gracias" por un tour de hace meses confunde al viajero.
        $conCorreoDesde = Carbon::today()->subDays(self::DIAS_CORREO_CIERRE)->toDateString();
        $reservas = self::reservasConFin()
            ->where('r.estado', 'aceptada')
            ->whereRaw(self::sqlFin() . ' <= ?', [$limite])
            ->selectRaw('r.id, ' . self::sqlFin() . ' as fin')
            ->get();

        $finalizadas = 0;
        foreach ($reservas as $reserva) {
            try {
                DB::transaction(fn () => Reserva::cambiarEstado($reserva->id, 'finalizada', self::RESPONSABLE_CIERRE));
                if ($reserva->fin >= $conCorreoDesde) {
                    NotificadorReservas::estadoCambiado($reserva->id, 'aceptada');
                }
                $finalizadas++;
            } catch (Throwable $e) {
                Log::warning("Cierre automático: no se pudo finalizar la reserva {$reserva->id}: {$e->getMessage()}");
            }
        }
        return $finalizadas;
    }

    // ---------------------- Datos del control -------------------------- //

    public static function datos(): array
    {
        $hoy = Carbon::today()->toDateString();
        $desde = Carbon::today()->subDays(self::DIAS_VENTANA)->toDateString();
        $umbral = (float) self::parametro('CONTROL_TASA_CANCELACION_ALERTA');
        $diasCierre = (int) self::parametro('RESERVAS_DIAS_AUTOFINALIZAR');
        // Aceptadas que ya debieron cerrarse: pasado el plazo del cierre automático (o el día del tour si está apagado).
        $limiteCierre = Carbon::today()->subDays(max(1, $diasCierre))->toDateString();
        $porProveedor = fn ($query) => $query->groupBy('r.proveedor_id')->pluck('n', 'r.proveedor_id');

        $sinCerrar = $porProveedor(self::reservasConFin()->where('r.estado', 'aceptada')
            ->whereRaw(self::sqlFin() . ' < ?', [$limiteCierre])->selectRaw('r.proveedor_id, COUNT(*) as n'));
        $sinResponder = $porProveedor(DB::table('reservas as r')->where('r.estado', 'pendiente')->where('r.fecha', '<', $hoy)
            ->selectRaw('r.proveedor_id, COUNT(*) as n'));

        $ventana = DB::table('reservas as r')->whereBetween('r.fecha', [$desde, $hoy])
            ->groupBy('r.proveedor_id')
            ->selectRaw("r.proveedor_id,
                COUNT(*) as total,
                SUM(r.estado = 'cancelada') as canceladas,
                SUM(r.estado = 'rechazada') as rechazadas,
                SUM(r.estado = 'finalizada') as finalizadas,
                SUM(r.estado = 'no_asistio') as no_asistio,
                SUM(CASE WHEN r.estado IN ('finalizada', 'no_asistio') THEN r.valor_total - r.valor_reembolsado ELSE 0 END) as ventas")
            ->get()->keyBy('proveedor_id');

        // Cancelada el mismo día del tour o después: la señal más clara de una venta que no se quiere reportar.
        $tardias = $porProveedor(DB::table('reservas as r')
            ->where('r.estado', 'cancelada')
            ->whereBetween('r.fecha', [$desde, $hoy])
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('reserva_historial as h')
                ->whereColumn('h.reserva_id', 'r.id')->where('h.estado', 'cancelada')
                ->whereRaw('DATE(h.created_at) >= r.fecha'))
            ->selectRaw('r.proveedor_id, COUNT(*) as n'));

        $liquidaciones = DB::table('liquidaciones')->where('estado', 'emitida')->groupBy('proveedor_id')
            ->selectRaw('proveedor_id, SUM(total - valor_pagado) as saldo, SUM(fecha_vencimiento < ?) as vencidas', [$hoy])
            ->get()->keyBy('proveedor_id');
        $comisiones = DB::table('comisiones')
            ->leftJoin('liquidaciones', 'liquidaciones.id', '=', 'comisiones.liquidacion_id')
            ->where(fn ($q) => $q->whereNull('comisiones.liquidacion_id')->orWhere('liquidaciones.estado', 'borrador'))
            ->groupBy('comisiones.proveedor_id')
            ->selectRaw('comisiones.proveedor_id, SUM(comisiones.valor) as n')
            ->pluck('n', 'comisiones.proveedor_id');
        $pasarelas = DB::table('cuentas_pasarela')->pluck('estado', 'proveedor_id');
        $periodo = Carbon::today()->format('Y-m');

        $filas = DB::table('proveedores_turisticos')
            ->where('es_plataforma', false)
            ->orderBy('nombre_comercial')
            ->get(['id', 'nombre_comercial', 'estado', 'estado_verificacion', 'suspendido_por_mora'])
            ->map(function ($p) use ($sinCerrar, $sinResponder, $ventana, $tardias, $liquidaciones, $comisiones, $pasarelas, $periodo, $umbral) {
                $v = $ventana[$p->id] ?? null;
                $total = (int) ($v->total ?? 0);
                $canceladas = (int) ($v->canceladas ?? 0) + (int) ($v->rechazadas ?? 0);
                $tasa = $total ? round($canceladas * 100 / $total, 1) : 0.0;
                $modelo = CobroProveedores::modeloVigente($p->id, $periodo);
                $fila = [
                    'id' => $p->id,
                    'proveedor_nombre' => $p->nombre_comercial,
                    'activo' => (bool) $p->estado,
                    'estado_verificacion' => $p->estado_verificacion,
                    'reservas_sin_cerrar' => (int) ($sinCerrar[$p->id] ?? 0),
                    'reservas_sin_responder' => (int) ($sinResponder[$p->id] ?? 0),
                    'reservas_periodo' => $total,
                    'finalizadas' => (int) ($v->finalizadas ?? 0) + (int) ($v->no_asistio ?? 0),
                    'canceladas' => $canceladas,
                    'tasa_cancelacion' => $tasa,
                    'canceladas_tarde' => (int) ($tardias[$p->id] ?? 0),
                    'ventas_periodo' => round((float) ($v->ventas ?? 0), 2),
                    'comisiones_pendientes' => round((float) ($comisiones[$p->id] ?? 0), 2),
                    'saldo_por_pagar' => round((float) ($liquidaciones[$p->id]->saldo ?? 0), 2),
                    'liquidaciones_vencidas' => (int) ($liquidaciones[$p->id]->vencidas ?? 0),
                    'suspendido_por_mora' => (bool) $p->suspendido_por_mora,
                    'pasarela' => $pasarelas[$p->id] ?? 'sin_pasarela',
                    'modelo' => $modelo->tipo ?? null,
                    'modelo_nombre' => $modelo ? (CobroProveedores::TIPOS[$modelo->tipo] ?? $modelo->tipo) : 'Sin modelo',
                ];
                return $fila + self::alertas($fila, $umbral);
            });

        // Primero los que requieren atención.
        $orden = ['alto' => 0, 'medio' => 1, 'ok' => 2];
        $filas = $filas->sortBy(fn ($f) => [$orden[$f['nivel']], -count($f['alertas']), $f['proveedor_nombre']])->values();

        return [
            'resumen' => [
                'proveedores' => $filas->count(),
                'con_alertas' => $filas->where('nivel', '!=', 'ok')->count(),
                'reservas_sin_cerrar' => $filas->sum('reservas_sin_cerrar'),
                'reservas_sin_responder' => $filas->sum('reservas_sin_responder'),
                'canceladas_tarde' => $filas->sum('canceladas_tarde'),
                'saldo_por_pagar' => round($filas->sum('saldo_por_pagar'), 2),
                'liquidaciones_vencidas' => $filas->sum('liquidaciones_vencidas'),
                'en_mora' => $filas->where('suspendido_por_mora', true)->count(),
                'sin_pasarela' => $filas->where('pasarela', '!=', 'activa')->count(),
                'sin_modelo' => $filas->whereNull('modelo')->where('estado_verificacion', 'aprobado')->count(),
            ],
            'proveedores' => $filas->all(),
            'parametros' => [
                'dias_ventana' => self::DIAS_VENTANA,
                'dias_autofinalizar' => (int) self::parametro('RESERVAS_DIAS_AUTOFINALIZAR'),
                'tasa_cancelacion_alerta' => $umbral,
            ],
        ];
    }

    /**
     * Alertas de un proveedor: [nivel => alto|medio|ok, alertas => [{nivel, texto}]].
     *  - alto: deuda vencida o en mora, cancelaciones el día del tour o después, reservas que debieron cerrarse.
     *  - medio: tasa de cancelación alta, solicitudes sin responder, aprobado sin modelo de cobro.
     * No tener pasarela no es una alerta: el proveedor puede trabajar solo con reservas.
     */
    private static function alertas(array $f, float $umbral): array
    {
        $alertas = [];
        $agregar = function ($nivel, $texto) use (&$alertas) {
            $alertas[] = ['nivel' => $nivel, 'texto' => $texto];
        };

        if ($f['suspendido_por_mora']) {
            $agregar('alto', 'Suspendido por mora: sus experiencias no se muestran');
        } elseif ($f['liquidaciones_vencidas']) {
            $agregar('alto', "{$f['liquidaciones_vencidas']} liquidación(es) vencida(s)");
        }
        if ($f['canceladas_tarde']) {
            $agregar('alto', "{$f['canceladas_tarde']} reserva(s) cancelada(s) el día del tour o después");
        }
        if ($f['reservas_sin_cerrar']) {
            $agregar('alto', "{$f['reservas_sin_cerrar']} reserva(s) aceptada(s) con el tour ya pasado sin finalizar");
        }
        if ($f['reservas_periodo'] >= self::MINIMO_RESERVAS_TASA && $f['tasa_cancelacion'] >= $umbral) {
            $agregar('medio', "Cancela o rechaza el {$f['tasa_cancelacion']} % de sus reservas");
        }
        if ($f['reservas_sin_responder']) {
            $agregar('medio', "{$f['reservas_sin_responder']} solicitud(es) sin responder con la fecha ya pasada");
        }
        if (!$f['modelo'] && $f['estado_verificacion'] === 'aprobado') {
            $agregar('medio', 'Aprobado sin modelo de cobro: no se le cobra nada');
        }

        $niveles = array_column($alertas, 'nivel');
        $nivel = in_array('alto', $niveles, true) ? 'alto' : (in_array('medio', $niveles, true) ? 'medio' : 'ok');
        return ['nivel' => $nivel, 'alertas' => $alertas];
    }

    // ---------------------- Resumen semanal -------------------------- //

    // Lo envía a la administración si CONTROL_RESUMEN_SEMANAL = SI. Devuelve a cuántos correos se envió.
    public static function enviarResumenSemanal(): int
    {
        if (strtoupper(trim((string) self::parametro('CONTROL_RESUMEN_SEMANAL'))) !== 'SI') {
            return 0;
        }
        $lista = trim(ParametroConstante::cargarParametros()['CORREO_ADMIN_RESERVAS'] ?? '') ?: config('mail.from.address');
        $destinos = array_filter(array_map('trim', explode(',', (string) $lista)), fn ($c) => filter_var($c, FILTER_VALIDATE_EMAIL));
        if (!$destinos) {
            return 0;
        }

        $datos = self::datos();
        $enviados = 0;
        foreach ($destinos as $correo) {
            try {
                Mail::to($correo)->send(new ControlProveedoresMail($datos, config('app.front_url') . '/control-proveedores'));
                $enviados++;
            } catch (Throwable $e) {
                Log::warning("No se pudo enviar el resumen del control de proveedores a {$correo}: {$e->getMessage()}");
            }
        }
        return $enviados;
    }
}
