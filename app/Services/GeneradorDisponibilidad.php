<?php

namespace App\Services;

use Throwable;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\Parametrizacion\ParametroConstante;

/**
 * Crea las fechas de disponibilidad de una experiencia a partir de sus horarios semanales, para que un
 * tour recurrente no obligue a cargar cada fecha a mano.
 *
 *  - Mantiene siempre creadas las fechas de los próximos DIAS_DISPONIBILIDAD días (parámetro, 90 por defecto).
 *  - Un horario con temporada (vigente_desde / vigente_hasta) solo genera dentro de ella; si tiene fecha final
 *    se crean de una vez todas las fechas hasta ese día, aunque falten meses (tours de temporada que se venden
 *    con anticipación).
 *  - Los cupos de cada fecha salen de la capacidad del horario o, si no tiene, de la capacidad máxima de la
 *    experiencia. Sin ninguna de las dos no se generan fechas (no habría cómo controlar cupos).
 *  - Nunca duplica: si ya hay una fecha a esa hora (creada a mano o generada) la deja como está.
 *  - Una fecha generada que el proveedor pasa a "cancelado" bloquea ese día: no se vuelve a crear.
 *  - Al cambiar o quitar un horario, sus fechas futuras sin reservas se rehacen; las que ya tienen reservas
 *    o cupos tomados se conservan.
 *
 * Corre al guardar un horario y, una vez al día, con la primera visita al portal (generarSiHaceFalta); no
 * necesita cron. El comando disponibilidad:generar permite lanzarlo a mano.
 */
class GeneradorDisponibilidad
{
    public const DIAS_POR_DEFECTO = 90;
    private const DIAS_MAXIMO = 365;
    // Tope de una temporada con fecha final: no se crean fechas a más de dos años.
    private const DIAS_TEMPORADA_MAXIMO = 730;

    public static function dias(): int
    {
        $parametros = ParametroConstante::cargarParametros();
        $dias = (int) ($parametros['DIAS_DISPONIBILIDAD'] ?? 0);

        return $dias > 0 ? min($dias, self::DIAS_MAXIMO) : self::DIAS_POR_DEFECTO;
    }

    /** Crea las fechas que falten de la experiencia. Devuelve cuántas creó. */
    public static function generar($experienciaId): int
    {
        $horarios = DB::table('experiencia_horarios')
            ->where('experiencia_id', $experienciaId)
            ->where('estado', 1)
            ->get();
        if ($horarios->isEmpty()) {
            return 0;
        }

        $capacidadExperiencia = (int) DB::table('experiencias')->where('id', $experienciaId)->value('capacidad_maxima');
        $ahora = Carbon::now();
        $hoy = $ahora->copy()->startOfDay();
        $finVentana = $hoy->copy()->addDays(self::dias());
        $tope = $hoy->copy()->addDays(self::DIAS_TEMPORADA_MAXIMO);

        // Rango de cada horario: su temporada completa si tiene fecha final; si no, la ventana.
        $rangos = [];
        $fin = $finVentana->copy();
        foreach ($horarios as $horario) {
            $desde = $horario->vigente_desde ? Carbon::parse($horario->vigente_desde)->max($hoy) : $hoy->copy();
            $hasta = $horario->vigente_hasta ? Carbon::parse($horario->vigente_hasta)->min($tope) : $finVentana->copy();
            $rangos[$horario->id] = [$desde->toDateString(), $hasta->toDateString()];
            $fin = $fin->max($hasta);
        }

        $existentes = DB::table('experiencia_disponibilidad')
            ->where('experiencia_id', $experienciaId)
            ->whereBetween('fecha', [$hoy->toDateString(), $fin->toDateString()])
            ->select('fecha', 'hora_inicio', 'horario_id', 'estado')
            ->get();

        $ocupadas = [];   // "fecha|HH:MM": ya hay una fecha a esa hora
        $bloqueadas = []; // "fecha|horario": el proveedor canceló ese día de ese horario
        foreach ($existentes as $fila) {
            $ocupadas[$fila->fecha . '|' . substr($fila->hora_inicio, 0, 5)] = true;
            if ($fila->estado === 'cancelado' && $fila->horario_id) {
                $bloqueadas[$fila->fecha . '|' . $fila->horario_id] = true;
            }
        }

        $nuevas = [];
        for ($dia = $hoy->copy(); $dia->lte($fin); $dia->addDay()) {
            $fecha = $dia->toDateString();
            foreach ($horarios as $horario) {
                // dia_semana: 0 = domingo ... 6 = sábado, igual que Carbon::dayOfWeek.
                [$desde, $hasta] = $rangos[$horario->id];
                if ((int) $horario->dia_semana !== $dia->dayOfWeek || $fecha < $desde || $fecha > $hasta) {
                    continue;
                }
                $capacidad = (int) $horario->capacidad ?: $capacidadExperiencia;
                $hora = substr($horario->hora_inicio, 0, 5);
                $yaPaso = $dia->isSameDay($ahora) && $hora <= $ahora->format('H:i');
                if ($capacidad <= 0 || $yaPaso || isset($ocupadas["{$fecha}|{$hora}"]) || isset($bloqueadas["{$fecha}|{$horario->id}"])) {
                    continue;
                }
                $ocupadas["{$fecha}|{$hora}"] = true;
                $nuevas[] = [
                    'experiencia_id' => $experienciaId,
                    'horario_id' => $horario->id,
                    'fecha' => $fecha,
                    'hora_inicio' => $horario->hora_inicio,
                    'hora_fin' => $horario->hora_fin,
                    'capacidad' => $capacidad,
                    'cupos_disponibles' => $capacidad,
                    'estado' => 'disponible',
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }
        }

        foreach (array_chunk($nuevas, 200) as $bloque) {
            DB::table('experiencia_disponibilidad')->insert($bloque);
        }

        return count($nuevas);
    }

    /**
     * Rehace las fechas futuras de un horario que cambió o se va a quitar: borra las que nadie ha
     * reservado (y no están bloqueadas) y vuelve a generar. Llamar antes de eliminar el horario.
     */
    public static function sincronizar($experienciaId, $horarioId): int
    {
        DB::table('experiencia_disponibilidad')
            ->where('horario_id', $horarioId)
            ->where('fecha', '>=', Carbon::today()->toDateString())
            ->where('estado', '!=', 'cancelado')
            ->whereColumn('cupos_disponibles', 'capacidad')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('reservas')
                    ->whereColumn('reservas.disponibilidad_id', 'experiencia_disponibilidad.id');
            })
            ->delete();

        return self::generar($experienciaId);
    }

    /** Todas las experiencias con horarios activos. Devuelve cuántas fechas creó. */
    public static function generarTodas(): int
    {
        $creadas = 0;
        $experiencias = DB::table('experiencia_horarios')->where('estado', 1)->distinct()->pluck('experiencia_id');
        foreach ($experiencias as $experienciaId) {
            $creadas += self::generar($experienciaId);
        }

        return $creadas;
    }

    /**
     * Para el portal: genera una sola vez al día, con la primera visita. Así la ventana avanza sola sin
     * cron. Nunca interrumpe la página: un fallo queda en el log.
     */
    public static function generarSiHaceFalta(): void
    {
        try {
            if (Cache::add('disponibilidad-generada-' . Carbon::today()->toDateString(), true, Carbon::now()->endOfDay())) {
                self::generarTodas();
            }
        } catch (Throwable $e) {
            Log::warning('No se pudo generar la disponibilidad automática: ' . $e->getMessage());
        }
    }
}
