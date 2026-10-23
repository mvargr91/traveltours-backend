<?php

namespace App\Http\Controllers\Finanzas;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AlcancePorRol;
use Illuminate\Support\Facades\Validator;
use App\Services\CobroProveedores;

/**
 * Finanzas > Liquidaciones: el cobro mensual de la plataforma a cada proveedor.
 *  - Administración: genera los borradores del mes, los emite, registra pagos, reabre o anula.
 *  - Proveedor: ve las suyas (no los borradores), su resumen (modelo y lo acumulado del mes) y paga en línea.
 */
class LiquidacionController extends Controller
{
    use AlcancePorRol;

    private function usuario()
    {
        return Auth::user()?->usuario();
    }

    private function responsable()
    {
        return $this->usuario()->nombre ?? 'Administrador';
    }

    // Busca la liquidación y valida el alcance; el proveedor no ve los borradores.
    private function liquidacion($id)
    {
        $liquidacion = DB::table('liquidaciones')->where('id', $id)->first();
        if (!$liquidacion || ($this->esProveedor() && $liquidacion->estado === 'borrador')) {
            return [null, response(get_response_body(['La liquidación no existe.']), Response::HTTP_NOT_FOUND)];
        }
        if (!$this->enAlcance($liquidacion->proveedor_id)) {
            return [null, $this->respuestaSinAcceso()];
        }
        return [$liquidacion, null];
    }

    public function index(Request $request)
    {
        try {
            $datos = $this->aplicarAlcance($request->all(), 'proveedor_id', null);
            $hoy = now()->toDateString();
            $query = CobroProveedores::consultaLiquidaciones()
                ->when($datos['proveedor_id'] ?? null, fn ($q, $v) => $q->where('liquidaciones.proveedor_id', $v))
                ->when($datos['periodo'] ?? null, fn ($q, $v) => $q->where('liquidaciones.periodo', $v))
                ->when($this->esProveedor(), fn ($q) => $q->where('liquidaciones.estado', '<>', 'borrador'))
                ->when(($datos['estado'] ?? null) === 'vencida', fn ($q) => $q->where('liquidaciones.estado', 'emitida')->where('liquidaciones.fecha_vencimiento', '<', $hoy))
                ->when(($datos['estado'] ?? null) && $datos['estado'] !== 'vencida', fn ($q) => $q->where('liquidaciones.estado', $datos['estado']))
                ->orderByDesc('liquidaciones.periodo')
                ->orderBy('proveedores_turisticos.nombre_comercial');
            $pagina = $query->paginate((int) ($datos['limite'] ?? 100));

            $estados = collect(CobroProveedores::ESTADOS_LIQUIDACION)->map(fn ($n, $id) => ['id' => $id, 'nombre' => $n])->values()->all();
            array_splice($estados, 2, 0, [['id' => 'vencida', 'nombre' => 'Vencida']]);

            return response([
                'datos' => $pagina->getCollection()->map(fn ($l) => CobroProveedores::formatearLiquidacion($l))->all(),
                'estados' => $this->esProveedor() ? array_values(array_filter($estados, fn ($e) => $e['id'] !== 'borrador')) : $estados,
                'periodo_sugerido' => CobroProveedores::periodoAnterior(),
                'desde' => $pagina->firstItem(),
                'hasta' => $pagina->lastItem(),
                'por_pagina' => $pagina->perPage(),
                'pagina_actual' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'total' => $pagina->total(),
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function show($id)
    {
        [$liquidacion, $error] = $this->liquidacion($id);
        if ($error) {
            return $error;
        }
        $datos = CobroProveedores::detalle($id, $this->esProveedor());
        $datos['medios_pago'] = CobroProveedores::MEDIOS_MANUALES;
        return response($datos, Response::HTTP_OK);
    }

    // Modelo actual y lo pendiente por liquidar. El proveedor ve el suyo; la administración, el del proveedor indicado.
    public function resumen(Request $request)
    {
        $datos = $this->aplicarAlcance($request->all(), 'proveedor_id', null);
        $proveedorId = (int) ($datos['proveedor_id'] ?? 0);
        $proveedor = DB::table('proveedores_turisticos')->where('id', $proveedorId)->first();
        if (!$proveedor || $proveedor->es_plataforma) {
            return response(['modelo' => null, 'es_plataforma' => (bool) ($proveedor->es_plataforma ?? false)], Response::HTTP_OK);
        }
        return response(CobroProveedores::resumenProveedor($proveedorId) + ['proveedor_nombre' => $proveedor->nombre_comercial], Response::HTTP_OK);
    }

    public function generar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'periodo' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'proveedor_id' => 'integer|nullable|exists:proveedores_turisticos,id',
        ], ['periodo.regex' => 'El mes debe tener el formato AAAA-MM.']);
        if ($validator->fails()) {
            return response(get_response_body(format_messages_validator($validator)), Response::HTTP_BAD_REQUEST);
        }
        if ($request->periodo >= now()->format('Y-m')) {
            return response(get_response_body(['Solo se liquidan meses terminados.']), Response::HTTP_BAD_REQUEST);
        }
        DB::beginTransaction();
        try {
            $total = CobroProveedores::generar($request->periodo, $request->proveedor_id, $this->usuario());
            DB::commit();
            $texto = $total
                ? "Se generaron o actualizaron {$total} liquidaciones en borrador de " . CobroProveedores::nombrePeriodo($request->periodo) . '. Revísalas y emítelas.'
                : 'No hay valores por liquidar en ' . CobroProveedores::nombrePeriodo($request->periodo) . ' (o ya están emitidas).';
            return response(get_response_body([$texto, 2], ['total' => $total]), Response::HTTP_OK);
        } catch (Exception $e) {
            DB::rollback();
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // Acciones del administrador sobre la liquidación: recalcular, emitir, reabrir, anular.
    private function accion($id, callable $accion, $mensaje)
    {
        [$liquidacion, $error] = $this->liquidacion($id);
        if ($error) {
            return $error;
        }
        DB::beginTransaction();
        try {
            $accion($liquidacion);
            DB::commit();
            $existe = DB::table('liquidaciones')->where('id', $id)->exists();
            return response(get_response_body([$mensaje, 1], $existe ? CobroProveedores::detalle($id) : null), Response::HTTP_OK);
        } catch (Exception $e) {
            DB::rollback();
            return response(get_response_body([$e->getMessage()]), Response::HTTP_CONFLICT);
        }
    }

    public function recalcular($id)
    {
        return $this->accion($id, fn () => CobroProveedores::recalcular($id), 'La liquidación ha sido recalculada.');
    }

    public function emitir(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'factura_numero' => 'string|nullable|max:50',
            'observaciones' => 'string|nullable|max:2000',
        ]);
        if ($validator->fails()) {
            return response(get_response_body(format_messages_validator($validator)), Response::HTTP_BAD_REQUEST);
        }
        return $this->accion(
            $id,
            fn () => CobroProveedores::emitir($id, $this->usuario(), $request->only('factura_numero', 'observaciones')),
            'La liquidación ha sido emitida y se le envió al proveedor.'
        );
    }

    public function reabrir($id)
    {
        return $this->accion($id, fn () => CobroProveedores::reabrir($id, $this->usuario()), 'La liquidación volvió a borrador.');
    }

    public function anular(Request $request, $id)
    {
        $validator = Validator::make($request->all(), ['motivo' => 'string|required|max:500'], ['motivo.required' => 'Escribe el motivo de la anulación.']);
        if ($validator->fails()) {
            return response(get_response_body(format_messages_validator($validator)), Response::HTTP_BAD_REQUEST);
        }
        return $this->accion($id, fn () => CobroProveedores::anular($id, $this->usuario(), $request->motivo), 'La liquidación ha sido anulada: no se le cobrará al proveedor.');
    }

    public function destroy($id)
    {
        return $this->accion($id, fn () => CobroProveedores::eliminarBorrador($id), 'El borrador ha sido eliminado. Sus comisiones pasan a la siguiente liquidación.');
    }

    public function registrarPago(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'valor' => 'numeric|required|min:1',
            'metodo_pago' => 'string|required|in:' . implode(',', CobroProveedores::MEDIOS_MANUALES),
            'fecha_pago' => 'date|required|before_or_equal:today',
            'nota' => 'string|nullable|max:255',
        ], ['fecha_pago.before_or_equal' => 'La fecha del pago no puede ser futura.']);
        if ($validator->fails()) {
            return response(get_response_body(format_messages_validator($validator)), Response::HTTP_BAD_REQUEST);
        }
        return $this->accion(
            $id,
            fn () => CobroProveedores::registrarPagoManual($id, $request->only('valor', 'metodo_pago', 'fecha_pago', 'nota'), $this->responsable()),
            'El pago ha sido registrado.'
        );
    }

    public function anularPago($id, $pagoId)
    {
        return $this->accion($id, fn () => CobroProveedores::anularPagoManual($id, $pagoId, $this->responsable()), 'El pago ha sido anulado.');
    }

    // El proveedor (o el administrador) abre el checkout de la plataforma por el saldo.
    public function pagar($id)
    {
        [$liquidacion, $error] = $this->liquidacion($id);
        if ($error) {
            return $error;
        }
        DB::beginTransaction();
        try {
            $checkout = CobroProveedores::iniciarPago($id);
            DB::commit();
            return response()->json($checkout, Response::HTTP_OK);
        } catch (Exception $e) {
            DB::rollback();
            Log::warning("Liquidaciones: no se pudo iniciar el pago de {$liquidacion->codigo}: " . $e->getMessage());
            return response(get_response_body([$e->getMessage()]), Response::HTTP_CONFLICT);
        }
    }
}
