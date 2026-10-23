<?php

namespace App\Http\Controllers\Finanzas;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Services\CobroProveedores;

/**
 * Finanzas > Modelos de cobro (solo administración): cuánto le cobra la plataforma a cada proveedor.
 * La lista es de proveedores (sin el de la plataforma) con el modelo de este mes y lo pendiente por liquidar;
 * el detalle trae el historial. Guardar crea o reemplaza el modelo desde un mes (YYYY-MM).
 */
class ModeloCobroController extends Controller
{
    public function index(Request $request)
    {
        try {
            $periodo = now()->format('Y-m');
            $vigente = "(SELECT m.id FROM modelos_cobro_proveedor m WHERE m.proveedor_id = proveedores_turisticos.id
                AND m.vigente_desde <= '{$periodo}-01' ORDER BY m.vigente_desde DESC LIMIT 1)";
            $query = DB::table('proveedores_turisticos')
                ->leftJoin('modelos_cobro_proveedor as modelo', 'modelo.id', '=', DB::raw($vigente))
                ->where('proveedores_turisticos.es_plataforma', false)
                ->when($request->nombre, fn ($q, $v) => $q->where('proveedores_turisticos.nombre_comercial', 'like', "%{$v}%"))
                ->when($request->tipo === 'sin_modelo', fn ($q) => $q->whereNull('modelo.id'))
                ->when($request->tipo && $request->tipo !== 'sin_modelo', fn ($q) => $q->where('modelo.tipo', $request->tipo))
                ->select(
                    'proveedores_turisticos.id',
                    'proveedores_turisticos.nombre_comercial as proveedor_nombre',
                    'proveedores_turisticos.estado_verificacion',
                    'proveedores_turisticos.suspendido_por_mora',
                    'modelo.tipo',
                    'modelo.valor_mensual',
                    'modelo.porcentaje_comision',
                    'modelo.vigente_desde',
                    DB::raw("EXISTS (SELECT 1 FROM cuentas_pasarela cp WHERE cp.proveedor_id = proveedores_turisticos.id AND cp.estado = 'activa') as pasarela_activa"),
                    DB::raw("(SELECT MIN(m2.vigente_desde) FROM modelos_cobro_proveedor m2 WHERE m2.proveedor_id = proveedores_turisticos.id
                        AND m2.vigente_desde > '{$periodo}-01') as proximo_cambio"),
                )
                ->orderBy('proveedores_turisticos.nombre_comercial');
            $pagina = $query->paginate((int) ($request->limite ?? 100));

            return response([
                'datos' => $pagina->getCollection()->map(fn ($p) => (array) $p + [
                    'tipo_nombre' => $p->tipo ? CobroProveedores::TIPOS[$p->tipo] : 'Sin modelo',
                    'pasarela_activa' => (bool) $p->pasarela_activa,
                    'suspendido_por_mora' => (bool) $p->suspendido_por_mora,
                    'proximo_cambio' => $p->proximo_cambio ? substr($p->proximo_cambio, 0, 7) : null,
                ])->all(),
                'tipos' => collect(CobroProveedores::TIPOS)->map(fn ($n, $id) => ['id' => $id, 'nombre' => $n])->values(),
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

    // $id = proveedor.
    public function show($id)
    {
        $proveedor = DB::table('proveedores_turisticos')->where('id', $id)->select('id', 'nombre_comercial', 'es_plataforma')->first();
        if (!$proveedor || $proveedor->es_plataforma) {
            return response(get_response_body(['El proveedor no existe o no tiene modelo de cobro.']), Response::HTTP_NOT_FOUND);
        }
        return response([
            'proveedor_id' => $proveedor->id,
            'proveedor_nombre' => $proveedor->nombre_comercial,
            'resumen' => CobroProveedores::resumenProveedor($id),
            'historial' => CobroProveedores::historialModelos($id),
            'tipos' => collect(CobroProveedores::TIPOS)->map(fn ($n, $tipo) => ['id' => $tipo, 'nombre' => $n])->values(),
        ], Response::HTTP_OK);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'proveedor_id' => 'integer|required|exists:proveedores_turisticos,id',
            'tipo' => 'string|required|in:' . implode(',', array_keys(CobroProveedores::TIPOS)),
            'valor_mensual' => 'numeric|min:0|max:9999999999|required_if:tipo,suscripcion,mixto',
            'porcentaje_comision' => 'numeric|min:0|max:100|required_if:tipo,comision,mixto',
            'desde' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'observaciones' => 'string|nullable|max:500',
        ], [
            'valor_mensual.required_if' => 'Escribe el valor mensual de la suscripción.',
            'porcentaje_comision.required_if' => 'Escribe el porcentaje de comisión.',
            'porcentaje_comision.max' => 'El porcentaje de comisión no puede ser mayor a 100.',
            'desde.regex' => 'El mes de inicio debe tener el formato AAAA-MM.',
        ]);
        if ($validator->fails()) {
            return response(get_response_body(format_messages_validator($validator)), Response::HTTP_BAD_REQUEST);
        }
        if (in_array($request->tipo, ['suscripcion', 'mixto'], true) && (float) $request->valor_mensual <= 0) {
            return response(get_response_body(['El valor mensual de la suscripción debe ser mayor a cero.']), Response::HTTP_BAD_REQUEST);
        }
        if (in_array($request->tipo, ['comision', 'mixto'], true) && (float) $request->porcentaje_comision <= 0) {
            return response(get_response_body(['El porcentaje de comisión debe ser mayor a cero.']), Response::HTTP_BAD_REQUEST);
        }

        DB::beginTransaction();
        try {
            $modelo = CobroProveedores::guardarModelo($request->all(), Auth::user()?->usuario());
            DB::commit();
            return response(get_response_body(['El modelo de cobro ha sido guardado desde ' . $modelo['desde_nombre'] . '.', 1], $modelo), Response::HTTP_OK);
        } catch (Exception $e) {
            DB::rollback();
            return response(get_response_body([$e->getMessage()]), Response::HTTP_CONFLICT);
        }
    }

    // $id = modelo (un cambio programado o un mes aún sin liquidar).
    public function destroy($id)
    {
        if (!DB::table('modelos_cobro_proveedor')->where('id', $id)->exists()) {
            return response(get_response_body(['El modelo de cobro no existe.']), Response::HTTP_NOT_FOUND);
        }
        DB::beginTransaction();
        try {
            CobroProveedores::eliminarModelo($id);
            DB::commit();
            return response(get_response_body(['El modelo de cobro ha sido eliminado.', 3]), Response::HTTP_OK);
        } catch (Exception $e) {
            DB::rollback();
            return response(get_response_body([$e->getMessage()]), Response::HTTP_CONFLICT);
        }
    }
}
