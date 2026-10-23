<?php

namespace App\Services;

use Exception;
use Throwable;
use Carbon\Carbon;
use App\Mail\LiquidacionMail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Models\Finanzas\CuentaPasarela;
use App\Models\Seguridad\AuditoriaTabla;
use App\Models\Parametrizacion\ParametroConstante;

use function Illuminate\Support\defer;

/**
 * Cobro de la plataforma a los proveedores (menú Finanzas).
 *
 * Modelo de cobro (modelos_cobro_proveedor), con historial por mes: el que aplica a un mes es el de mayor
 * vigente_desde <= ese mes. Tipos:
 *  - suscripcion: valor fijo mensual.
 *  - comision: porcentaje de lo vendido.
 *  - mixto: valor fijo + porcentaje.
 * Los valores van sin IVA; la liquidación le suma COBRO_PORCENTAJE_IVA. El proveedor de la plataforma no tiene modelo.
 *
 * Comisiones: cada reserva finalizada (o "no asistió" con pago) genera una fila en `comisiones` con el porcentaje
 * del modelo del mes de la experiencia. Base: finalizada = valor_total − reembolsado; no asistió = pagado − reembolsado.
 * sincronizarComision() es idempotente y se llama cada vez que cambia el estado, lo pagado o lo reembolsado:
 * lo que no está en una liquidación cerrada (emitida, pagada o anulada) se recalcula; lo cerrado no se toca y la
 * diferencia queda como "ajuste" para la siguiente liquidación.
 *
 * Liquidaciones: una por proveedor y mes (YYYY-MM). generar() crea o recalcula el borrador con la suscripción del
 * mes y todas las comisiones pendientes hasta ese mes; emitir() la envía al proveedor con su fecha de vencimiento.
 * El proveedor la paga en línea con la pasarela de la plataforma o el administrador registra el pago a mano.
 * revisarMora(): avisa las vencidas y, pasados COBRO_DIAS_GRACIA_SUSPENSION días, oculta las experiencias del
 * proveedor del portal (suspendido_por_mora) hasta que pague.
 */
class CobroProveedores
{
    public const TIPOS = ['suscripcion' => 'Suscripción mensual', 'comision' => 'Comisión por venta', 'mixto' => 'Suscripción + comisión'];
    public const ESTADOS_LIQUIDACION = ['borrador' => 'Borrador', 'emitida' => 'Emitida', 'pagada' => 'Pagada', 'anulada' => 'Anulada'];
    // Liquidaciones que ya no se recalculan: sus comisiones quedan fijas.
    private const CERRADAS = ['emitida', 'pagada', 'anulada'];
    private const ESTADOS_CON_COMISION = ['finalizada', 'no_asistio'];
    private const PREFIJO = 'LIQ-';
    public const MANUAL = 'manual';
    public const MEDIOS_MANUALES = ['transferencia', 'efectivo', 'consignacion', 'otro'];

    // codigo => [valor por defecto, descripción] (Parametrización > Parámetros).
    public const PARAMETROS = [
        'COBRO_MODELO_DEFECTO' => ['comision', 'Modelo de cobro que recibe un proveedor al aprobarse: suscripcion | comision | mixto.'],
        'COBRO_PORCENTAJE_DEFECTO' => ['10', 'Porcentaje de comisión por defecto (sin IVA).'],
        'COBRO_SUSCRIPCION_DEFECTO' => ['0', 'Valor mensual de la suscripción por defecto (sin IVA).'],
        'COBRO_PORCENTAJE_IVA' => ['19', 'IVA que se suma a las liquidaciones de los proveedores (0 = no se cobra IVA).'],
        'COBRO_DIAS_PLAZO_PAGO' => ['10', 'Días que tiene el proveedor para pagar una liquidación desde que se emite.'],
        'COBRO_DIAS_GRACIA_SUSPENSION' => ['15', 'Días después del vencimiento para ocultar las experiencias del proveedor (0 = nunca).'],
        'COBRO_EMITIR_AUTOMATICO' => ['NO', 'SI/NO: emitir las liquidaciones del mes anterior el día 1 sin revisión del administrador.'],
    ];

    public const VARIABLES = '{proveedor} {codigo} {periodo} {total} {pagado} {saldo} {vencimiento}';

    // nombre => [asunto, texto HTML, título del correo]
    public const PLANTILLAS = [
        'LIQUIDACION_EMITIDA' => [
            'Tu liquidación {codigo} de {periodo}',
            '<p>Ya está disponible la liquidación de <strong>{periodo}</strong> por <strong>{total}</strong>.</p><p>Puedes revisarla y pagarla en línea desde el panel (Finanzas &gt; Liquidaciones) hasta el {vencimiento}.</p>',
            'Hola, {proveedor}',
        ],
        'LIQUIDACION_VENCIDA' => [
            'Tu liquidación {codigo} está vencida',
            '<p>La liquidación de <strong>{periodo}</strong> venció el {vencimiento} y tiene un saldo de <strong>{saldo}</strong>.</p><p>Págala desde el panel para que tus experiencias sigan visibles en el portal.</p>',
            'Hola, {proveedor}',
        ],
        'LIQUIDACION_PAGADA' => [
            'Recibimos el pago de la liquidación {codigo}',
            '<p>Recibimos el pago de la liquidación de <strong>{periodo}</strong> por <strong>{pagado}</strong>. ¡Gracias!</p>',
            'Hola, {proveedor}',
        ],
        'PROVEEDOR_SUSPENDIDO_MORA' => [
            'Tus experiencias se ocultaron del portal',
            '<p>La liquidación {codigo} de {periodo} sigue sin pagar (saldo <strong>{saldo}</strong>).</p><p>Tus experiencias dejaron de mostrarse en el portal; vuelven a publicarse en cuanto se registre el pago.</p>',
            'Hola, {proveedor}',
        ],
    ];

    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    // ---------------------- Utilidades -------------------------- //

    public static function parametro($codigo)
    {
        $valor = ParametroConstante::cargarParametros()[$codigo] ?? null;
        return ($valor === null || $valor === '') ? self::PARAMETROS[$codigo][0] : $valor;
    }

    public static function nombrePeriodo($periodo)
    {
        [$anio, $mes] = explode('-', $periodo);
        return self::MESES[(int) $mes - 1] . ' de ' . $anio;
    }

    public static function periodoAnterior(?Carbon $fecha = null)
    {
        return ($fecha ?? now())->copy()->startOfMonth()->subMonth()->format('Y-m');
    }

    private static function pesos($valor)
    {
        return '$ ' . number_format((float) $valor, 0, ',', '.');
    }

    private static function esPlataforma($proveedorId)
    {
        return (bool) DB::table('proveedores_turisticos')->where('id', $proveedorId)->value('es_plataforma');
    }

    // ---------------------- Modelo de cobro -------------------------- //

    // Modelo que aplica al proveedor en el mes (YYYY-MM), o null si no tiene.
    public static function modeloVigente($proveedorId, $periodo)
    {
        return DB::table('modelos_cobro_proveedor')
            ->where('proveedor_id', $proveedorId)
            ->where('vigente_desde', '<=', $periodo . '-01')
            ->orderByDesc('vigente_desde')
            ->first();
    }

    public static function historialModelos($proveedorId)
    {
        return DB::table('modelos_cobro_proveedor')
            ->where('proveedor_id', $proveedorId)
            ->orderByDesc('vigente_desde')
            ->get()
            ->map(fn ($m) => self::formatearModelo($m));
    }

    public static function formatearModelo($modelo)
    {
        if (!$modelo) {
            return null;
        }
        return [
            'id' => $modelo->id,
            'tipo' => $modelo->tipo,
            'tipo_nombre' => self::TIPOS[$modelo->tipo] ?? $modelo->tipo,
            'valor_mensual' => (float) $modelo->valor_mensual,
            'porcentaje_comision' => (float) $modelo->porcentaje_comision,
            'desde' => substr($modelo->vigente_desde, 0, 7),
            'desde_nombre' => self::nombrePeriodo(substr($modelo->vigente_desde, 0, 7)),
            'observaciones' => $modelo->observaciones,
            'usuario_modificacion_nombre' => $modelo->usuario_modificacion_nombre,
            'fecha_modificacion' => Carbon::parse($modelo->updated_at)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Guarda el modelo desde el mes indicado (YYYY-MM). No se puede cambiar un mes ya liquidado (emitido o pagado).
     * Recalcula las comisiones pendientes de ese mes en adelante con el nuevo porcentaje.
     */
    public static function guardarModelo(array $dto, $usuario)
    {
        $proveedorId = (int) $dto['proveedor_id'];
        if (self::esPlataforma($proveedorId)) {
            throw new Exception('El proveedor de la plataforma no tiene modelo de cobro.');
        }
        $desde = $dto['desde'];
        $liquidado = DB::table('liquidaciones')
            ->where('proveedor_id', $proveedorId)
            ->where('periodo', '>=', $desde)
            ->whereIn('estado', self::CERRADAS)
            ->exists();
        if ($liquidado) {
            throw new Exception('Ya hay liquidaciones emitidas desde ' . self::nombrePeriodo($desde) . ': el cambio debe aplicar desde un mes posterior.');
        }

        $tipo = $dto['tipo'];
        $valores = [
            'tipo' => $tipo,
            'valor_mensual' => $tipo === 'comision' ? 0 : round((float) ($dto['valor_mensual'] ?? 0), 2),
            'porcentaje_comision' => $tipo === 'suscripcion' ? 0 : round((float) ($dto['porcentaje_comision'] ?? 0), 2),
            'observaciones' => $dto['observaciones'] ?? null,
            'usuario_modificacion_id' => $usuario->id ?? 0,
            'usuario_modificacion_nombre' => $usuario->nombre ?? 'Sistema',
            'updated_at' => now(),
        ];
        $clave = ['proveedor_id' => $proveedorId, 'vigente_desde' => $desde . '-01'];
        $original = DB::table('modelos_cobro_proveedor')->where($clave)->first();
        if ($original) {
            DB::table('modelos_cobro_proveedor')->where('id', $original->id)->update($valores);
        } else {
            DB::table('modelos_cobro_proveedor')->insert($clave + $valores + [
                'usuario_creacion_id' => $usuario->id ?? 0,
                'usuario_creacion_nombre' => $usuario->nombre ?? 'Sistema',
                'created_at' => now(),
            ]);
        }
        $guardado = DB::table('modelos_cobro_proveedor')->where($clave)->first();

        AuditoriaTabla::crear([
            'id_recurso' => $guardado->id,
            'nombre_recurso' => 'App\\Models\\Finanzas\\ModeloCobro',
            'descripcion_recurso' => "Modelo de cobro del proveedor {$proveedorId} desde {$desde}",
            'accion' => $original ? 'Modificar' : 'Crear',
            'recurso_original' => json_encode($original ?? $guardado),
            'recurso_resultante' => $original ? json_encode($guardado) : null,
        ]);

        self::recalcularComisionesDesde($proveedorId, $desde);
        return self::formatearModelo($guardado);
    }

    // Borra un cambio programado o un mes aún no liquidado (vuelve a aplicar el modelo anterior).
    public static function eliminarModelo($modeloId)
    {
        $modelo = DB::table('modelos_cobro_proveedor')->where('id', $modeloId)->first();
        $desde = substr($modelo->vigente_desde, 0, 7);
        $liquidado = DB::table('liquidaciones')
            ->where('proveedor_id', $modelo->proveedor_id)
            ->where('periodo', '>=', $desde)
            ->whereIn('estado', self::CERRADAS)
            ->exists();
        if ($liquidado) {
            throw new Exception('Este modelo ya se usó en liquidaciones emitidas: no se puede eliminar.');
        }
        AuditoriaTabla::crear([
            'id_recurso' => $modelo->id,
            'nombre_recurso' => 'App\\Models\\Finanzas\\ModeloCobro',
            'descripcion_recurso' => "Modelo de cobro del proveedor {$modelo->proveedor_id} desde {$desde}",
            'accion' => 'Eliminar',
            'recurso_original' => json_encode($modelo),
        ]);
        DB::table('modelos_cobro_proveedor')->where('id', $modeloId)->delete();
        self::recalcularComisionesDesde($modelo->proveedor_id, $desde);
    }

    // Al aprobar un proveedor sin modelo: el de los parámetros, desde este mes.
    public static function asignarModeloPorDefecto($proveedorId, $usuario = null)
    {
        if (self::esPlataforma($proveedorId) || DB::table('modelos_cobro_proveedor')->where('proveedor_id', $proveedorId)->exists()) {
            return;
        }
        $tipo = self::parametro('COBRO_MODELO_DEFECTO');
        if (!isset(self::TIPOS[$tipo])) {
            $tipo = 'comision';
        }
        self::guardarModelo([
            'proveedor_id' => $proveedorId,
            'tipo' => $tipo,
            'valor_mensual' => (float) self::parametro('COBRO_SUSCRIPCION_DEFECTO'),
            'porcentaje_comision' => (float) self::parametro('COBRO_PORCENTAJE_DEFECTO'),
            'desde' => now()->format('Y-m'),
            'observaciones' => 'Modelo por defecto asignado al aprobar el proveedor.',
        ], $usuario);
    }

    private static function recalcularComisionesDesde($proveedorId, $desde)
    {
        DB::table('reservas')
            ->where('proveedor_id', $proveedorId)
            ->where('fecha', '>=', $desde . '-01')
            ->whereIn('estado', self::ESTADOS_CON_COMISION)
            ->pluck('id')
            ->each(fn ($id) => self::sincronizarComision($id));
    }

    // ---------------------- Comisiones -------------------------- //

    /**
     * Deja la comisión de la reserva como debe ser hoy. $eliminar: la reserva se va a borrar (comisión esperada 0).
     */
    public static function sincronizarComision($reservaId, $eliminar = false)
    {
        $reserva = DB::table('reservas')
            ->join('proveedores_turisticos', 'proveedores_turisticos.id', '=', 'reservas.proveedor_id')
            ->where('reservas.id', $reservaId)
            ->select('reservas.*', 'proveedores_turisticos.es_plataforma')
            ->first();
        if (!$reserva || $reserva->es_plataforma) {
            return;
        }

        $periodo = substr($reserva->fecha, 0, 7);
        $base = 0.0;
        $porcentaje = 0.0;
        if (!$eliminar && in_array($reserva->estado, self::ESTADOS_CON_COMISION, true)) {
            $modelo = self::modeloVigente($reserva->proveedor_id, $periodo);
            if ($modelo && $modelo->tipo !== 'suscripcion') {
                $porcentaje = (float) $modelo->porcentaje_comision;
                $base = $reserva->estado === 'finalizada'
                    ? (float) $reserva->valor_total - (float) $reserva->valor_reembolsado
                    : (float) $reserva->valor_pagado - (float) $reserva->valor_reembolsado;
                $base = max(0, round($base, 2));
            }
        }
        $esperado = round($base * $porcentaje / 100);

        DB::transaction(function () use ($reserva, $periodo, $base, $porcentaje, $esperado) {
            $filas = DB::table('comisiones')
                ->leftJoin('liquidaciones', 'liquidaciones.id', '=', 'comisiones.liquidacion_id')
                ->where('comisiones.reserva_id', $reserva->id)
                ->lockForUpdate()
                ->select('comisiones.*', 'liquidaciones.estado as liquidacion_estado')
                ->get();
            $cerradas = $filas->filter(fn ($f) => in_array($f->liquidacion_estado, self::CERRADAS, true));
            $pendientes = $filas->reject(fn ($f) => in_array($f->liquidacion_estado, self::CERRADAS, true));
            $borradores = $pendientes->pluck('liquidacion_id')->filter()->unique();

            DB::table('comisiones')->whereIn('id', $pendientes->pluck('id'))->delete();

            $diferencia = round($esperado - (float) $cerradas->sum('valor'), 2);
            if (abs($diferencia) >= 1) {
                $ajuste = $cerradas->isNotEmpty();
                DB::table('comisiones')->insert([
                    'proveedor_id' => $reserva->proveedor_id,
                    'reserva_id' => $reserva->id,
                    'codigo_reserva' => $reserva->codigo_reserva,
                    'periodo' => $periodo,
                    'tipo' => $ajuste ? 'ajuste' : 'comision',
                    'base' => round($base - (float) $cerradas->sum('base'), 2),
                    'porcentaje' => $porcentaje ?: (float) ($cerradas->first()->porcentaje ?? 0),
                    'valor' => $diferencia,
                    'descripcion' => $ajuste
                        ? 'Ajuste: la reserva cambió después de liquidada (' . $reserva->estado . ')'
                        : ($reserva->estado === 'finalizada' ? 'Reserva finalizada' : 'No asistió (sobre lo pagado)'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($borradores as $liquidacionId) {
                self::recalcularBorrador($liquidacionId);
            }
        });
    }

    // ---------------------- Liquidaciones -------------------------- //

    /**
     * Crea o recalcula los borradores del mes para todos los proveedores (o uno). No toca las ya emitidas.
     * Devuelve cuántas liquidaciones quedaron en borrador.
     */
    public static function generar($periodo, $proveedorId = null, $usuario = null)
    {
        $proveedores = DB::table('proveedores_turisticos')
            ->where('es_plataforma', false)
            ->when($proveedorId, fn ($q, $v) => $q->where('id', $v))
            ->pluck('id');

        $total = 0;
        foreach ($proveedores as $id) {
            // Las comisiones de reservas que se finalizaron sin pasar por la app (ej. datos viejos) se ponen al día.
            DB::table('reservas')
                ->where('proveedor_id', $id)
                ->where('fecha', '<', Carbon::parse($periodo . '-01')->addMonth()->toDateString())
                ->whereIn('estado', self::ESTADOS_CON_COMISION)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('comisiones')->whereColumn('comisiones.reserva_id', 'reservas.id'))
                ->pluck('id')
                ->each(fn ($reservaId) => self::sincronizarComision($reservaId));

            $existente = DB::table('liquidaciones')->where('proveedor_id', $id)->where('periodo', $periodo)->first();
            if ($existente && $existente->estado !== 'borrador') {
                continue;
            }
            $liquidacionId = $existente->id ?? DB::table('liquidaciones')->insertGetId([
                'codigo' => self::PREFIJO . str_replace('-', '', $periodo) . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT),
                'proveedor_id' => $id,
                'periodo' => $periodo,
                'tipo_modelo' => 'comision',
                'estado' => 'borrador',
                'usuario_creacion_id' => $usuario->id ?? 0,
                'usuario_creacion_nombre' => $usuario->nombre ?? 'Sistema',
                'usuario_modificacion_id' => $usuario->id ?? 0,
                'usuario_modificacion_nombre' => $usuario->nombre ?? 'Sistema',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $liquidacion = self::recalcularBorrador($liquidacionId);
            // Sin nada que cobrar no queda liquidación.
            if ((float) $liquidacion->total <= 0) {
                DB::table('comisiones')->where('liquidacion_id', $liquidacionId)->update(['liquidacion_id' => null]);
                DB::table('liquidaciones')->where('id', $liquidacionId)->delete();
                continue;
            }
            $total++;
        }
        return $total;
    }

    // Vuelve a juntar las comisiones pendientes hasta el mes y recalcula los totales del borrador.
    private static function recalcularBorrador($liquidacionId)
    {
        $liquidacion = DB::table('liquidaciones')->where('id', $liquidacionId)->first();
        if (!$liquidacion || $liquidacion->estado !== 'borrador') {
            return $liquidacion;
        }
        DB::table('comisiones')->where('liquidacion_id', $liquidacionId)->update(['liquidacion_id' => null]);
        DB::table('comisiones')
            ->where('proveedor_id', $liquidacion->proveedor_id)
            ->whereNull('liquidacion_id')
            ->where('periodo', '<=', $liquidacion->periodo)
            ->update(['liquidacion_id' => $liquidacionId]);

        $modelo = self::modeloVigente($liquidacion->proveedor_id, $liquidacion->periodo);
        $suscripcion = $modelo && $modelo->tipo !== 'comision' ? (float) $modelo->valor_mensual : 0;
        $comisiones = DB::table('comisiones')->where('liquidacion_id', $liquidacionId);
        $totalComisiones = round((float) (clone $comisiones)->sum('valor'), 2);
        $totalVentas = round((float) (clone $comisiones)->sum('base'), 2);
        $subtotal = round($suscripcion + $totalComisiones, 2);
        $iva = (float) self::parametro('COBRO_PORCENTAJE_IVA');
        $valorIva = round($subtotal * $iva / 100);

        DB::table('liquidaciones')->where('id', $liquidacionId)->update([
            'tipo_modelo' => $modelo->tipo ?? 'comision',
            'porcentaje_comision' => $modelo && $modelo->tipo !== 'suscripcion' ? (float) $modelo->porcentaje_comision : 0,
            'valor_suscripcion' => $suscripcion,
            'total_ventas' => $totalVentas,
            'total_comisiones' => $totalComisiones,
            'subtotal' => $subtotal,
            'porcentaje_iva' => $iva,
            'valor_iva' => $valorIva,
            'total' => max(0, round($subtotal + $valorIva, 2)),
            'updated_at' => now(),
        ]);
        return DB::table('liquidaciones')->where('id', $liquidacionId)->first();
    }

    public static function recalcular($liquidacionId)
    {
        $liquidacion = DB::table('liquidaciones')->where('id', $liquidacionId)->first();
        if ($liquidacion->estado !== 'borrador') {
            throw new Exception('Solo se recalculan las liquidaciones en borrador.');
        }
        self::generar($liquidacion->periodo, $liquidacion->proveedor_id);
    }

    public static function emitir($liquidacionId, $usuario, $datos = [])
    {
        $liquidacion = DB::table('liquidaciones')->where('id', $liquidacionId)->lockForUpdate()->first();
        if ($liquidacion->estado !== 'borrador') {
            throw new Exception('Solo se emiten las liquidaciones en borrador.');
        }
        if ((float) $liquidacion->total <= 0) {
            throw new Exception('La liquidación no tiene valor a cobrar.');
        }
        $hoy = now()->startOfDay();
        $cambios = [
            'estado' => 'emitida',
            'fecha_emision' => $hoy->toDateString(),
            'fecha_vencimiento' => $hoy->copy()->addDays((int) self::parametro('COBRO_DIAS_PLAZO_PAGO'))->toDateString(),
            'factura_numero' => $datos['factura_numero'] ?? $liquidacion->factura_numero,
            'observaciones' => $datos['observaciones'] ?? $liquidacion->observaciones,
        ];
        self::actualizar($liquidacion, $cambios, $usuario);
        self::notificar('LIQUIDACION_EMITIDA', $liquidacionId);
    }

    // Emitida sin pagos: vuelve a borrador para corregirla (se recalcula al guardar o generar).
    public static function reabrir($liquidacionId, $usuario)
    {
        $liquidacion = DB::table('liquidaciones')->where('id', $liquidacionId)->lockForUpdate()->first();
        if ($liquidacion->estado !== 'emitida' || (float) $liquidacion->valor_pagado > 0) {
            throw new Exception('Solo se reabren las liquidaciones emitidas que no tienen pagos.');
        }
        self::actualizar($liquidacion, ['estado' => 'borrador', 'fecha_emision' => null, 'fecha_vencimiento' => null, 'recordatorio_enviado' => null], $usuario);
        self::recalcularBorrador($liquidacionId);
        self::revisarSuspension($liquidacion->proveedor_id);
    }

    // Condona la liquidación: no se cobra y sus comisiones no pasan a la siguiente.
    public static function anular($liquidacionId, $usuario, $motivo)
    {
        $liquidacion = DB::table('liquidaciones')->where('id', $liquidacionId)->lockForUpdate()->first();
        if (!in_array($liquidacion->estado, ['borrador', 'emitida'], true) || (float) $liquidacion->valor_pagado > 0) {
            throw new Exception('Solo se anulan las liquidaciones en borrador o emitidas que no tienen pagos.');
        }
        $observaciones = trim(($liquidacion->observaciones ? $liquidacion->observaciones . "\n" : '') . 'Anulada: ' . $motivo);
        self::actualizar($liquidacion, ['estado' => 'anulada', 'observaciones' => $observaciones], $usuario);
        self::revisarSuspension($liquidacion->proveedor_id);
    }

    public static function eliminarBorrador($liquidacionId)
    {
        $liquidacion = DB::table('liquidaciones')->where('id', $liquidacionId)->first();
        if ($liquidacion->estado !== 'borrador') {
            throw new Exception('Solo se eliminan las liquidaciones en borrador.');
        }
        DB::table('comisiones')->where('liquidacion_id', $liquidacionId)->update(['liquidacion_id' => null]);
        DB::table('liquidaciones')->where('id', $liquidacionId)->delete();
    }

    private static function actualizar($liquidacion, array $cambios, $usuario)
    {
        $cambios += [
            'usuario_modificacion_id' => $usuario->id ?? 0,
            'usuario_modificacion_nombre' => $usuario->nombre ?? 'Sistema',
            'updated_at' => now(),
        ];
        DB::table('liquidaciones')->where('id', $liquidacion->id)->update($cambios);
        AuditoriaTabla::crear([
            'id_recurso' => $liquidacion->id,
            'nombre_recurso' => 'App\\Models\\Finanzas\\Liquidacion',
            'descripcion_recurso' => $liquidacion->codigo,
            'accion' => 'Modificar',
            'recurso_original' => json_encode($liquidacion),
            'recurso_resultante' => json_encode(DB::table('liquidaciones')->where('id', $liquidacion->id)->first()),
        ]);
    }

    // ---------------------- Consultas -------------------------- //

    public static function consultaLiquidaciones()
    {
        return DB::table('liquidaciones')
            ->join('proveedores_turisticos', 'proveedores_turisticos.id', '=', 'liquidaciones.proveedor_id')
            ->select('liquidaciones.*', 'proveedores_turisticos.nombre_comercial as proveedor_nombre');
    }

    public static function formatearLiquidacion($l)
    {
        $vencida = $l->estado === 'emitida' && $l->fecha_vencimiento && $l->fecha_vencimiento < now()->toDateString();
        return [
            'id' => $l->id,
            'codigo' => $l->codigo,
            'proveedor_id' => $l->proveedor_id,
            'proveedor_nombre' => $l->proveedor_nombre ?? null,
            'periodo' => $l->periodo,
            'periodo_nombre' => self::nombrePeriodo($l->periodo),
            'tipo_modelo' => $l->tipo_modelo,
            'tipo_modelo_nombre' => self::TIPOS[$l->tipo_modelo] ?? $l->tipo_modelo,
            'porcentaje_comision' => (float) $l->porcentaje_comision,
            'valor_suscripcion' => (float) $l->valor_suscripcion,
            'total_ventas' => (float) $l->total_ventas,
            'total_comisiones' => (float) $l->total_comisiones,
            'subtotal' => (float) $l->subtotal,
            'porcentaje_iva' => (float) $l->porcentaje_iva,
            'valor_iva' => (float) $l->valor_iva,
            'total' => (float) $l->total,
            'valor_pagado' => (float) $l->valor_pagado,
            'saldo' => max(0, round((float) $l->total - (float) $l->valor_pagado, 2)),
            'estado' => $l->estado,
            'estado_nombre' => $vencida ? 'Vencida' : (self::ESTADOS_LIQUIDACION[$l->estado] ?? $l->estado),
            'vencida' => $vencida,
            'fecha_emision' => $l->fecha_emision,
            'fecha_vencimiento' => $l->fecha_vencimiento,
            'fecha_pago' => $l->fecha_pago,
            'factura_numero' => $l->factura_numero,
            'observaciones' => $l->observaciones,
            'usuario_modificacion_nombre' => $l->usuario_modificacion_nombre,
            'fecha_creacion' => Carbon::parse($l->created_at)->format('Y-m-d H:i:s'),
            'fecha_modificacion' => Carbon::parse($l->updated_at)->format('Y-m-d H:i:s'),
        ];
    }

    public static function detalle($liquidacionId, $paraProveedor = false)
    {
        $liquidacion = self::consultaLiquidaciones()->where('liquidaciones.id', $liquidacionId)->first();
        $datos = self::formatearLiquidacion($liquidacion);
        $datos['comisiones'] = DB::table('comisiones')
            ->leftJoin('reservas', 'reservas.id', '=', 'comisiones.reserva_id')
            ->leftJoin('experiencias', 'experiencias.id', '=', 'reservas.experiencia_id')
            ->where('comisiones.liquidacion_id', $liquidacionId)
            ->orderBy('comisiones.periodo')
            ->orderBy('reservas.fecha')
            ->get([
                'comisiones.id', 'comisiones.codigo_reserva', 'comisiones.periodo', 'comisiones.tipo', 'comisiones.base',
                'comisiones.porcentaje', 'comisiones.valor', 'comisiones.descripcion', 'reservas.fecha', 'reservas.estado as reserva_estado',
                'experiencias.nombre as experiencia_nombre',
            ]);
        $datos['pagos'] = DB::table('pagos_liquidacion')
            ->where('liquidacion_id', $liquidacionId)
            ->where(fn ($q) => $q->where('estado', 'aprobado')->orWhere(fn ($m) => $m->where('pasarela', self::MANUAL)->where('estado', 'anulado')))
            ->orderBy('id')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'fecha' => $p->fecha_pago ?: substr((string) $p->updated_at, 0, 10),
                'valor' => round($p->monto_centavos / 100, 2),
                'pasarela' => $p->pasarela,
                'metodo_pago' => $p->metodo_pago,
                'estado' => $p->estado,
                'nota' => $paraProveedor ? null : $p->nota,
                'registrado_por' => $paraProveedor ? null : $p->registrado_por,
                'anulado_por' => $paraProveedor ? null : $p->anulado_por,
            ]);
        $datos['pago_en_linea_disponible'] = $liquidacion->estado === 'emitida' && $datos['saldo'] > 0 && (bool) CuentaPasarela::dePlataforma();
        return $datos;
    }

    /**
     * Resumen para el proveedor (o el administrador viendo un proveedor): modelo actual, el próximo si hay un cambio
     * programado, y lo acumulado del mes en curso que irá en la próxima liquidación.
     */
    public static function resumenProveedor($proveedorId)
    {
        $periodo = now()->format('Y-m');
        $modelo = self::modeloVigente($proveedorId, $periodo);
        $proximo = DB::table('modelos_cobro_proveedor')
            ->where('proveedor_id', $proveedorId)
            ->where('vigente_desde', '>', $periodo . '-01')
            ->orderBy('vigente_desde')
            ->first();
        $pendientes = DB::table('comisiones')
            ->leftJoin('liquidaciones', 'liquidaciones.id', '=', 'comisiones.liquidacion_id')
            ->where('comisiones.proveedor_id', $proveedorId)
            ->where(fn ($q) => $q->whereNull('comisiones.liquidacion_id')->orWhere('liquidaciones.estado', 'borrador'));
        $saldo = DB::table('liquidaciones')->where('proveedor_id', $proveedorId)->where('estado', 'emitida')
            ->selectRaw('COALESCE(SUM(total - valor_pagado), 0) as saldo')->value('saldo');

        return [
            'modelo' => self::formatearModelo($modelo),
            'proximo_modelo' => self::formatearModelo($proximo),
            'comisiones_pendientes' => round((float) (clone $pendientes)->sum('comisiones.valor'), 2),
            'ventas_pendientes' => round((float) (clone $pendientes)->sum('comisiones.base'), 2),
            'reservas_pendientes' => (clone $pendientes)->count(),
            'saldo_por_pagar' => round((float) $saldo, 2),
            'suspendido_por_mora' => (bool) DB::table('proveedores_turisticos')->where('id', $proveedorId)->value('suspendido_por_mora'),
            'porcentaje_iva' => (float) self::parametro('COBRO_PORCENTAJE_IVA'),
        ];
    }

    // ---------------------- Pagos -------------------------- //

    public static function esReferenciaDeLiquidacion($referencia)
    {
        return Str::startsWith((string) $referencia, self::PREFIJO);
    }

    // Checkout con la pasarela de la plataforma por el saldo de la liquidación. El monto sale de la BD.
    public static function iniciarPago($liquidacionId)
    {
        $liquidacion = self::consultaLiquidaciones()->where('liquidaciones.id', $liquidacionId)->first();
        $saldo = round((float) $liquidacion->total - (float) $liquidacion->valor_pagado, 2);
        if ($liquidacion->estado !== 'emitida' || $saldo <= 0) {
            throw new Exception('Esta liquidación no tiene un pago pendiente.');
        }
        $cuenta = CuentaPasarela::dePlataforma();
        if (!$cuenta) {
            throw new Exception('El pago en línea de liquidaciones no está disponible: la plataforma no tiene una pasarela activa.');
        }
        $proveedor = DB::table('proveedores_turisticos')
            ->leftJoin('usuarios', 'usuarios.id', '=', 'proveedores_turisticos.usuario_id')
            ->where('proveedores_turisticos.id', $liquidacion->proveedor_id)
            ->select('proveedores_turisticos.nombre_comercial', 'proveedores_turisticos.correo', 'usuarios.correo_electronico')
            ->first();

        $montoCentavos = (int) round($saldo * 100);
        $referencia = $liquidacion->codigo . '-' . strtoupper(Str::random(6));
        DB::table('pagos_liquidacion')->insert([
            'liquidacion_id' => $liquidacion->id,
            'pasarela' => $cuenta->pasarela,
            'cuenta_pasarela_id' => $cuenta->id,
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
            'descripcion' => 'Liquidación ' . $liquidacion->codigo . ' (' . self::nombrePeriodo($liquidacion->periodo) . ')',
            'correo' => $proveedor->correo ?: $proveedor->correo_electronico,
            'nombre' => $proveedor->nombre_comercial,
            'retorno_url' => $cuenta->urlRetorno(['destino' => 'liquidacion']),
            'notificacion_url' => $cuenta->urlEventos(),
        ])];
    }

    // Igual que PagoService::aplicarTransaccion pero para `pagos_liquidacion`. Idempotente.
    public static function aplicarTransaccion(array $transaccion, CuentaPasarela $cuenta)
    {
        $nombre = $cuenta->pasarela()->nombre();
        $liquidacionId = DB::transaction(function () use ($transaccion, $cuenta, $nombre) {
            $pago = DB::table('pagos_liquidacion')
                ->where('referencia', $transaccion['referencia'])
                ->where('cuenta_pasarela_id', $cuenta->id)
                ->lockForUpdate()
                ->first();
            if (!$pago) {
                Log::warning("{$nombre}: transacción de liquidación con referencia desconocida {$transaccion['referencia']}");
                return null;
            }
            if ($transaccion['monto_centavos'] !== (int) $pago->monto_centavos || $transaccion['moneda'] !== $pago->moneda) {
                Log::warning("{$nombre}: el monto de la transacción no coincide con el pago de liquidación {$pago->referencia}");
                return null;
            }
            if ($pago->estado === 'aprobado') {
                return null;
            }
            DB::table('pagos_liquidacion')->where('id', $pago->id)->update([
                'estado' => $transaccion['estado'],
                'transaccion_id' => $transaccion['id'],
                'metodo_pago' => $transaccion['metodo_pago'],
                'respuesta' => json_encode($transaccion['respuesta']),
                'fecha_pago' => $transaccion['estado'] === 'aprobado' ? now()->toDateString() : null,
                'updated_at' => now(),
            ]);
            return $transaccion['estado'] === 'aprobado' ? $pago->liquidacion_id : null;
        });

        if ($liquidacionId) {
            self::recalcularPagado($liquidacionId, "Pago {$nombre}");
        }
    }

    public static function registrarPagoManual($liquidacionId, array $datos, $responsable)
    {
        $liquidacion = DB::table('liquidaciones')->where('id', $liquidacionId)->lockForUpdate()->first();
        if ($liquidacion->estado !== 'emitida') {
            throw new Exception('Solo se registran pagos en liquidaciones emitidas.');
        }
        $saldo = round((float) $liquidacion->total - (float) $liquidacion->valor_pagado, 2);
        $valor = round((float) $datos['valor'], 2);
        if ($valor > $saldo) {
            throw new Exception('El pago supera el saldo de la liquidación (' . self::pesos($saldo) . ').');
        }
        DB::table('pagos_liquidacion')->insert([
            'liquidacion_id' => $liquidacionId,
            'pasarela' => self::MANUAL,
            'referencia' => $liquidacion->codigo . '-M' . strtoupper(Str::random(6)),
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
        self::recalcularPagado($liquidacionId, $responsable);
    }

    public static function anularPagoManual($liquidacionId, $pagoId, $responsable)
    {
        $pago = DB::table('pagos_liquidacion')->where('id', $pagoId)->where('liquidacion_id', $liquidacionId)->lockForUpdate()->first();
        if (!$pago || $pago->pasarela !== self::MANUAL) {
            throw new Exception('Solo se pueden anular los pagos registrados a mano en esta liquidación.');
        }
        if ($pago->estado !== 'aprobado') {
            throw new Exception('Ese pago ya está anulado.');
        }
        DB::table('pagos_liquidacion')->where('id', $pagoId)->update([
            'estado' => 'anulado',
            'anulado_por' => $responsable,
            'anulado_en' => now(),
            'updated_at' => now(),
        ]);
        self::recalcularPagado($liquidacionId, $responsable);
    }

    // valor_pagado = suma de los pagos aprobados; con el total cubierto queda pagada (y si no, vuelve a emitida).
    private static function recalcularPagado($liquidacionId, $responsable)
    {
        $liquidacion = DB::table('liquidaciones')->where('id', $liquidacionId)->first();
        $pagado = round(DB::table('pagos_liquidacion')->where('liquidacion_id', $liquidacionId)->where('estado', 'aprobado')->sum('monto_centavos') / 100, 2);
        $cubierta = $pagado >= (float) $liquidacion->total;
        $cambios = ['valor_pagado' => $pagado];
        if ($cubierta && $liquidacion->estado === 'emitida') {
            $cambios += ['estado' => 'pagada', 'fecha_pago' => now()->toDateString()];
        } elseif (!$cubierta && $liquidacion->estado === 'pagada') {
            $cambios += ['estado' => 'emitida', 'fecha_pago' => null];
        }
        self::actualizar($liquidacion, $cambios, (object) ['id' => 0, 'nombre' => $responsable]);

        if (($cambios['estado'] ?? null) === 'pagada') {
            self::notificar('LIQUIDACION_PAGADA', $liquidacionId);
        }
        self::revisarSuspension($liquidacion->proveedor_id);
    }

    // ---------------------- Mora -------------------------- //

    /**
     * Tarea diaria: avisa (una vez) las liquidaciones vencidas y suspende a los proveedores con alguna vencida hace
     * más de COBRO_DIAS_GRACIA_SUSPENSION días. Devuelve [avisos enviados, proveedores suspendidos].
     */
    public static function revisarMora()
    {
        $hoy = now()->toDateString();
        $vencidas = DB::table('liquidaciones')->where('estado', 'emitida')->where('fecha_vencimiento', '<', $hoy)->whereNull('recordatorio_enviado')->get();
        foreach ($vencidas as $liquidacion) {
            DB::table('liquidaciones')->where('id', $liquidacion->id)->update(['recordatorio_enviado' => $hoy]);
            self::notificar('LIQUIDACION_VENCIDA', $liquidacion->id);
        }

        $suspendidos = 0;
        $proveedores = DB::table('liquidaciones')->where('estado', 'emitida')->where('fecha_vencimiento', '<', $hoy)->distinct()->pluck('proveedor_id');
        foreach ($proveedores as $proveedorId) {
            $suspendidos += self::revisarSuspension($proveedorId) ? 1 : 0;
        }
        return [$vencidas->count(), $suspendidos];
    }

    // Suspende o reactiva al proveedor según sus liquidaciones vencidas. Devuelve true si lo acaba de suspender.
    public static function revisarSuspension($proveedorId)
    {
        $gracia = (int) self::parametro('COBRO_DIAS_GRACIA_SUSPENSION');
        $limite = now()->subDays(max(0, $gracia))->toDateString();
        $enMora = $gracia > 0 ? DB::table('liquidaciones')
            ->where('proveedor_id', $proveedorId)
            ->where('estado', 'emitida')
            ->where('fecha_vencimiento', '<', $limite)
            ->orderBy('fecha_vencimiento')
            ->first() : null;
        $estaba = (bool) DB::table('proveedores_turisticos')->where('id', $proveedorId)->value('suspendido_por_mora');

        if ((bool) $enMora === $estaba) {
            return false;
        }
        DB::table('proveedores_turisticos')->where('id', $proveedorId)->update(['suspendido_por_mora' => (bool) $enMora]);
        Log::info('Cobro a proveedores: el proveedor ' . $proveedorId . ($enMora ? ' quedó suspendido por mora' : ' salió de la suspensión por mora'));
        if ($enMora) {
            self::notificar('PROVEEDOR_SUSPENDIDO_MORA', $enMora->id);
        }
        return (bool) $enMora;
    }

    // ---------------------- Correos -------------------------- //

    private static function notificar($plantilla, $liquidacionId)
    {
        $tarea = function () use ($plantilla, $liquidacionId) {
            $l = DB::table('liquidaciones')
                ->join('proveedores_turisticos', 'proveedores_turisticos.id', '=', 'liquidaciones.proveedor_id')
                ->leftJoin('usuarios', 'usuarios.id', '=', 'proveedores_turisticos.usuario_id')
                ->where('liquidaciones.id', $liquidacionId)
                ->select('liquidaciones.*', 'proveedores_turisticos.nombre_comercial', 'proveedores_turisticos.correo', 'usuarios.correo_electronico')
                ->first();
            $correo = $l?->correo ?: $l?->correo_electronico;
            if (!$l || !$correo || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            [$asunto, $texto, $titulo] = self::PLANTILLAS[$plantilla];
            $guardada = DB::table('parametros_correos')->where('nombre', $plantilla)->select('asunto', 'texto', 'estado')->first();
            if ($guardada && !$guardada->estado) {
                return;
            }
            if ($guardada) {
                [$asunto, $texto] = [$guardada->asunto, $guardada->texto];
            }
            $variables = [
                '{proveedor}' => $l->nombre_comercial,
                '{codigo}' => $l->codigo,
                '{periodo}' => self::nombrePeriodo($l->periodo),
                '{total}' => self::pesos($l->total),
                '{pagado}' => self::pesos($l->valor_pagado),
                '{saldo}' => self::pesos(max(0, (float) $l->total - (float) $l->valor_pagado)),
                '{vencimiento}' => $l->fecha_vencimiento ?? '',
            ];
            $variablesHtml = array_map(fn ($v) => e((string) $v), $variables);

            try {
                Mail::to($correo)->send(new LiquidacionMail(
                    strtr($asunto, $variables),
                    strtr($titulo, $variables),
                    strtr($texto, $variablesHtml),
                    self::formatearLiquidacion($l),
                    config('app.front_url') . '/liquidaciones/' . $l->id . '/ver',
                ));
            } catch (Throwable $e) {
                Log::warning("No se pudo enviar el correo {$plantilla} de la liquidación {$l->codigo} a {$correo}: {$e->getMessage()}");
            }
        };
        app()->runningInConsole() ? $tarea() : defer($tarea);
    }
}
