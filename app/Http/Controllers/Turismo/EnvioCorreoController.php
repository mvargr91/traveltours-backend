<?php

namespace App\Http\Controllers\Turismo;

use Exception;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AlcancePorRol;
use Illuminate\Support\Facades\Validator;
use App\Services\EnviosCorreo;

/**
 * Envíos de correo a los clientes de un proveedor y su seguimiento por destinatario.
 *  - Administración: elige el proveedor; ve los envíos de todos.
 *  - Proveedor: escribe solo a sus clientes (el proveedor se toma del token) y ve solo sus envíos.
 */
class EnvioCorreoController extends Controller
{
    use AlcancePorRol;

    private function formatear(object $e): array
    {
        $conteo = DB::table('envio_correo_destinatarios')->where('envio_id', $e->id)
            ->groupBy('estado')->select('estado', DB::raw('COUNT(*) as n'))->pluck('n', 'estado');
        $experiencia = ($e->audiencia === 'experiencia')
            ? DB::table('experiencias')->where('id', json_decode($e->filtro ?? '{}', true)['experiencia_id'] ?? 0)->value('nombre')
            : null;

        return [
            'id' => $e->id,
            'proveedor_id' => $e->proveedor_id,
            'proveedor_nombre' => $e->proveedor_nombre ?? null,
            'asunto' => $e->asunto,
            'audiencia' => $e->audiencia,
            'audiencia_nombre' => (EnviosCorreo::AUDIENCIAS[$e->audiencia] ?? $e->audiencia) . ($experiencia ? ": {$experiencia}" : ''),
            'total' => (int) $e->total,
            'enviados' => (int) ($conteo['enviado'] ?? 0),
            'pendientes' => (int) ($conteo['pendiente'] ?? 0),
            'errores' => (int) ($conteo['error'] ?? 0),
            'estado' => $e->estado,
            'estado_nombre' => $e->estado === 'completado' ? 'Completado' : 'Enviando…',
            'usuario_creacion_nombre' => $e->usuario_creacion_nombre,
            'fecha_creacion' => Carbon::parse($e->created_at)->format('Y-m-d H:i:s'),
            'fecha_modificacion' => Carbon::parse($e->updated_at)->format('Y-m-d H:i:s'),
        ];
    }

    private function consulta()
    {
        return DB::table('envios_correo')
            ->join('proveedores_turisticos', 'proveedores_turisticos.id', '=', 'envios_correo.proveedor_id')
            ->select('envios_correo.*', 'proveedores_turisticos.nombre_comercial as proveedor_nombre');
    }

    public function index(Request $request)
    {
        try {
            $datos = $this->aplicarAlcance($request->all(), 'proveedor_id', null);
            $query = $this->consulta()
                ->when($datos['proveedor_id'] ?? null, fn ($q, $v) => $q->where('envios_correo.proveedor_id', $v))
                ->when($datos['asunto'] ?? null, fn ($q, $v) => $q->where('envios_correo.asunto', 'like', "%{$v}%"))
                ->orderByDesc('envios_correo.id');
            $pagina = $query->paginate((int) ($datos['limite'] ?? 100));

            return response([
                'datos' => $pagina->getCollection()->map(fn ($e) => $this->formatear($e))->all(),
                'audiencias' => collect(EnviosCorreo::AUDIENCIAS)->map(fn ($n, $id) => ['id' => $id, 'nombre' => $n])->values(),
                'variables' => EnviosCorreo::VARIABLES,
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
        $envio = $this->consulta()->where('envios_correo.id', $id)->first();
        if (!$envio) {
            return response(get_response_body(['El envío no existe.']), Response::HTTP_NOT_FOUND);
        }
        if (!$this->enAlcance($envio->proveedor_id)) {
            return $this->respuestaSinAcceso();
        }
        $datos = $this->formatear($envio);
        $datos['texto'] = $envio->texto;
        $datos['destinatarios'] = DB::table('envio_correo_destinatarios')->where('envio_id', $id)->orderBy('nombre')
            ->get(['id', 'nombre', 'correo', 'estado', 'error', 'enviado_en']);

        return response($datos, Response::HTTP_OK);
    }

    /** Clientes del proveedor para la audiencia "Clientes seleccionados". */
    public function clientes(Request $request)
    {
        $datos = $this->aplicarAlcance($request->all(), 'proveedor_id', null);
        $validator = Validator::make($datos, ['proveedor_id' => 'required|integer|exists:proveedores_turisticos,id'], $this->mensajes());
        if ($validator->fails()) {
            return response(get_response_body(format_messages_validator($validator)), Response::HTTP_BAD_REQUEST);
        }
        return response(EnviosCorreo::clientes((int) $datos['proveedor_id']), Response::HTTP_OK);
    }

    /**
     * Imagen del contenido del correo (botón de imagen del editor). El navegador la reduce antes de subirla;
     * al enviar va incrustada en el correo (EnviosCorreo::incrustarImagenes).
     */
    public function subirImagen(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'imagen' => 'bail|required|file|mimes:jpg,jpeg,png,gif|max:2048|dimensions:max_width=5000,max_height=5000',
        ], [
            'imagen.mimes' => 'La imagen debe ser JPG, PNG o GIF.',
            'imagen.max' => 'La imagen no puede pesar más de 2 MB.',
            'imagen.uploaded' => 'La imagen no puede pesar más de 2 MB.',
            'imagen.dimensions' => 'La imagen es demasiado grande (máximo 5000 px por lado).',
        ]);
        if ($validator->fails()) {
            return response(get_response_body(format_messages_validator($validator)), Response::HTTP_BAD_REQUEST);
        }

        try {
            return response(['url' => EnviosCorreo::guardarImagen($request->file('imagen'))], Response::HTTP_CREATED);
        } catch (Exception $e) {
            return response(get_response_body(['No se pudo guardar la imagen.']), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** Cuántos clientes recibirán el correo y si se supera el límite diario. */
    public function resumen(Request $request)
    {
        $datos = $this->aplicarAlcance($request->all(), 'proveedor_id', null);
        if ($error = $this->validar($datos)) {
            return $error;
        }
        return response(EnviosCorreo::resumen((int) $datos['proveedor_id'], $datos['audiencia'], $this->filtro($datos)), Response::HTTP_OK);
    }

    public function store(Request $request)
    {
        $datos = $this->aplicarAlcance($request->all(), 'proveedor_id', null);
        if ($error = $this->validar($datos, true)) {
            return $error;
        }

        try {
            $id = EnviosCorreo::enviar([
                'proveedor_id' => (int) $datos['proveedor_id'],
                'asunto' => $datos['asunto'],
                'texto' => $datos['texto'],
                'audiencia' => $datos['audiencia'],
                'filtro' => $this->filtro($datos),
            ], Auth::user()->usuario());
        } catch (Exception $e) {
            return response(get_response_body(['No se pudo crear el envío: ' . $e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $total = DB::table('envios_correo')->where('id', $id)->value('total');
        return response(get_response_body([
            $total
                ? "Envío creado: {$total} correo(s) en cola. Se van enviando en el próximo minuto; revisa el avance en la lista."
                : 'No hay clientes con correo para esa selección: no se envió nada.',
            2,
        ], ['id' => $id]), Response::HTTP_CREATED);
    }

    private function validar(array $datos, $conMensaje = false)
    {
        $reglas = [
            'proveedor_id' => 'required|integer|exists:proveedores_turisticos,id',
            'audiencia' => 'required|in:' . implode(',', array_keys(EnviosCorreo::AUDIENCIAS)),
            'experiencia_id' => 'required_if:audiencia,experiencia|nullable|integer|exists:experiencias,id',
            'correos' => 'required_if:audiencia,seleccion|nullable|array',
            'correos.*' => 'email',
        ];
        if ($conMensaje) {
            $reglas += ['asunto' => 'required|string|max:150', 'texto' => 'required|string'];
        }
        $validator = Validator::make($datos, $reglas, $this->mensajes());
        if ($validator->fails()) {
            return response(get_response_body(format_messages_validator($validator)), Response::HTTP_BAD_REQUEST);
        }
        // La experiencia debe ser del proveedor del envío.
        if (($datos['audiencia'] ?? null) === 'experiencia'
            && (int) DB::table('experiencias')->where('id', $datos['experiencia_id'])->value('proveedor_id') !== (int) $datos['proveedor_id']) {
            return response(get_response_body(['La experiencia no pertenece al proveedor elegido.']), Response::HTTP_BAD_REQUEST);
        }
        return null;
    }

    private function mensajes()
    {
        return [
            'proveedor_id.required' => 'Elige el proveedor.',
            'experiencia_id.required_if' => 'Elige la experiencia.',
            'correos.required_if' => 'Elige al menos un cliente.',
        ];
    }

    private function filtro(array $datos): array
    {
        return array_filter([
            'experiencia_id' => ($datos['audiencia'] ?? null) === 'experiencia' ? (int) $datos['experiencia_id'] : null,
            'correos' => ($datos['audiencia'] ?? null) === 'seleccion' ? array_values($datos['correos'] ?? []) : null,
        ]);
    }
}
