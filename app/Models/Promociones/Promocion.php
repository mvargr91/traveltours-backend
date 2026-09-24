<?php

namespace App\Models\Promociones;

use Exception;
use Carbon\Carbon;
use App\Enum\AccionAuditoriaEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use App\Models\Seguridad\AuditoriaTabla;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Promocion extends Model
{
    use HasFactory;

    protected $table = 'promociones';

    protected $fillable = [
        'nombre',
        'descripcion',
        'tipo_descuento',
        'valor_descuento',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'usuario_creacion_id',
        'usuario_creacion_nombre',
        'usuario_modificacion_id',
        'usuario_modificacion_nombre',
    ];

    public static function obtenerColeccionLigera($dto)
    {
        $query = DB::table('promociones')
            ->select(
                'promociones.id',
                'promociones.nombre',
                'promociones.estado',
            )
            ->where('promociones.estado', 1);

        $query->orderBy('nombre', 'asc');
        return $query->get();
    }

    public static function obtenerColeccion($dto)
    {
        $query = DB::table('promociones')
            ->select(
                'promociones.id',
                'promociones.nombre',
                'promociones.descripcion',
                'promociones.tipo_descuento',
                'promociones.valor_descuento',
                'promociones.fecha_inicio',
                'promociones.fecha_fin',
                'promociones.estado',
                'promociones.usuario_creacion_id',
                'promociones.usuario_creacion_nombre',
                'promociones.usuario_modificacion_id',
                'promociones.usuario_modificacion_nombre',
                'promociones.created_at as fecha_creacion',
                'promociones.updated_at as fecha_modificacion',
            );

        if (isset($dto['nombre'])) {
            $query->where('promociones.nombre', 'like', '%' . $dto['nombre'] . '%');
        }

        if (isset($dto['ordenar_por']) && count($dto['ordenar_por']) > 0) {
            foreach ($dto['ordenar_por'] as $attribute => $value) {
                if ($attribute == 'nombre') {
                    $query->orderBy('promociones.nombre', $value);
                }
                if ($attribute == 'fecha_inicio') {
                    $query->orderBy('promociones.fecha_inicio', $value);
                }
                if ($attribute == 'fecha_fin') {
                    $query->orderBy('promociones.fecha_fin', $value);
                }
                if ($attribute == 'estado') {
                    $query->orderBy('promociones.estado', $value);
                }
                if ($attribute == 'fecha_creacion') {
                    $query->orderBy('promociones.created_at', $value);
                }
                if ($attribute == 'fecha_modificacion') {
                    $query->orderBy('promociones.updated_at', $value);
                }
            }
        } else {
            $query->orderBy('promociones.updated_at', 'desc');
        }

        $promociones = $query->paginate($dto['limite'] ?? 100);
        $data = $promociones->items();

        return [
            'datos' => $data,
            'desde' => $promociones->firstItem(),
            'hasta' => $promociones->lastItem(),
            'por_pagina' => $promociones->perPage(),
            'pagina_actual' => $promociones->currentPage(),
            'ultima_pagina' => $promociones->lastPage(),
            'total' => $promociones->total(),
        ];
    }

    public static function cargar($id)
    {
        $promocion = Promocion::find($id);

        $experiencias = DB::table('promocion_experiencia')
            ->join('experiencias', 'experiencias.id', '=', 'promocion_experiencia.experiencia_id')
            ->where('promocion_experiencia.promocion_id', $id)
            ->select('experiencias.id', 'experiencias.nombre')
            ->get();

        return [
            'id' => $promocion->id,
            'nombre' => $promocion->nombre,
            'descripcion' => $promocion->descripcion,
            'tipo_descuento' => $promocion->tipo_descuento,
            'valor_descuento' => $promocion->valor_descuento,
            'fecha_inicio' => $promocion->fecha_inicio,
            'fecha_fin' => $promocion->fecha_fin,
            'estado' => $promocion->estado,
            'experiencias' => $experiencias,
            'usuario_creacion_id' => $promocion->usuario_creacion_id,
            'usuario_creacion_nombre' => $promocion->usuario_creacion_nombre,
            'usuario_modificacion_id' => $promocion->usuario_modificacion_id,
            'usuario_modificacion_nombre' => $promocion->usuario_modificacion_nombre,
            'fecha_creacion' => (new Carbon($promocion->created_at))->format('Y-m-d H:i:s'),
            'fecha_modificacion' => (new Carbon($promocion->updated_at))->format('Y-m-d H:i:s'),
        ];
    }

    public static function modificarOCrear($dto)
    {
        $user = Auth::user();
        $usuario = $user->usuario();

        if (!isset($dto['id'])) {
            $dto['usuario_creacion_id'] = $usuario->id ?? ($dto['usuario_creacion_id'] ?? null);
            $dto['usuario_creacion_nombre'] = $usuario->nombre ?? ($dto['usuario_creacion_nombre'] ?? null);
        }
        if (isset($usuario) || isset($dto['usuario_modificacion_id'])) {
            $dto['usuario_modificacion_id'] = $usuario->id ?? ($dto['usuario_modificacion_id'] ?? null);
            $dto['usuario_modificacion_nombre'] = $usuario->nombre ?? ($dto['usuario_modificacion_nombre'] ?? null);
        }

        $promocion = isset($dto['id']) ? Promocion::find($dto['id']) : new Promocion();

        $promocionOriginal = $promocion->toJson();

        $promocion->fill($dto);
        $guardado = $promocion->save();
        if (!$guardado) {
            throw new Exception('Ocurrió un error al intentar guardar la promoción.');
        }

        $auditoriaDto = [
            'id_recurso' => $promocion->id,
            'nombre_recurso' => Promocion::class,
            'descripcion_recurso' => $promocion->nombre,
            'accion' => isset($dto['id']) ? AccionAuditoriaEnum::MODIFICAR : AccionAuditoriaEnum::CREAR,
            'recurso_original' => isset($dto['id']) ? $promocionOriginal : $promocion->toJson(),
            'recurso_resultante' => isset($dto['id']) ? $promocion->toJson() : null,
        ];
        AuditoriaTabla::crear($auditoriaDto);

        return Promocion::cargar($promocion->id);
    }

    public static function eliminar($id)
    {
        $promocion = Promocion::find($id);

        $auditoriaDto = [
            'id_recurso' => $promocion->id,
            'nombre_recurso' => Promocion::class,
            'descripcion_recurso' => $promocion->nombre,
            'accion' => AccionAuditoriaEnum::ELIMINAR,
            'recurso_original' => $promocion->toJson(),
        ];
        AuditoriaTabla::crear($auditoriaDto);

        return $promocion->delete();
    }
}
