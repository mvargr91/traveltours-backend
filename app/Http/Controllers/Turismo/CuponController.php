<?php

namespace App\Http\Controllers\Turismo;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AlcancePorRol;
use Illuminate\Support\Facades\Validator;
use App\Models\Turismo\Cupon;

/**
 * Cupones de descuento.
 *  - Administración: ve todos; crea cupones de la plataforma (sin proveedor) o de un proveedor.
 *  - Proveedor: solo los suyos (el proveedor se toma del token) y solo para sus experiencias.
 */
class CuponController extends Controller
{
    use AlcancePorRol;

    public function index(Request $request)
    {
        try {
            $datos = $this->aplicarAlcance($request->all(), 'proveedor_id', null);
            if (!$request->ligera) {
                $validator = Validator::make($datos, [
                    'limite' => 'integer|between:1,500'
                ]);

                if ($validator->fails()) {
                    return response(
                        get_response_body(format_messages_validator($validator)),
                        Response::HTTP_BAD_REQUEST
                    );
                }
            }

            if ($request->ligera) {
                $cupones = Cupon::obtenerColeccionLigera($datos);
            } else {
                if (isset($datos['ordenar_por'])) {
                    $datos['ordenar_por'] = format_order_by_attributes($datos);
                }
                $cupones = Cupon::obtenerColeccion($datos);
            }
            return response($cupones, Response::HTTP_OK);
        } catch (Exception $e) {
            return response($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $datos = $this->prepararDatos($request->all());
            $validator = Validator::make($datos, $this->reglas(), $this->mensajes());

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }
            if ($error = $this->validarExperiencias($datos)) {
                return response(get_response_body([$error]), Response::HTTP_BAD_REQUEST);
            }

            $cupon = Cupon::modificarOCrear($datos);
            if ($cupon) {
                DB::commit();
                return response(
                    get_response_body(['El cupón ha sido creado.', 2], $cupon),
                    Response::HTTP_CREATED
                );
            } else {
                DB::rollback();
                return response(get_response_body(['Ocurrió un error al intentar crear el cupón.']), Response::HTTP_CONFLICT);
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
                'id' => 'integer|required|exists:cupones,id'
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }
            if (!$this->cuponEnAlcance($id)) {
                return $this->respuestaSinAcceso();
            }

            return response(Cupon::cargar($id), Response::HTTP_OK);
        } catch (Exception $e) {
            return response(null, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $datos = $this->prepararDatos($request->all());
            $datos['id'] = $id;
            $validator = Validator::make($datos, array_merge($this->reglas($id), [
                'id' => 'integer|required|exists:cupones,id',
            ]), $this->mensajes());

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }
            if (!$this->cuponEnAlcance($id)) {
                return $this->respuestaSinAcceso();
            }
            if ($error = $this->validarExperiencias($datos)) {
                return response(get_response_body([$error]), Response::HTTP_BAD_REQUEST);
            }

            $cupon = Cupon::modificarOCrear($datos);
            if ($cupon) {
                DB::commit();
                return response(
                    get_response_body(['El cupón ha sido modificado.', 1], $cupon),
                    Response::HTTP_OK
                );
            } else {
                DB::rollback();
                return response(get_response_body(['Ocurrió un error al intentar modificar el cupón.']), Response::HTTP_CONFLICT);
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
                'id' => 'integer|required|exists:cupones,id'
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }
            if (!$this->cuponEnAlcance($id)) {
                return $this->respuestaSinAcceso();
            }
            // Las reservas guardan el cupón que usaron: un cupón usado se inactiva, no se elimina.
            if (DB::table('reservas')->where('cupon_id', $id)->exists()) {
                return response(
                    get_response_body(['El cupón ya se usó en reservas. Inactívalo en lugar de eliminarlo.']),
                    Response::HTTP_CONFLICT
                );
            }

            $eliminado = Cupon::eliminar($id);
            if ($eliminado) {
                DB::commit();
                return response(
                    get_response_body(['El cupón ha sido eliminado.', 3]),
                    Response::HTTP_OK
                );
            } else {
                DB::rollback();
                return response(get_response_body(['Ocurrió un error al intentar eliminar el cupón.']), Response::HTTP_CONFLICT);
            }
        } catch (Exception $e) {
            DB::rollback();
            return response(null, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // El código se compara en mayúsculas y el proveedor del cupón lo fija el token si es proveedor.
    private function prepararDatos(array $datos)
    {
        if (isset($datos['codigo'])) {
            $datos['codigo'] = Cupon::normalizarCodigo($datos['codigo']);
        }
        if (array_key_exists('proveedor_id', $datos) && $datos['proveedor_id'] === '') {
            $datos['proveedor_id'] = null;
        }
        return $this->aplicarAlcance($datos, 'proveedor_id', null);
    }

    private function reglas($id = null)
    {
        return [
            'codigo' => ['string', 'required', 'min:3', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('cupones', 'codigo')->ignore($id)],
            'proveedor_id' => 'integer|nullable|exists:proveedores_turisticos,id',
            'tipo' => 'string|required|in:porcentaje,valor_fijo',
            'nombre' => 'string|required|max:150',
            'descripcion' => 'string|nullable',
            'cantidad' => 'integer|nullable|min:1',
            'descuento' => 'numeric|nullable|required_if:tipo,porcentaje|gt:0|max:100',
            'valor' => 'numeric|nullable|required_if:tipo,valor_fijo|gt:0',
            'fecha_inicio' => 'date|required',
            'fecha_fin' => 'date|required|after_or_equal:fecha_inicio',
            'estado' => 'boolean|required',
            'experiencias' => 'array|nullable',
            'experiencias.*' => 'integer|exists:experiencias,id',
        ];
    }

    private function mensajes()
    {
        return [
            'codigo.regex' => 'El código solo puede tener letras, números, guion y guion bajo (sin espacios).',
            'codigo.unique' => 'Ya existe un cupón con ese código.',
            'descuento.required_if' => 'Indica el porcentaje de descuento.',
            'valor.required_if' => 'Indica el valor del descuento.',
        ];
    }

    // Un cupón de proveedor solo puede limitarse a experiencias de ese proveedor.
    private function validarExperiencias(array $datos)
    {
        $experiencias = $datos['experiencias'] ?? [];
        if (!$experiencias || empty($datos['proveedor_id'])) {
            return null;
        }
        $ajenas = DB::table('experiencias')
            ->whereIn('id', $experiencias)
            ->where('proveedor_id', '!=', $datos['proveedor_id'])
            ->exists();

        return $ajenas ? 'Las experiencias del cupón deben ser del mismo proveedor.' : null;
    }

    // El proveedor no ve ni modifica los cupones de la plataforma ni los de otros proveedores.
    private function cuponEnAlcance($id)
    {
        if (!$this->esProveedor()) {
            return !$this->esCliente();
        }
        return $this->enAlcance(DB::table('cupones')->where('id', $id)->value('proveedor_id'));
    }
}
