<?php

namespace App\Http\Controllers\Proveedores;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Services\ArchivosService;
use Illuminate\Support\Facades\Validator;
use App\Models\Proveedores\DocumentoProveedor;
use App\Http\Controllers\Concerns\AlcancePorRol;

class DocumentoProveedorController extends Controller
{
    use AlcancePorRol;

    /**
     * El proveedor (Mi Cuenta > Mis Documentos) solo ve y carga los de su negocio, y lo que sube queda
     * pendiente de revisión: el estado, la fecha de revisión y el motivo de rechazo los pone el administrador.
     */
    private function soloRevisionDelAdministrador(array $datos)
    {
        if ($this->esProveedor()) {
            $datos['estado'] = 'pendiente';
            $datos['motivo_rechazo'] = null;
            $datos['revisado_por'] = null;
            $datos['revisado_en'] = null;
        }
        return $datos;
    }

    public function index(Request $request)
    {
        try {
            $datos = $this->aplicarAlcance($request->all(), 'proveedor_id', null);
            $validator = Validator::make($datos, [
                'proveedor_id' => 'integer|required|exists:proveedores_turisticos,id',
                'limite' => 'integer|between:1,500'
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if ($request->ligera) {
                $documentos = DocumentoProveedor::obtenerColeccionLigera($datos);
            } else {
                if (isset($datos['ordenar_por'])) {
                    $datos['ordenar_por'] = format_order_by_attributes($datos);
                }
                $documentos = DocumentoProveedor::obtenerColeccion($datos);
            }
            return response($documentos, Response::HTTP_OK);
        } catch (Exception $e) {
            return response($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $datos = $this->soloRevisionDelAdministrador($this->aplicarAlcance($request->all(), 'proveedor_id', null));
            unset($datos['id']);
            $validator = Validator::make($datos, [
                'proveedor_id' => 'integer|required|exists:proveedores_turisticos,id',
                'tipo_documento' => 'string|required|max:100',
                'nombre_archivo' => 'string|nullable|max:255',
                'ruta_archivo' => 'string|required_without:archivo|nullable|max:255',
                'archivo' => ArchivosService::REGLA_DOCUMENTO . '|nullable',
                'estado' => 'string|nullable|max:30',
                'fecha_vencimiento' => 'date|nullable',
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if ($request->hasFile('archivo')) {
                $datos['nombre_archivo'] = $request->file('archivo')->getClientOriginalName();
                if (!isset($datos['id'])) {
                    $datos['ruta_archivo'] = $datos['ruta_archivo'] ?? '';
                } else {
                    unset($datos['ruta_archivo']);
                }
            } elseif (!isset($datos['id'])) {
                $datos['nombre_archivo'] = $datos['nombre_archivo'] ?? basename($datos['ruta_archivo'] ?? '');
            }
            $anterior = ArchivosService::rutaActual('documentos_proveedor', $datos['id'] ?? null, 'ruta_archivo');
            $documento = DocumentoProveedor::modificarOCrear($datos);
            $proveedor = DB::table('proveedores_turisticos')->where('id', $documento['proveedor_id'])->first(['id', 'nombre_comercial']);
            if (ArchivosService::adjuntar($request, 'documentos_proveedor', $documento['id'], 'ruta_archivo',
                ArchivosService::carpeta('proveedores', $proveedor->id, $proveedor->nombre_comercial, 'documentos/' . \Illuminate\Support\Str::slug($documento['tipo_documento'])),
                $anterior, 'archivo', ArchivosService::PRIVADO)) {
                $documento = DocumentoProveedor::cargar($documento['id']);
            }

            if ($documento) {
                DB::commit();
                ArchivosService::confirmar();
                return response(
                    get_response_body(['El documento ha sido creado.', 2], $documento),
                    Response::HTTP_CREATED
                );
            } else {
                DB::rollback();
                ArchivosService::revertir();
                return response(get_response_body(['Ocurrió un error al intentar crear el documento.']), Response::HTTP_CONFLICT);
            }
        } catch (Exception $e) {
            DB::rollback();
            ArchivosService::revertir();
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function show($id)
    {
        try {
            $datos['id'] = $id;
            $validator = Validator::make($datos, [
                'id' => 'integer|required|exists:documentos_proveedor,id'
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if (!$this->enAlcance(DB::table('documentos_proveedor')->where('id', $id)->value('proveedor_id'))) {
                return $this->respuestaSinAcceso();
            }

            return response(DocumentoProveedor::cargar($id), Response::HTTP_OK);
        } catch (Exception $e) {
            return response(null, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            if (!$this->enAlcance(DB::table('documentos_proveedor')->where('id', $id)->value('proveedor_id'))) {
                return $this->respuestaSinAcceso();
            }
            // Al reemplazar un documento el proveedor lo devuelve a revisión; no lo pasa a otro negocio.
            $datos = $this->soloRevisionDelAdministrador($request->all());
            unset($datos['proveedor_id']);
            $datos['id'] = $id;
            $validator = Validator::make($datos, [
                'id' => 'integer|required|exists:documentos_proveedor,id',
                'tipo_documento' => 'string|required|max:100',
                'estado' => 'string|required|max:30',
                'archivo' => ArchivosService::REGLA_DOCUMENTO . '|nullable',
                'fecha_vencimiento' => 'date|nullable',
                'motivo_rechazo' => 'string|nullable',
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if ($datos['estado'] !== 'pendiente') {
                $datos['revisado_por'] = auth()->user()?->usuario()?->id;
                $datos['revisado_en'] = now();
            }

            if ($request->hasFile('archivo')) {
                $datos['nombre_archivo'] = $request->file('archivo')->getClientOriginalName();
                if (!isset($datos['id'])) {
                    $datos['ruta_archivo'] = $datos['ruta_archivo'] ?? '';
                } else {
                    unset($datos['ruta_archivo']);
                }
            } elseif (!isset($datos['id'])) {
                $datos['nombre_archivo'] = $datos['nombre_archivo'] ?? basename($datos['ruta_archivo'] ?? '');
            }
            $anterior = ArchivosService::rutaActual('documentos_proveedor', $datos['id'] ?? null, 'ruta_archivo');
            $documento = DocumentoProveedor::modificarOCrear($datos);
            $proveedor = DB::table('proveedores_turisticos')->where('id', $documento['proveedor_id'])->first(['id', 'nombre_comercial']);
            if (ArchivosService::adjuntar($request, 'documentos_proveedor', $documento['id'], 'ruta_archivo',
                ArchivosService::carpeta('proveedores', $proveedor->id, $proveedor->nombre_comercial, 'documentos/' . \Illuminate\Support\Str::slug($documento['tipo_documento'])),
                $anterior, 'archivo', ArchivosService::PRIVADO)) {
                $documento = DocumentoProveedor::cargar($documento['id']);
            }
            if ($documento) {
                DB::commit();
                ArchivosService::confirmar();
                return response(
                    get_response_body(['El documento ha sido modificado.', 1], $documento),
                    Response::HTTP_OK
                );
            } else {
                DB::rollback();
                ArchivosService::revertir();
                return response(get_response_body(['Ocurrió un error al intentar modificar el documento.']), Response::HTTP_CONFLICT);
            }
        } catch (Exception $e) {
            DB::rollback();
            ArchivosService::revertir();
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $datos['id'] = $id;
            $validator = Validator::make($datos, [
                'id' => 'integer|required|exists:documentos_proveedor,id'
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            $documento = DB::table('documentos_proveedor')->where('id', $id)->first(['proveedor_id', 'estado']);
            if (!$this->enAlcance($documento->proveedor_id)) {
                return $this->respuestaSinAcceso();
            }
            if ($this->esProveedor() && $documento->estado === 'aprobado') {
                DB::rollback();
                return response(
                    get_response_body(['Este documento ya fue aprobado: para cambiarlo, sube una versión nueva.']),
                    Response::HTTP_CONFLICT
                );
            }

            ArchivosService::programarEliminacion(DB::table('documentos_proveedor')->where('id', $id)->value('ruta_archivo'), ArchivosService::PRIVADO);
            $eliminado = DocumentoProveedor::eliminar($id);
            if ($eliminado) {
                DB::commit();
                ArchivosService::confirmar();
                return response(
                    get_response_body(['El documento ha sido eliminado.', 3]),
                    Response::HTTP_OK
                );
            } else {
                DB::rollback();
                ArchivosService::revertir();
                return response(get_response_body(['Ocurrió un error al intentar eliminar el documento.']), Response::HTTP_CONFLICT);
            }
        } catch (Exception $e) {
            DB::rollback();
            ArchivosService::revertir();
            return response(null, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Descarga el archivo del documento. Los documentos se guardan en el disco privado,
     * así que solo se entregan con sesión: al administrador o al proveedor dueño.
     */
    public function archivo($id)
    {
        $documento = DB::table('documentos_proveedor')->where('id', $id)->first(['proveedor_id', 'nombre_archivo', 'ruta_archivo']);
        if (!$documento) {
            return response(get_response_body(['El documento no existe.']), Response::HTTP_NOT_FOUND);
        }
        if (!$this->enAlcance($documento->proveedor_id)) {
            return $this->respuestaSinAcceso();
        }
        // Documentos registrados antes como URL externa.
        if (!ArchivosService::esLocal($documento->ruta_archivo)) {
            return response(['url' => $documento->ruta_archivo], Response::HTTP_OK);
        }
        $ruta = ArchivosService::rutaPrivada($documento->ruta_archivo);
        if (!$ruta) {
            return response(get_response_body(['El archivo no se encuentra en el servidor.']), Response::HTTP_NOT_FOUND);
        }
        return response()->download($ruta, $documento->nombre_archivo ?: basename($ruta));
    }
}
