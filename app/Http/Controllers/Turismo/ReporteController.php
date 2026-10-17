<?php

namespace App\Http\Controllers\Turismo;

use Exception;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Concerns\AlcancePorRol;
use App\Models\Reservas\Reserva;
use App\Services\PagoService;

/**
 * Inicio > Reportes: estadísticas de reservas y transacciones en un rango de fechas.
 * Las reservas se filtran por la fecha en que se hicieron (fecha_base=creacion) o por la fecha del tour
 * (fecha_base=servicio); las transacciones, por la fecha del pago. Un proveedor solo ve lo suyo.
 * "Vendido" cuenta las reservas confirmadas (aceptadas, finalizadas o no asistió).
 */
class ReporteController extends Controller
{
    use AlcancePorRol;

    private const LIMITE_TRANSACCIONES = 2000;

    public function index(Request $request)
    {
        try {
            $datos = $this->aplicarAlcance($request->all(), 'proveedor_id', null);
            $validator = Validator::make($datos, [
                'desde' => 'nullable|date_format:Y-m-d',
                'hasta' => 'nullable|date_format:Y-m-d|after_or_equal:desde',
                'fecha_base' => 'nullable|in:creacion,servicio',
                'proveedor_id' => 'nullable|integer',
                'experiencia_id' => 'nullable|integer',
                'destino_id' => 'nullable|integer',
            ], [
                'hasta.after_or_equal' => 'La fecha final debe ser posterior a la inicial.',
            ]);
            if ($validator->fails()) {
                return response(get_response_body(format_messages_validator($validator)), Response::HTTP_BAD_REQUEST);
            }

            $desde = $datos['desde'] ?? Carbon::now()->startOfYear()->toDateString();
            $hasta = $datos['hasta'] ?? Carbon::now()->toDateString();
            $columnaFecha = ($datos['fecha_base'] ?? 'creacion') === 'servicio' ? 'reservas.fecha' : 'reservas.created_at';

            // Reservas del rango con los filtros (se clona para cada agrupación).
            $base = DB::table('reservas')
                ->join('experiencias', 'experiencias.id', '=', 'reservas.experiencia_id')
                ->join('proveedores_turisticos', 'proveedores_turisticos.id', '=', 'reservas.proveedor_id')
                ->leftJoin('destinos', 'destinos.id', '=', 'experiencias.destino_id')
                ->whereRaw("DATE({$columnaFecha}) BETWEEN ? AND ?", [$desde, $hasta])
                ->when($datos['proveedor_id'] ?? null, fn ($q, $v) => $q->where('reservas.proveedor_id', $v))
                ->when($datos['experiencia_id'] ?? null, fn ($q, $v) => $q->where('reservas.experiencia_id', $v))
                ->when($datos['destino_id'] ?? null, fn ($q, $v) => $q->where('experiencias.destino_id', $v));
            $confirmadas = fn () => (clone $base)->whereIn('reservas.estado', Reserva::ESTADOS_CON_CUPO);
            $metricas = 'COUNT(*) as reservas, SUM(reservas.cantidad_personas) as personas, SUM(reservas.valor_total) as vendido, '
                . 'SUM(reservas.valor_pagado) as pagado, SUM(reservas.valor_reembolsado) as reembolsado';

            $totales = (clone $base)->selectRaw('COUNT(*) as reservas')->first();
            $vendido = $confirmadas()->selectRaw($metricas)->first();

            $resumen = [
                'reservas' => (int) $totales->reservas,
                'reservas_confirmadas' => (int) $vendido->reservas,
                'personas' => (int) $vendido->personas,
                'vendido' => (float) $vendido->vendido,
                'pagado' => (float) $vendido->pagado,
                'saldo_por_cobrar' => max(0, (float) $vendido->vendido - (float) $vendido->pagado),
                'ticket_promedio' => $vendido->reservas ? round((float) $vendido->vendido / $vendido->reservas, 2) : 0,
                'cancelaciones' => (clone $base)->whereIn('reservas.estado', ['cancelada', 'rechazada'])->count(),
                'ingresos_netos' => DashboardController::ingresos($datos['proveedor_id'] ?? null, $desde, $hasta),
                'reembolsado' => $this->reembolsado($datos['proveedor_id'] ?? null, $desde, $hasta),
            ];

            $agrupar = fn ($columnas, $alias, $orden = 'vendido') => $confirmadas()
                ->groupBy(array_map(fn ($c) => str_contains($c, '(') ? DB::raw($c) : $c, $columnas))
                ->selectRaw(implode(', ', array_map(fn ($c, $a) => "{$c} as {$a}", $columnas, $alias)) . ', ' . $metricas)
                ->orderByDesc($orden);

            return response([
                'filtros' => ['desde' => $desde, 'hasta' => $hasta, 'fecha_base' => $datos['fecha_base'] ?? 'creacion'],
                'resumen' => $resumen,
                'por_estado' => (clone $base)->groupBy('reservas.estado')
                    ->selectRaw('reservas.estado, COUNT(*) as reservas, SUM(reservas.valor_total) as valor')
                    ->orderByDesc('reservas')->get(),
                'por_mes' => $agrupar(["DATE_FORMAT({$columnaFecha}, '%Y-%m')"], ['mes'], 'mes')->reorder()->orderBy('mes')->get(),
                'por_experiencia' => $agrupar(['experiencias.id', 'experiencias.nombre'], ['id', 'nombre'])->limit(20)->get(),
                'por_proveedor' => $this->esProveedor() ? [] : $agrupar(['proveedores_turisticos.id', 'proveedores_turisticos.nombre_comercial'], ['id', 'nombre'])->get(),
                'por_destino' => $agrupar(['destinos.id', 'destinos.nombre'], ['id', 'nombre'])->get(),
                'transacciones' => $this->transacciones($datos, $desde, $hasta),
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function reembolsado($proveedorId, $desde, $hasta)
    {
        return round(DB::table('pagos')
            ->join('reservas', 'reservas.id', '=', 'pagos.reserva_id')
            ->when($proveedorId, fn ($q) => $q->where('reservas.proveedor_id', $proveedorId))
            ->where('pagos.estado', 'aprobado')
            ->where('pagos.concepto', PagoService::REEMBOLSO)
            ->whereRaw('COALESCE(pagos.fecha_pago, DATE(pagos.updated_at)) BETWEEN ? AND ?', [$desde, $hasta])
            ->sum('pagos.monto_centavos') / 100, 2);
    }

    // Pagos, reembolsos y anulaciones del rango (en línea y manuales); los intentos sin aprobar no se listan.
    private function transacciones(array $datos, $desde, $hasta)
    {
        return DB::table('pagos')
            ->join('reservas', 'reservas.id', '=', 'pagos.reserva_id')
            ->join('experiencias', 'experiencias.id', '=', 'reservas.experiencia_id')
            ->join('proveedores_turisticos', 'proveedores_turisticos.id', '=', 'reservas.proveedor_id')
            ->whereIn('pagos.estado', ['aprobado', 'anulado'])
            ->whereRaw('COALESCE(pagos.fecha_pago, DATE(pagos.updated_at)) BETWEEN ? AND ?', [$desde, $hasta])
            ->when($datos['proveedor_id'] ?? null, fn ($q, $v) => $q->where('reservas.proveedor_id', $v))
            ->when($datos['experiencia_id'] ?? null, fn ($q, $v) => $q->where('reservas.experiencia_id', $v))
            ->when($datos['destino_id'] ?? null, fn ($q, $v) => $q->where('experiencias.destino_id', $v))
            ->orderByDesc(DB::raw('COALESCE(pagos.fecha_pago, DATE(pagos.updated_at))'))
            ->orderByDesc('pagos.id')
            ->limit(self::LIMITE_TRANSACCIONES)
            ->get([
                'pagos.id',
                DB::raw('COALESCE(pagos.fecha_pago, DATE(pagos.updated_at)) as fecha'),
                'reservas.id as reserva_id',
                'reservas.codigo_reserva',
                'reservas.nombre as viajero',
                'experiencias.nombre as experiencia_nombre',
                'proveedores_turisticos.nombre_comercial as proveedor_nombre',
                'pagos.pasarela',
                'pagos.metodo_pago',
                'pagos.concepto',
                'pagos.estado',
                'pagos.referencia',
                'pagos.registrado_por',
                DB::raw('ROUND(pagos.monto_centavos / 100, 2) as valor'),
            ]);
    }
}
