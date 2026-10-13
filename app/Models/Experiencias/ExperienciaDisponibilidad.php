<?php

namespace App\Models\Experiencias;

use Exception;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ExperienciaDisponibilidad extends Model
{
    use HasFactory;

    protected $table = 'experiencia_disponibilidad';

    protected $fillable = [
        'experiencia_id',
        'horario_id',
        'fecha',
        'fecha_fin',
        'hora_inicio',
        'hora_fin',
        'capacidad',
        'cupos_disponibles',
        'estado',
    ];

    public static function obtenerColeccion($dto)
    {
        $query = DB::table('experiencia_disponibilidad')
            ->select(
                'experiencia_disponibilidad.id',
                'experiencia_disponibilidad.experiencia_id',
                'experiencia_disponibilidad.horario_id',
                'experiencia_disponibilidad.fecha',
                'experiencia_disponibilidad.fecha_fin',
                'experiencia_disponibilidad.hora_inicio',
                'experiencia_disponibilidad.hora_fin',
                'experiencia_disponibilidad.capacidad',
                'experiencia_disponibilidad.cupos_disponibles',
                'experiencia_disponibilidad.estado',
            )
            ->where('experiencia_disponibilidad.experiencia_id', $dto['experiencia_id']);

        if (isset($dto['fecha_desde'])) {
            $query->where('experiencia_disponibilidad.fecha', '>=', $dto['fecha_desde']);
        }
        if (isset($dto['fecha_hasta'])) {
            $query->where('experiencia_disponibilidad.fecha', '<=', $dto['fecha_hasta']);
        }

        $query->orderBy('experiencia_disponibilidad.fecha', 'asc');

        return $query->get();
    }

    public static function cargar($id)
    {
        return ExperienciaDisponibilidad::find($id);
    }

    public static function modificarOCrear($dto)
    {
        $disponibilidad = isset($dto['id']) ? ExperienciaDisponibilidad::find($dto['id']) : new ExperienciaDisponibilidad();

        if (!isset($dto['id']) && !isset($dto['cupos_disponibles'])) {
            $dto['cupos_disponibles'] = $dto['capacidad'];
        }

        $disponibilidad->fill($dto);
        $guardado = $disponibilidad->save();
        if (!$guardado) {
            throw new Exception('Ocurrió un error al intentar guardar la disponibilidad de la experiencia.');
        }

        return $disponibilidad;
    }

    /**
     * Crea varias salidas de una vez con la misma hora y capacidad. $salidas: [['fecha' => ..., 'fecha_fin' => ...]].
     * Las que ya existen (misma fecha y hora) se dejan como están. Devuelve [creadas, omitidas].
     */
    public static function crearLote($experienciaId, array $salidas, $horaInicio, $horaFin, $capacidad)
    {
        $existentes = DB::table('experiencia_disponibilidad')
            ->where('experiencia_id', $experienciaId)
            ->whereIn('fecha', array_column($salidas, 'fecha'))
            ->get(['fecha', 'hora_inicio'])
            ->mapWithKeys(fn ($fila) => [$fila->fecha . '|' . substr($fila->hora_inicio, 0, 5) => true])
            ->all();

        $nuevas = [];
        foreach ($salidas as $salida) {
            $clave = $salida['fecha'] . '|' . $horaInicio;
            if (isset($existentes[$clave])) {
                continue;
            }
            $existentes[$clave] = true;
            $fin = $salida['fecha_fin'] ?? null;
            $nuevas[] = [
                'experiencia_id' => $experienciaId,
                'fecha' => $salida['fecha'],
                'fecha_fin' => $fin && $fin > $salida['fecha'] ? $fin : null,
                'hora_inicio' => $horaInicio,
                'hora_fin' => $horaFin,
                'capacidad' => $capacidad,
                'cupos_disponibles' => $capacidad,
                'estado' => 'disponible',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        if ($nuevas) {
            DB::table('experiencia_disponibilidad')->insert($nuevas);
        }

        return [count($nuevas), count($salidas) - count($nuevas)];
    }

    public static function eliminar($id)
    {
        $disponibilidad = ExperienciaDisponibilidad::find($id);

        // Una fecha futura generada desde un horario volvería a crearse sola: se deja bloqueada (cancelada).
        if ($disponibilidad->horario_id && $disponibilidad->fecha >= Carbon::today()->toDateString()) {
            $disponibilidad->estado = 'cancelado';
            return $disponibilidad->save();
        }

        return $disponibilidad->delete();
    }
}
