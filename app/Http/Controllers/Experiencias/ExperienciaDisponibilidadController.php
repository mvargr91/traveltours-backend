<?php

namespace App\Http\Controllers\Experiencias;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AlcancePorRol;
use Illuminate\Support\Facades\Validator;
use App\Models\Experiencias\ExperienciaDisponibilidad;
use App\Services\GeneradorDisponibilidad;

class ExperienciaDisponibilidadController extends Controller
{
    use AlcancePorRol;

    public function index(Request $request)
    {
        try {
            $datos = $request->all();
            $validator = Validator::make($datos, [
                'experiencia_id' => 'integer|required|exists:experiencias,id',
                'fecha_desde' => 'date|nullable',
                'fecha_hasta' => 'date|nullable',
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if (!$this->experienciaEnAlcance($datos['experiencia_id'])) {
                return $this->respuestaSinAcceso();
            }

            return response(ExperienciaDisponibilidad::obtenerColeccion($datos), Response::HTTP_OK);
        } catch (Exception $e) {
            return response($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** Crea las fechas que falten de todas las experiencias con horario semanal (solo administración). */
    public function generar()
    {
        try {
            $creadas = GeneradorDisponibilidad::generarTodas();
            $dias = GeneradorDisponibilidad::dias();
            $mensaje = $creadas > 0
                ? "Se crearon {$creadas} fecha(s) para los próximos {$dias} días."
                : "No había fechas por crear: los próximos {$dias} días ya están completos.";

            return response(get_response_body([$mensaje, 1], ['creadas' => $creadas]), Response::HTTP_OK);
        } catch (Exception $e) {
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // Varias salidas en un solo paso (misma hora y capacidad), cada una con su fecha de salida y de regreso.
    public function lote(Request $request)
    {
        DB::beginTransaction();
        try {
            $datos = $request->all();
            $validator = Validator::make($datos, [
                'experiencia_id' => 'integer|required|exists:experiencias,id',
                'hora_inicio' => 'date_format:H:i|required',
                'hora_fin' => 'date_format:H:i|nullable',
                'capacidad' => 'integer|required|min:1',
                'salidas' => 'array|required|min:1|max:200',
                'salidas.*.fecha' => 'date_format:Y-m-d|required',
                'salidas.*.fecha_fin' => 'date_format:Y-m-d|nullable|after_or_equal:salidas.*.fecha',
            ], [
                'salidas.required' => 'Marca al menos una salida en el calendario.',
                'salidas.*.fecha_fin.after_or_equal' => 'El regreso de una salida no puede ser anterior a su fecha de salida.',
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if (!$this->experienciaEnAlcance($datos['experiencia_id'])) {
                return $this->respuestaSinAcceso();
            }

            [$creadas, $omitidas] = ExperienciaDisponibilidad::crearLote(
                $datos['experiencia_id'], $datos['salidas'], $datos['hora_inicio'], $datos['hora_fin'] ?? null, $datos['capacidad']
            );
            DB::commit();

            $mensaje = "Se crearon {$creadas} salida(s)." . ($omitidas ? " {$omitidas} ya existían a esa hora y se dejaron como estaban." : '');
            return response(get_response_body([$mensaje, 2], ['creadas' => $creadas, 'omitidas' => $omitidas]), Response::HTTP_CREATED);
        } catch (Exception $e) {
            DB::rollback();
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $datos = $request->all();
            $validator = Validator::make($datos, [
                'experiencia_id' => 'integer|required|exists:experiencias,id',
                'fecha' => 'date|required',
                'fecha_fin' => 'date|nullable|after_or_equal:fecha',
                'hora_inicio' => 'date_format:H:i|required',
                'hora_fin' => 'date_format:H:i|nullable',
                'capacidad' => 'integer|required',
                'cupos_disponibles' => 'integer|nullable',
                'estado' => 'string|nullable|max:30',
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if (!$this->experienciaEnAlcance($datos['experiencia_id'])) {
                return $this->respuestaSinAcceso();
            }

            $disponibilidad = ExperienciaDisponibilidad::modificarOCrear($datos);
            DB::commit();
            return response(
                get_response_body(['La disponibilidad ha sido creada.', 2], $disponibilidad),
                Response::HTTP_CREATED
            );
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
                'id' => 'integer|required|exists:experiencia_disponibilidad,id'
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if (!$this->registroDeExperienciaEnAlcance('experiencia_disponibilidad', $id)) {
                return $this->respuestaSinAcceso();
            }

            return response(ExperienciaDisponibilidad::cargar($id), Response::HTTP_OK);
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
                'id' => 'integer|required|exists:experiencia_disponibilidad,id',
                'fecha' => 'date|required',
                'fecha_fin' => 'date|nullable|after_or_equal:fecha',
                'hora_inicio' => 'date_format:H:i|required',
                'hora_fin' => 'date_format:H:i|nullable',
                'capacidad' => 'integer|required',
                'cupos_disponibles' => 'integer|required',
                'estado' => 'string|nullable|max:30',
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if (!$this->registroDeExperienciaEnAlcance('experiencia_disponibilidad', $id)) {
                return $this->respuestaSinAcceso();
            }

            $disponibilidad = ExperienciaDisponibilidad::modificarOCrear($datos);
            DB::commit();
            return response(
                get_response_body(['La disponibilidad ha sido modificada.', 1], $disponibilidad),
                Response::HTTP_OK
            );
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
                'id' => 'integer|required|exists:experiencia_disponibilidad,id'
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if (!$this->registroDeExperienciaEnAlcance('experiencia_disponibilidad', $id)) {
                return $this->respuestaSinAcceso();
            }

            $eliminado = ExperienciaDisponibilidad::eliminar($id);
            if ($eliminado) {
                DB::commit();
                return response(
                    get_response_body(['La disponibilidad ha sido eliminada.', 3]),
                    Response::HTTP_OK
                );
            } else {
                DB::rollback();
                return response(get_response_body(['Ocurrió un error al intentar eliminar la disponibilidad.']), Response::HTTP_CONFLICT);
            }
        } catch (Exception $e) {
            DB::rollback();
            return response(null, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
