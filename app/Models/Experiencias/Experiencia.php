<?php

namespace App\Models\Experiencias;

use Exception;
use Carbon\Carbon;
use App\Enum\AccionAuditoriaEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use App\Models\Seguridad\AuditoriaTabla;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Experiencia extends Model
{
    use HasFactory;

    protected $table = 'experiencias';

    protected $fillable = [
        'proveedor_id',
        'destino_id',
        'nombre',
        'slug',
        'descripcion',
        'idioma',
        'incluye',
        'no_incluye',
        'precio_desde',
        'duracion',
        'capacidad_maxima',
        'punto_encuentro',
        'direccion',
        'latitud',
        'longitud',
        'estado',
        'destacada',
        'verificada',
        'usuario_creacion_id',
        'usuario_creacion_nombre',
        'usuario_modificacion_id',
        'usuario_modificacion_nombre',
    ];

    public static function obtenerColeccionLigera($dto)
    {
        $query = DB::table('experiencias')
            ->select(
                'experiencias.id',
                'experiencias.nombre',
                'experiencias.estado',
            )
            ->where('experiencias.estado', 'publicada');

        $query->orderBy('nombre', 'asc');
        return $query->get();
    }

    public static function obtenerColeccion($dto)
    {
        $query = DB::table('experiencias')
            ->join('destinos', 'destinos.id', '=', 'experiencias.destino_id')
            ->join('proveedores_turisticos', 'proveedores_turisticos.id', '=', 'experiencias.proveedor_id')
            ->select(
                'experiencias.id',
                'experiencias.proveedor_id',
                'proveedores_turisticos.nombre_comercial as proveedor_nombre',
                'experiencias.destino_id',
                'destinos.nombre as destino_nombre',
                'experiencias.nombre',
                'experiencias.slug',
                'experiencias.descripcion',
                'experiencias.idioma',
                'experiencias.incluye',
                'experiencias.no_incluye',
                'experiencias.precio_desde',
                'experiencias.duracion',
                'experiencias.capacidad_maxima',
                'experiencias.punto_encuentro',
                'experiencias.direccion',
                'experiencias.latitud',
                'experiencias.longitud',
                'experiencias.estado',
                'experiencias.destacada',
                'experiencias.verificada',
                'experiencias.usuario_creacion_id',
                'experiencias.usuario_creacion_nombre',
                'experiencias.usuario_modificacion_id',
                'experiencias.usuario_modificacion_nombre',
                'experiencias.created_at as fecha_creacion',
                'experiencias.updated_at as fecha_modificacion',
            );

        if (isset($dto['nombre'])) {
            $query->where('experiencias.nombre', 'like', '%' . $dto['nombre'] . '%');
        }
        if (isset($dto['destino_id'])) {
            $query->where('experiencias.destino_id', $dto['destino_id']);
        }
        if (isset($dto['proveedor_id'])) {
            $query->where('experiencias.proveedor_id', $dto['proveedor_id']);
        }
        if (isset($dto['estado'])) {
            $query->where('experiencias.estado', $dto['estado']);
        }

        if (isset($dto['ordenar_por']) && count($dto['ordenar_por']) > 0) {
            foreach ($dto['ordenar_por'] as $attribute => $value) {
                if ($attribute == 'nombre') {
                    $query->orderBy('experiencias.nombre', $value);
                }
                if ($attribute == 'destino_nombre') {
                    $query->orderBy('destinos.nombre', $value);
                }
                if ($attribute == 'proveedor_nombre') {
                    $query->orderBy('proveedores_turisticos.nombre_comercial', $value);
                }
                if ($attribute == 'precio_desde') {
                    $query->orderBy('experiencias.precio_desde', $value);
                }
                if ($attribute == 'estado') {
                    $query->orderBy('experiencias.estado', $value);
                }
                if ($attribute == 'destacada') {
                    $query->orderBy('experiencias.destacada', $value);
                }
                if ($attribute == 'fecha_creacion') {
                    $query->orderBy('experiencias.created_at', $value);
                }
                if ($attribute == 'fecha_modificacion') {
                    $query->orderBy('experiencias.updated_at', $value);
                }
            }
        } else {
            $query->orderBy('experiencias.updated_at', 'desc');
        }

        $experiencias = $query->paginate($dto['limite'] ?? 100);
        $data = $experiencias->items();

        return [
            'datos' => $data,
            'desde' => $experiencias->firstItem(),
            'hasta' => $experiencias->lastItem(),
            'por_pagina' => $experiencias->perPage(),
            'pagina_actual' => $experiencias->currentPage(),
            'ultima_pagina' => $experiencias->lastPage(),
            'total' => $experiencias->total(),
        ];
    }

    public static function cargar($id)
    {
        $experiencia = Experiencia::find($id);

        $categorias = DB::table('experiencia_categoria')
            ->join('categorias', 'categorias.id', '=', 'experiencia_categoria.categoria_id')
            ->where('experiencia_categoria.experiencia_id', $id)
            ->select('categorias.id', 'categorias.nombre')
            ->get();

        $caracteristicas = DB::table('experiencia_caracteristica')
            ->join('caracteristicas', 'caracteristicas.id', '=', 'experiencia_caracteristica.caracteristica_id')
            ->where('experiencia_caracteristica.experiencia_id', $id)
            ->select('caracteristicas.id', 'caracteristicas.nombre', 'experiencia_caracteristica.incluida')
            ->get();

        return [
            'id' => $experiencia->id,
            'proveedor_id' => $experiencia->proveedor_id,
            'destino_id' => $experiencia->destino_id,
            'nombre' => $experiencia->nombre,
            'slug' => $experiencia->slug,
            'descripcion' => $experiencia->descripcion,
            'idioma' => $experiencia->idioma,
            'incluye' => $experiencia->incluye,
            'no_incluye' => $experiencia->no_incluye,
            'precio_desde' => $experiencia->precio_desde,
            'duracion' => $experiencia->duracion,
            'capacidad_maxima' => $experiencia->capacidad_maxima,
            'punto_encuentro' => $experiencia->punto_encuentro,
            'direccion' => $experiencia->direccion,
            'latitud' => $experiencia->latitud,
            'longitud' => $experiencia->longitud,
            'estado' => $experiencia->estado,
            'destacada' => $experiencia->destacada,
            'verificada' => $experiencia->verificada,
            'categorias' => $categorias,
            'caracteristicas' => $caracteristicas,
            'usuario_creacion_id' => $experiencia->usuario_creacion_id,
            'usuario_creacion_nombre' => $experiencia->usuario_creacion_nombre,
            'usuario_modificacion_id' => $experiencia->usuario_modificacion_id,
            'usuario_modificacion_nombre' => $experiencia->usuario_modificacion_nombre,
            'fecha_creacion' => (new Carbon($experiencia->created_at))->format('Y-m-d H:i:s'),
            'fecha_modificacion' => (new Carbon($experiencia->updated_at))->format('Y-m-d H:i:s'),
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

        $experiencia = isset($dto['id']) ? Experiencia::find($dto['id']) : new Experiencia();

        $experienciaOriginal = $experiencia->toJson();

        $experiencia->fill($dto);
        $guardado = $experiencia->save();
        if (!$guardado) {
            throw new Exception('Ocurrió un error al intentar guardar la experiencia.');
        }

        $auditoriaDto = [
            'id_recurso' => $experiencia->id,
            'nombre_recurso' => Experiencia::class,
            'descripcion_recurso' => $experiencia->nombre,
            'accion' => isset($dto['id']) ? AccionAuditoriaEnum::MODIFICAR : AccionAuditoriaEnum::CREAR,
            'recurso_original' => isset($dto['id']) ? $experienciaOriginal : $experiencia->toJson(),
            'recurso_resultante' => isset($dto['id']) ? $experiencia->toJson() : null,
        ];
        AuditoriaTabla::crear($auditoriaDto);

        return Experiencia::cargar($experiencia->id);
    }

    public static function cambiarEstado($id, $estado)
    {
        $experiencia = Experiencia::find($id);
        $experienciaOriginal = $experiencia->toJson();

        $experiencia->estado = $estado;
        $guardado = $experiencia->save();
        if (!$guardado) {
            throw new Exception('Ocurrió un error al intentar cambiar el estado de la experiencia.');
        }

        $auditoriaDto = [
            'id_recurso' => $experiencia->id,
            'nombre_recurso' => Experiencia::class,
            'descripcion_recurso' => $experiencia->nombre,
            'accion' => AccionAuditoriaEnum::MODIFICAR,
            'recurso_original' => $experienciaOriginal,
            'recurso_resultante' => $experiencia->toJson(),
        ];
        AuditoriaTabla::crear($auditoriaDto);

        return Experiencia::cargar($experiencia->id);
    }

    public static function eliminar($id)
    {
        $experiencia = Experiencia::find($id);

        $auditoriaDto = [
            'id_recurso' => $experiencia->id,
            'nombre_recurso' => Experiencia::class,
            'descripcion_recurso' => $experiencia->nombre,
            'accion' => AccionAuditoriaEnum::ELIMINAR,
            'recurso_original' => $experiencia->toJson(),
        ];
        AuditoriaTabla::crear($auditoriaDto);

        return $experiencia->delete();
    }
}
