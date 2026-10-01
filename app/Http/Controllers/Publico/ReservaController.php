<?php

namespace App\Http\Controllers\Publico;

use Exception;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Models\Reservas\Reserva;
use App\Models\Seguridad\AuditoriaTabla;
use App\Services\NotificadorReservas;

/**
 * Reservas de invitados desde el portal (sin sesión).
 * El cliente HTTP solo elige la fecha, las personas y sus datos de contacto: experiencia, proveedor,
 * hora y valor se toman de la base de datos. La reserva queda "pendiente" hasta que el proveedor la acepte.
 */
class ReservaController extends Controller
{
    private const RESPONSABLE = 'Reserva invitado';

    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $datos = $request->all();
            $validator = Validator::make($datos, [
                'experiencia_id' => 'integer|required',
                'disponibilidad_id' => 'integer|required',
                'cantidad_personas' => 'integer|required|min:1|max:100',
                'nombre' => 'string|required|max:150',
                'correo' => 'email|required|max:150',
                'telefono' => ['string', 'required', 'max:30', 'regex:/^[+]?[0-9\s-]*$/'],
                'observaciones' => 'string|nullable|max:1000',
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            $experiencia = DB::table('experiencias')
                ->where('id', $datos['experiencia_id'])
                ->where('estado', 'publicada')
                ->select('id', 'proveedor_id', 'precio_desde', 'idioma')
                ->first();

            $disponibilidad = $experiencia
                ? DB::table('experiencia_disponibilidad')
                    ->where('id', $datos['disponibilidad_id'])
                    ->where('experiencia_id', $experiencia->id)
                    ->where('estado', 'disponible')
                    ->where('fecha', '>=', Carbon::now()->toDateString())
                    ->select('id', 'fecha', 'hora_inicio', 'cupos_disponibles')
                    ->first()
                : null;

            if (!$disponibilidad) {
                return response(
                    get_response_body(['La fecha elegida ya no está disponible. Elige otra.']),
                    Response::HTTP_CONFLICT
                );
            }
            if ($datos['cantidad_personas'] > $disponibilidad->cupos_disponibles) {
                return response(
                    get_response_body(['Solo quedan ' . $disponibilidad->cupos_disponibles . ' cupos para esa fecha.']),
                    Response::HTTP_CONFLICT
                );
            }

            $reserva = Reserva::create([
                'usuario_id' => null,
                'experiencia_id' => $experiencia->id,
                'proveedor_id' => $experiencia->proveedor_id,
                'disponibilidad_id' => $disponibilidad->id,
                'codigo_reserva' => strtoupper('RES-' . uniqid()),
                'fecha' => $disponibilidad->fecha,
                'hora_inicio' => $disponibilidad->hora_inicio,
                'nombre' => $datos['nombre'],
                'correo' => $datos['correo'],
                'telefono' => $datos['telefono'],
                'cantidad_personas' => $datos['cantidad_personas'],
                'cantidad_chicos' => 0,
                'idioma' => $experiencia->idioma,
                'valor_total' => Reserva::calcularTotal($experiencia->id, $datos['cantidad_personas']),
                'estado' => Reserva::estadoInicial($experiencia->id),
                'observaciones' => $datos['observaciones'] ?? null,
                'usuario_creacion_id' => 0,
                'usuario_creacion_nombre' => self::RESPONSABLE,
                'usuario_modificacion_id' => 0,
                'usuario_modificacion_nombre' => self::RESPONSABLE,
            ]);

            AuditoriaTabla::crear([
                'externo' => true,
                'id_recurso' => $reserva->id,
                'nombre_recurso' => Reserva::class,
                'descripcion_recurso' => $reserva->codigo_reserva,
                'accion' => 'Crear',
                'recurso_original' => $reserva->toJson(),
            ]);

            DB::commit();
            NotificadorReservas::reservaCreada($reserva->id);
            return response(
                get_response_body(
                    ['Recibimos tu solicitud de reserva.', 2],
                    self::resumen($reserva->codigo_reserva)
                ),
                Response::HTTP_CREATED
            );
        } catch (Exception $e) {
            DB::rollback();
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // Consulta por código + correo: sin el correo exacto no se revela si el código existe.
    public function show(Request $request, $codigo)
    {
        try {
            $datos = array_merge($request->all(), ['codigo' => $codigo]);
            $validator = Validator::make($datos, [
                'codigo' => 'string|required|max:30',
                'correo' => 'email|required|max:150',
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            $resumen = self::resumen(strtoupper(trim($codigo)), $datos['correo']);
            if (!$resumen) {
                return response(
                    get_response_body(['No encontramos una reserva con ese código y correo.']),
                    Response::HTTP_NOT_FOUND
                );
            }
            return response()->json($resumen, Response::HTTP_OK);
        } catch (Exception $e) {
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // Datos que ve el viajero: sin notas internas ni datos de auditoría.
    private static function resumen($codigo, $correo = null)
    {
        $query = DB::table('reservas')
            ->join('experiencias', 'experiencias.id', '=', 'reservas.experiencia_id')
            ->join('proveedores_turisticos', 'proveedores_turisticos.id', '=', 'reservas.proveedor_id')
            ->where('reservas.codigo_reserva', $codigo)
            ->select(
                'reservas.codigo_reserva',
                'reservas.estado',
                'reservas.fecha',
                'reservas.hora_inicio',
                'reservas.cantidad_personas',
                'reservas.valor_total',
                'reservas.nombre',
                'reservas.correo',
                'experiencias.nombre as experiencia_nombre',
                'experiencias.slug as experiencia_slug',
                'experiencias.punto_encuentro',
                'proveedores_turisticos.nombre_comercial as proveedor_nombre',
                'proveedores_turisticos.telefono as proveedor_telefono',
                'reservas.created_at as fecha_creacion',
            );

        if ($correo !== null) {
            $query->whereRaw('LOWER(reservas.correo) = ?', [strtolower(trim($correo))]);
        }

        return $query->first();
    }
}
