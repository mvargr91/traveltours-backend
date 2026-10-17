<?php

namespace App\Http\Controllers\Resenas;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AlcancePorRol;
use Illuminate\Support\Facades\Validator;
use App\Models\Resenas\Resena;

/**
 * Reseñas de experiencias.
 *  - Administración: modera todas (pendiente, aprobada, rechazada).
 *  - Cliente (Mi Cuenta > Mis Reseñas): califica sus reservas finalizadas, una reseña por reserva; al
 *    crearla o editarla queda pendiente de moderación.
 *  - Proveedor: consulta las reseñas de sus experiencias (solo lectura).
 */
class ResenaController extends Controller
{
    use AlcancePorRol;

    // Estado en el que la reserva ya se puede calificar.
    private const ESTADO_RESERVA_CALIFICABLE = 'finalizada';

    public function index(Request $request)
    {
        try {
            $datos = $this->aplicarAlcance($request->all(), 'proveedor_id', 'usuario_id');
            $validator = Validator::make($datos, [
                'limite' => 'integer|between:1,500'
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if (isset($datos['ordenar_por'])) {
                $datos['ordenar_por'] = format_order_by_attributes($datos);
            }

            return response(Resena::obtenerColeccion($datos), Response::HTTP_OK);
        } catch (Exception $e) {
            return response($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $datos = $this->aplicarAlcance($request->all(), null, 'usuario_id');
            if ($this->esCliente()) {
                // El viajero no se aprueba su propia reseña; la experiencia sale de la reserva.
                $datos['estado'] = 'pendiente';
                unset($datos['experiencia_id']);
            }
            $validator = Validator::make($datos, [
                'usuario_id' => 'integer|required|exists:usuarios,id',
                'experiencia_id' => 'integer|required_without:reserva_id|nullable|exists:experiencias,id',
                'reserva_id' => ($this->esCliente() ? 'required' : 'nullable') . '|integer|exists:reservas,id',
                'calificacion' => 'integer|required|between:1,5',
                'comentario' => 'string|nullable|max:2000',
                'estado' => 'string|nullable|in:pendiente,aprobada,rechazada',
            ], [
                'reserva_id.required' => 'Indica la reserva que quieres calificar.',
                'calificacion.between' => 'La calificación debe ser de 1 a 5 estrellas.',
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if (!empty($datos['reserva_id'])) {
                $error = $this->validarReservaCalificable($datos);
                if ($error) {
                    DB::rollback();
                    return response(get_response_body([$error]), Response::HTTP_CONFLICT);
                }
                // La experiencia es la de la reserva, no la que envíe el navegador.
                $datos['experiencia_id'] = DB::table('reservas')->where('id', $datos['reserva_id'])->value('experiencia_id');
            }

            $resena = Resena::modificarOCrear($datos);

            if ($resena) {
                DB::commit();
                $mensaje = $this->esCliente()
                    ? '¡Gracias por tu reseña! La publicaremos después de revisarla.'
                    : 'La reseña ha sido creada.';
                return response(get_response_body([$mensaje, 2], $resena), Response::HTTP_CREATED);
            } else {
                DB::rollback();
                return response(get_response_body(['Ocurrió un error al intentar crear la reseña.']), Response::HTTP_CONFLICT);
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
                'id' => 'integer|required|exists:resenas,id'
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if (!$this->resenaEnAlcance($id)) {
                return $this->respuestaSinAcceso();
            }

            return response(Resena::cargar($id), Response::HTTP_OK);
        } catch (Exception $e) {
            return response(null, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            // Solo se cambian calificación, comentario y estado; la reseña sigue ligada a su reserva y autor.
            $datos = $request->only(['calificacion', 'comentario', 'estado']);
            // Si el cliente edita su reseña, vuelve a moderación.
            if ($this->esCliente()) {
                $datos['estado'] = 'pendiente';
            }
            $datos['id'] = $id;
            $validator = Validator::make($datos, [
                'id' => 'integer|required|exists:resenas,id',
                'calificacion' => 'integer|required|between:1,5',
                'comentario' => 'string|nullable|max:2000',
                'estado' => 'string|required|in:pendiente,aprobada,rechazada',
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if ($this->esProveedor() || !$this->resenaEnAlcance($id)) {
                return $this->respuestaSinAcceso();
            }

            $resena = Resena::modificarOCrear($datos);
            if ($resena) {
                DB::commit();
                return response(
                    get_response_body(['La reseña ha sido modificada.', 1], $resena),
                    Response::HTTP_OK
                );
            } else {
                DB::rollback();
                return response(get_response_body(['Ocurrió un error al intentar modificar la reseña.']), Response::HTTP_CONFLICT);
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
                'id' => 'integer|required|exists:resenas,id'
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if ($this->esProveedor() || !$this->resenaEnAlcance($id)) {
                return $this->respuestaSinAcceso();
            }

            $eliminado = Resena::eliminar($id);
            if ($eliminado) {
                DB::commit();
                return response(
                    get_response_body(['La reseña ha sido eliminada.', 3]),
                    Response::HTTP_OK
                );
            } else {
                DB::rollback();
                return response(get_response_body(['Ocurrió un error al intentar eliminar la reseña.']), Response::HTTP_CONFLICT);
            }
        } catch (Exception $e) {
            DB::rollback();
            return response(null, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // Cliente: las suyas. Proveedor: las de sus experiencias. Administración: todas.
    private function resenaEnAlcance($id)
    {
        $resena = DB::table('resenas')
            ->join('experiencias', 'experiencias.id', '=', 'resenas.experiencia_id')
            ->where('resenas.id', $id)
            ->first(['resenas.usuario_id', 'experiencias.proveedor_id']);
        return $resena && $this->enAlcance($resena->proveedor_id, $resena->usuario_id);
    }

    /**
     * Solo se califica una reserva finalizada, del mismo viajero de la reseña y que aún no tenga reseña.
     * Devuelve el mensaje de error o null.
     */
    private function validarReservaCalificable(array $datos)
    {
        $reserva = DB::table('reservas')->where('id', $datos['reserva_id'])->first(['usuario_id', 'estado']);
        if ((int) $reserva->usuario_id !== (int) $datos['usuario_id']) {
            return 'Solo puedes calificar tus propias reservas.';
        }
        if ($reserva->estado !== self::ESTADO_RESERVA_CALIFICABLE) {
            return 'Podrás calificar esta experiencia cuando la reserva esté finalizada.';
        }
        if (DB::table('resenas')->where('reserva_id', $datos['reserva_id'])->exists()) {
            return 'Ya calificaste esta reserva. Puedes editar tu reseña en Mis Reseñas.';
        }
        return null;
    }
}
