<?php

namespace App\Http\Controllers\Proveedores;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Models\Proveedores\DocumentoProveedor;

class DocumentoProveedorController extends Controller
{
    public function index(Request $request)
    {
        try {
            $datos = $request->all();
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
            $datos = $request->all();
            $validator = Validator::make($datos, [
                'proveedor_id' => 'integer|required|exists:proveedores_turisticos,id',
                'tipo_documento' => 'string|required|max:100',
                'nombre_archivo' => 'string|required|max:255',
                'ruta_archivo' => 'string|required|max:255',
                'estado' => 'string|nullable|max:30',
                'fecha_vencimiento' => 'date|nullable',
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            $documento = DocumentoProveedor::modificarOCrear($datos);

            if ($documento) {
                DB::commit();
                return response(
                    get_response_body(['El documento ha sido creado.', 2], $documento),
                    Response::HTTP_CREATED
                );
            } else {
                DB::rollback();
                return response(get_response_body(['Ocurrió un error al intentar crear el documento.']), Response::HTTP_CONFLICT);
            }
        } catch (Exception $e) {
            DB::rollback();
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

            return response(DocumentoProveedor::cargar($id), Response::HTTP_OK);
        } catch (Exception $e) {
            return response(null, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $datos = $request->all();
            $datos['id'] = $id;
            $validator = Validator::make($datos, [
                'id' => 'integer|required|exists:documentos_proveedor,id',
                'tipo_documento' => 'string|required|max:100',
                'estado' => 'string|required|max:30',
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
                $datos['revisado_por'] = $datos['revisado_por'] ?? null;
                $datos['revisado_en'] = now();
            }

            $documento = DocumentoProveedor::modificarOCrear($datos);
            if ($documento) {
                DB::commit();
                return response(
                    get_response_body(['El documento ha sido modificado.', 1], $documento),
                    Response::HTTP_OK
                );
            } else {
                DB::rollback();
                return response(get_response_body(['Ocurrió un error al intentar modificar el documento.']), Response::HTTP_CONFLICT);
            }
        } catch (Exception $e) {
            DB::rollback();
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

            $eliminado = DocumentoProveedor::eliminar($id);
            if ($eliminado) {
                DB::commit();
                return response(
                    get_response_body(['El documento ha sido eliminado.', 3]),
                    Response::HTTP_OK
                );
            } else {
                DB::rollback();
                return response(get_response_body(['Ocurrió un error al intentar eliminar el documento.']), Response::HTTP_CONFLICT);
            }
        } catch (Exception $e) {
            DB::rollback();
            return response(null, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
