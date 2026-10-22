<?php

namespace App\Http\Controllers\Turismo;

use Exception;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AlcancePorRol;
use App\Models\Reservas\Reserva;
use App\Services\PagoService;

/**
 * Inicio > Dashboard: indicadores del negocio.
 *  - Proveedor: solo sus experiencias, reservas, ingresos y reseñas.
 *  - Administración: toda la plataforma, más lo que espera su revisión (proveedores, experiencias,
 *    documentos y reseñas pendientes).
 * Ingresos = pagos aprobados (en línea y manuales) por su fecha, menos los reembolsos.
 */
class DashboardController extends Controller
{
    use AlcancePorRol;

    public function index()
    {
        try {
            $proveedorId = $this->esProveedor() ? $this->proveedorActualId() : null;
            $hoy = Carbon::now();
            $inicioMes = $hoy->copy()->startOfMonth()->toDateString();

            $reservas = fn () => DB::table('reservas')->when($proveedorId, fn ($q) => $q->where('reservas.proveedor_id', $proveedorId));
            $experiencias = fn () => DB::table('experiencias')->when($proveedorId, fn ($q) => $q->where('proveedor_id', $proveedorId));

            $porEstadoExperiencia = $experiencias()->groupBy('estado')->selectRaw('estado, COUNT(*) as total')->pluck('total', 'estado');

            $resenas = DB::table('resenas')
                ->join('experiencias', 'experiencias.id', '=', 'resenas.experiencia_id')
                ->when($proveedorId, fn ($q) => $q->where('experiencias.proveedor_id', $proveedorId))
                ->where('resenas.estado', 'aprobada')
                ->selectRaw('ROUND(AVG(resenas.calificacion), 1) as promedio, COUNT(*) as total')
                ->first();

            $indicadores = [
                'experiencias_publicadas' => (int) ($porEstadoExperiencia['publicada'] ?? 0),
                'experiencias_suspendidas' => (int) ($porEstadoExperiencia['suspendida'] ?? 0),
                'experiencias_borrador' => (int) ($porEstadoExperiencia['borrador'] ?? 0),
                'reservas_pendientes' => $reservas()->whereIn('estado', ['pendiente', 'pendiente_pago'])->count(),
                'reservas_confirmadas' => $reservas()->where('estado', 'aceptada')->where('fecha', '>=', $hoy->toDateString())->count(),
                'reservas_mes' => $reservas()->where('created_at', '>=', $inicioMes)->count(),
                'ingresos_mes' => $this->ingresos($proveedorId, $inicioMes, $hoy->toDateString()),
                'saldo_por_cobrar' => (float) $reservas()->whereIn('estado', Reserva::ESTADOS_CON_CUPO)
                    ->whereColumn('valor_total', '>', 'valor_pagado')
                    ->sum(DB::raw('valor_total - valor_pagado')),
                'calificacion_promedio' => $resenas->promedio !== null ? (float) $resenas->promedio : null,
                'total_resenas' => (int) $resenas->total,
            ];

            if (!$proveedorId) {
                $indicadores += [
                    'proveedores_activos' => DB::table('proveedores_turisticos')->where('estado', 1)->count(),
                    'proveedores_pendientes' => DB::table('proveedores_turisticos')->where('estado_verificacion', 'pendiente')->count(),
                    'documentos_pendientes' => DB::table('documentos_proveedor')->where('estado', 'pendiente')->count(),
                    'resenas_pendientes' => DB::table('resenas')->where('estado', 'pendiente')->count(),
                    'clientes' => DB::table('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->where('roles.name', self::ROL_CLIENTE)
                        ->count(),
                ];
            }

            return response([
                'alcance' => $proveedorId ? 'proveedor' : 'plataforma',
                'indicadores' => $indicadores,
                'reservas_por_mes' => $this->reservasPorMes($proveedorId, 6),
                'reservas_por_estado' => $reservas()->groupBy('estado')->selectRaw('estado, COUNT(*) as total')->orderByDesc('total')->get(),
                'proximas_reservas' => $this->listaReservas($proveedorId)
                    ->whereIn('reservas.estado', Reserva::ESTADOS_VIGENTES)
                    ->where('reservas.fecha', '>=', $hoy->toDateString())
                    ->orderBy('reservas.fecha')->orderBy('reservas.hora_inicio')
                    ->limit(8)->get(),
                'solicitudes_pendientes' => $this->listaReservas($proveedorId)
                    ->where('reservas.estado', 'pendiente')
                    ->orderBy('reservas.created_at')
                    ->limit(8)->get(),
                'top_experiencias' => DB::table('reservas')
                    ->join('experiencias', 'experiencias.id', '=', 'reservas.experiencia_id')
                    ->when($proveedorId, fn ($q) => $q->where('reservas.proveedor_id', $proveedorId))
                    ->whereIn('reservas.estado', Reserva::ESTADOS_CON_CUPO)
                    ->where('reservas.created_at', '>=', $hoy->copy()->subDays(90)->toDateString())
                    ->groupBy('experiencias.id', 'experiencias.nombre')
                    ->selectRaw('experiencias.id, experiencias.nombre, COUNT(*) as reservas, SUM(reservas.cantidad_personas) as personas, SUM(reservas.valor_total) as valor')
                    ->orderByDesc('reservas')
                    ->limit(5)->get(),
                'resenas_recientes' => DB::table('resenas')
                    ->join('experiencias', 'experiencias.id', '=', 'resenas.experiencia_id')
                    ->leftJoin('usuarios', 'usuarios.id', '=', 'resenas.usuario_id')
                    ->when($proveedorId, fn ($q) => $q->where('experiencias.proveedor_id', $proveedorId)->where('resenas.estado', 'aprobada'))
                    ->orderByDesc('resenas.created_at')
                    ->limit(5)
                    ->get([
                        'resenas.id', 'resenas.calificacion', 'resenas.comentario', 'resenas.estado',
                        'resenas.created_at as fecha', 'experiencias.nombre as experiencia_nombre', 'usuarios.nombre as usuario_nombre',
                    ]),
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // Pagos aprobados entre las fechas menos los reembolsos del mismo periodo.
    public static function ingresos($proveedorId, $desde, $hasta)
    {
        $fila = DB::table('pagos')
            ->join('reservas', 'reservas.id', '=', 'pagos.reserva_id')
            ->when($proveedorId, fn ($q) => $q->where('reservas.proveedor_id', $proveedorId))
            ->where('pagos.estado', 'aprobado')
            ->whereRaw('COALESCE(pagos.fecha_pago, DATE(pagos.updated_at)) BETWEEN ? AND ?', [$desde, $hasta])
            ->selectRaw(
                'SUM(CASE WHEN pagos.concepto = ? THEN 0 ELSE pagos.monto_centavos END) as cobrado, ' .
                'SUM(CASE WHEN pagos.concepto = ? THEN pagos.monto_centavos ELSE 0 END) as reembolsado',
                [PagoService::REEMBOLSO, PagoService::REEMBOLSO]
            )
            ->first();
        return round(((float) $fila->cobrado - (float) $fila->reembolsado) / 100, 2);
    }

    // Últimos $meses meses (incluido el actual), con los meses sin reservas en cero.
    private function reservasPorMes($proveedorId, $meses)
    {
        $desde = Carbon::now()->startOfMonth()->subMonths($meses - 1);
        $filas = DB::table('reservas')
            ->when($proveedorId, fn ($q) => $q->where('proveedor_id', $proveedorId))
            ->where('created_at', '>=', $desde->toDateString())
            ->whereNotIn('estado', ['rechazada', 'cancelada'])
            ->groupByRaw("DATE_FORMAT(created_at, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as mes, COUNT(*) as reservas, SUM(valor_total) as valor")
            ->get()
            ->keyBy('mes');

        return collect(range(0, $meses - 1))->map(function ($i) use ($desde, $filas) {
            $mes = $desde->copy()->addMonths($i)->format('Y-m');
            return [
                'mes' => $mes,
                'reservas' => (int) ($filas[$mes]->reservas ?? 0),
                'valor' => (float) ($filas[$mes]->valor ?? 0),
            ];
        });
    }

    private function listaReservas($proveedorId)
    {
        return DB::table('reservas')
            ->join('experiencias', 'experiencias.id', '=', 'reservas.experiencia_id')
            ->when($proveedorId, fn ($q) => $q->where('reservas.proveedor_id', $proveedorId))
            ->select(
                'reservas.id', 'reservas.codigo_reserva', 'reservas.fecha', 'reservas.hora_inicio', 'reservas.nombre',
                'reservas.cantidad_personas', 'reservas.valor_total', 'reservas.valor_pagado', 'reservas.estado',
                'experiencias.nombre as experiencia_nombre',
            );
    }
}
