<?php

namespace App\Models\Proveedores;

use Exception;
use Carbon\Carbon;
use App\Enum\AccionAuditoriaEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use App\Models\Seguridad\AuditoriaTabla;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProveedorTuristico extends Model
{
    use HasFactory;

    protected $table = 'proveedores_turisticos';

    // Tipo de servicio del negocio (proveedores_turisticos.tipo_servicio). Deben coincidir con el frontend.
    public const TIPOS_SERVICIO = [
        'operador_turistico', 'guia_turistico', 'agencia_viajes', 'alojamiento', 'transporte',
        'actividades_aventura', 'restaurante', 'otro',
    ];
    public const TIPOS_CUENTA = ['ahorros', 'corriente'];
    // Cuenta donde el proveedor recibe sus pagos: solo la ven el administrador y el propio proveedor.
    public const CAMPOS_BANCARIOS = ['banco', 'tipo_cuenta', 'numero_cuenta', 'titular_cuenta', 'documento_titular'];
    public const MAX_FOTOS = 12;

    // Reglas de los datos del registro que comparten el formulario del panel, "Mi negocio" y el registro público.
    public static function reglasRegistro(): array
    {
        return [
            'tipo_servicio' => 'nullable|string|in:' . implode(',', self::TIPOS_SERVICIO),
            'categorias' => 'nullable|array|max:20',
            'categorias.*' => 'integer|exists:categorias,id',
            'banco' => 'nullable|string|max:100',
            'tipo_cuenta' => 'nullable|string|in:' . implode(',', self::TIPOS_CUENTA),
            'numero_cuenta' => ['nullable', 'string', 'max:40', 'regex:/^[0-9-]+$/'],
            'titular_cuenta' => 'nullable|string|max:150',
            'documento_titular' => 'nullable|string|max:50',
        ];
    }

    public const MENSAJES_REGISTRO = [
        'tipo_servicio.in' => 'El tipo de servicio no es válido.',
        'tipo_cuenta.in' => 'El tipo de cuenta debe ser ahorros o corriente.',
        'numero_cuenta.regex' => 'El número de cuenta solo puede tener números y guiones.',
    ];

    protected $fillable = [
        'usuario_id',
        'nombre_comercial',
        'razon_social',
        'nit',
        'tipo_servicio',
        'descripcion',
        'telefono',
        'correo',
        'direccion',
        'destino_id',
        'sitio_web',
        'instagram',
        'facebook',
        'rnt',
        'banco',
        'tipo_cuenta',
        'numero_cuenta',
        'titular_cuenta',
        'documento_titular',
        'estado_verificacion',
        'observaciones_verificacion',
        'verificado_en',
        'verificado_por',
        'estado',
        'usuario_creacion_id',
        'usuario_creacion_nombre',
        'usuario_modificacion_id',
        'usuario_modificacion_nombre',
    ];

    public static function obtenerColeccionLigera($dto)
    {
        $query = DB::table('proveedores_turisticos')
            ->select(
                'proveedores_turisticos.id',
                'proveedores_turisticos.nombre_comercial',
                'proveedores_turisticos.estado',
            )
            ->where('proveedores_turisticos.estado', 1);

        $query->orderBy('nombre_comercial', 'asc');
        return $query->get();
    }

    public static function obtenerColeccion($dto)
    {
        $query = DB::table('proveedores_turisticos')
            ->leftJoin('destinos', 'destinos.id', '=', 'proveedores_turisticos.destino_id')
            ->select(
                'proveedores_turisticos.id',
                'proveedores_turisticos.usuario_id',
                'proveedores_turisticos.nombre_comercial',
                'proveedores_turisticos.razon_social',
                'proveedores_turisticos.nit',
                'proveedores_turisticos.tipo_servicio',
                'proveedores_turisticos.logo',
                'proveedores_turisticos.descripcion',
                'proveedores_turisticos.telefono',
                'proveedores_turisticos.correo',
                'proveedores_turisticos.direccion',
                'proveedores_turisticos.destino_id',
                'destinos.nombre as destino_nombre',
                'proveedores_turisticos.sitio_web',
                'proveedores_turisticos.instagram',
                'proveedores_turisticos.facebook',
                'proveedores_turisticos.rnt',
                'proveedores_turisticos.estado_verificacion',
                'proveedores_turisticos.observaciones_verificacion',
                'proveedores_turisticos.verificado_en',
                'proveedores_turisticos.verificado_por',
                'proveedores_turisticos.estado',
                'proveedores_turisticos.usuario_creacion_id',
                'proveedores_turisticos.usuario_creacion_nombre',
                'proveedores_turisticos.usuario_modificacion_id',
                'proveedores_turisticos.usuario_modificacion_nombre',
                'proveedores_turisticos.created_at as fecha_creacion',
                'proveedores_turisticos.updated_at as fecha_modificacion',
            );

        if (isset($dto['nombre'])) {
            $query->where('proveedores_turisticos.nombre_comercial', 'like', '%' . $dto['nombre'] . '%');
        }
        if (isset($dto['estado_verificacion'])) {
            $query->where('proveedores_turisticos.estado_verificacion', $dto['estado_verificacion']);
        }
        if (isset($dto['tipo_servicio'])) {
            $query->where('proveedores_turisticos.tipo_servicio', $dto['tipo_servicio']);
        }

        if (isset($dto['ordenar_por']) && count($dto['ordenar_por']) > 0) {
            foreach ($dto['ordenar_por'] as $attribute => $value) {
                if ($attribute == 'nombre_comercial') {
                    $query->orderBy('proveedores_turisticos.nombre_comercial', $value);
                }
                if ($attribute == 'nit') {
                    $query->orderBy('proveedores_turisticos.nit', $value);
                }
                if ($attribute == 'destino_nombre') {
                    $query->orderBy('destinos.nombre', $value);
                }
                if ($attribute == 'estado_verificacion') {
                    $query->orderBy('proveedores_turisticos.estado_verificacion', $value);
                }
                if ($attribute == 'estado') {
                    $query->orderBy('proveedores_turisticos.estado', $value);
                }
                if ($attribute == 'usuario_creacion_nombre') {
                    $query->orderBy('proveedores_turisticos.usuario_creacion_nombre', $value);
                }
                if ($attribute == 'fecha_creacion') {
                    $query->orderBy('proveedores_turisticos.created_at', $value);
                }
                if ($attribute == 'fecha_modificacion') {
                    $query->orderBy('proveedores_turisticos.updated_at', $value);
                }
            }
        } else {
            $query->orderBy('proveedores_turisticos.updated_at', 'desc');
        }

        $proveedores = $query->paginate($dto['limite'] ?? 100);
        $data = $proveedores->items();

        return [
            'datos' => $data,
            'desde' => $proveedores->firstItem(),
            'hasta' => $proveedores->lastItem(),
            'por_pagina' => $proveedores->perPage(),
            'pagina_actual' => $proveedores->currentPage(),
            'ultima_pagina' => $proveedores->lastPage(),
            'total' => $proveedores->total(),
        ];
    }

    // $conDatosBancarios: false cuando consulta otro proveedor (la cuenta bancaria es privada).
    public static function cargar($id, $conDatosBancarios = true)
    {
        $proveedor = ProveedorTuristico::find($id);

        $datos = [
            'id' => $proveedor->id,
            'usuario_id' => $proveedor->usuario_id,
            'nombre_comercial' => $proveedor->nombre_comercial,
            'razon_social' => $proveedor->razon_social,
            'nit' => $proveedor->nit,
            'tipo_servicio' => $proveedor->tipo_servicio,
            'categorias' => self::categoriasDe($id),
            'logo' => $proveedor->logo,
            'fotos' => self::fotosDe($id),
            'descripcion' => $proveedor->descripcion,
            'telefono' => $proveedor->telefono,
            'correo' => $proveedor->correo,
            'direccion' => $proveedor->direccion,
            'destino_id' => $proveedor->destino_id,
            'sitio_web' => $proveedor->sitio_web,
            'instagram' => $proveedor->instagram,
            'facebook' => $proveedor->facebook,
            'rnt' => $proveedor->rnt,
            'estado_verificacion' => $proveedor->estado_verificacion,
            'observaciones_verificacion' => $proveedor->observaciones_verificacion,
            'verificado_en' => $proveedor->verificado_en,
            'verificado_por' => $proveedor->verificado_por,
            'estado' => $proveedor->estado,
            'usuario_creacion_id' => $proveedor->usuario_creacion_id,
            'usuario_creacion_nombre' => $proveedor->usuario_creacion_nombre,
            'usuario_modificacion_id' => $proveedor->usuario_modificacion_id,
            'usuario_modificacion_nombre' => $proveedor->usuario_modificacion_nombre,
            'fecha_creacion' => (new Carbon($proveedor->created_at))->format('Y-m-d H:i:s'),
            'fecha_modificacion' => (new Carbon($proveedor->updated_at))->format('Y-m-d H:i:s'),
        ];
        if ($conDatosBancarios) {
            foreach (self::CAMPOS_BANCARIOS as $campo) {
                $datos[$campo] = $proveedor->$campo;
            }
        }
        return $datos;
    }

    // Ids de las categorías del proveedor (qué tipo de experiencias ofrece).
    public static function categoriasDe($id)
    {
        return DB::table('proveedor_categoria')->where('proveedor_id', $id)->pluck('categoria_id')->map(fn ($c) => (int) $c)->values();
    }

    public static function fotosDe($id)
    {
        return DB::table('proveedor_fotos')->where('proveedor_id', $id)->orderBy('posicion')->orderBy('id')->get(['id', 'ruta_archivo', 'posicion']);
    }

    // Reemplaza las categorías del proveedor por las indicadas.
    public static function sincronizarCategorias($id, array $categorias)
    {
        DB::table('proveedor_categoria')->where('proveedor_id', $id)->delete();
        $filas = collect($categorias)->filter()->unique()->map(fn ($categoriaId) => [
            'proveedor_id' => $id,
            'categoria_id' => (int) $categoriaId,
        ])->values()->all();
        if ($filas) {
            DB::table('proveedor_categoria')->insert($filas);
        }
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

        $proveedor = isset($dto['id']) ? ProveedorTuristico::find($dto['id']) : new ProveedorTuristico();

        $proveedorOriginal = $proveedor->toJson();

        $proveedor->fill($dto);
        $guardado = $proveedor->save();
        if (!$guardado) {
            throw new Exception('Ocurrió un error al intentar guardar el proveedor turístico.');
        }
        if (isset($dto['categorias']) && is_array($dto['categorias'])) {
            self::sincronizarCategorias($proveedor->id, $dto['categorias']);
        }

        $auditoriaDto = [
            'id_recurso' => $proveedor->id,
            'nombre_recurso' => ProveedorTuristico::class,
            'descripcion_recurso' => $proveedor->nombre_comercial,
            'accion' => isset($dto['id']) ? AccionAuditoriaEnum::MODIFICAR : AccionAuditoriaEnum::CREAR,
            'recurso_original' => isset($dto['id']) ? $proveedorOriginal : $proveedor->toJson(),
            'recurso_resultante' => isset($dto['id']) ? $proveedor->toJson() : null,
        ];
        AuditoriaTabla::crear($auditoriaDto);

        return ProveedorTuristico::cargar($proveedor->id);
    }

    public static function verificar($dto)
    {
        $user = Auth::user();
        $usuario = $user->usuario();

        $proveedor = ProveedorTuristico::find($dto['id']);
        $proveedorOriginal = $proveedor->toJson();

        $proveedor->fill([
            'estado_verificacion' => $dto['estado_verificacion'],
            'observaciones_verificacion' => $dto['observaciones_verificacion'] ?? null,
            'verificado_en' => now(),
            'verificado_por' => $usuario->id ?? null,
        ]);
        $guardado = $proveedor->save();
        if (!$guardado) {
            throw new Exception('Ocurrió un error al intentar verificar el proveedor turístico.');
        }

        $auditoriaDto = [
            'id_recurso' => $proveedor->id,
            'nombre_recurso' => ProveedorTuristico::class,
            'descripcion_recurso' => $proveedor->nombre_comercial,
            'accion' => AccionAuditoriaEnum::MODIFICAR,
            'recurso_original' => $proveedorOriginal,
            'recurso_resultante' => $proveedor->toJson(),
        ];
        AuditoriaTabla::crear($auditoriaDto);

        return ProveedorTuristico::cargar($proveedor->id);
    }

    public static function eliminar($id)
    {
        $proveedor = ProveedorTuristico::find($id);

        $auditoriaDto = [
            'id_recurso' => $proveedor->id,
            'nombre_recurso' => ProveedorTuristico::class,
            'descripcion_recurso' => $proveedor->nombre_comercial,
            'accion' => AccionAuditoriaEnum::ELIMINAR,
            'recurso_original' => $proveedor->toJson(),
        ];
        AuditoriaTabla::crear($auditoriaDto);

        return $proveedor->delete();
    }

    // ---------------------- Portal público -------------------------- //

    /**
     * Perfil público del proveedor: datos del negocio, contacto y redes (sin NIT ni datos de auditoría)
     * y el resumen de sus experiencias publicadas. Null si no existe o está inactivo.
     */
    public static function cargarPublico($id)
    {
        $proveedor = DB::table('proveedores_turisticos')
            ->leftJoin('destinos', 'destinos.id', '=', 'proveedores_turisticos.destino_id')
            ->where('proveedores_turisticos.id', $id)
            ->where('proveedores_turisticos.estado', 1)
            ->select(
                'proveedores_turisticos.id',
                'proveedores_turisticos.nombre_comercial',
                'proveedores_turisticos.tipo_servicio',
                'proveedores_turisticos.logo',
                'proveedores_turisticos.descripcion',
                'proveedores_turisticos.telefono',
                'proveedores_turisticos.correo',
                'proveedores_turisticos.sitio_web',
                'proveedores_turisticos.instagram',
                'proveedores_turisticos.facebook',
                'proveedores_turisticos.rnt',
                'proveedores_turisticos.estado_verificacion',
                'proveedores_turisticos.created_at as fecha_registro',
                'destinos.nombre as destino_nombre',
                'destinos.slug as destino_slug',
            )
            ->first();

        if (!$proveedor) {
            return null;
        }

        $proveedor->fotos = DB::table('proveedor_fotos')->where('proveedor_id', $id)->orderBy('posicion')->orderBy('id')->pluck('ruta_archivo');
        $proveedor->categorias = DB::table('proveedor_categoria')
            ->join('categorias', 'categorias.id', '=', 'proveedor_categoria.categoria_id')
            ->where('proveedor_categoria.proveedor_id', $id)
            ->where('categorias.estado', 1)
            ->orderBy('categorias.nombre')
            ->get(['categorias.id', 'categorias.nombre', 'categorias.slug']);

        $proveedor->total_experiencias = DB::table('experiencias')
            ->where('proveedor_id', $id)
            ->where('estado', 'publicada')
            ->count();

        $resenas = DB::table('resenas')
            ->join('experiencias', 'experiencias.id', '=', 'resenas.experiencia_id')
            ->where('experiencias.proveedor_id', $id)
            ->where('experiencias.estado', 'publicada')
            ->where('resenas.estado', 'aprobada')
            ->selectRaw('ROUND(AVG(resenas.calificacion), 1) as promedio, COUNT(*) as total')
            ->first();
        $proveedor->calificacion_promedio = $resenas->promedio;
        $proveedor->total_resenas = $resenas->total;

        return $proveedor;
    }
}
