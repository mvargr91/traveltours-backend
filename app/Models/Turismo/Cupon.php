<?php

namespace App\Models\Turismo;

use Exception;
use Carbon\Carbon;
use App\Enum\AccionAuditoriaEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use App\Models\Seguridad\AuditoriaTabla;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Cupón de descuento que el viajero usa con un código al reservar.
 *  - proveedor_id vacío: cupón de la plataforma (lo crea el administrador).
 *  - Con experiencias (cupon_experiencia) solo aplica a ellas; sin experiencias, a todas las del proveedor
 *    dueño (o a todas, si es de la plataforma).
 *  - cantidad: usos máximos (vacío = ilimitado). Los usos son las reservas con el cupón que no están
 *    canceladas ni rechazadas: al cancelarse una reserva el uso se libera solo.
 *  - Se aplica sobre el valor que queda después de la promoción vigente.
 */
class Cupon extends Model
{
    use HasFactory;

    // Reservas que no cuentan como uso del cupón.
    public const ESTADOS_SIN_USO = ['cancelada', 'rechazada'];

    protected $table = 'cupones';

    protected $fillable = [
        'codigo',
        'proveedor_id',
        'tipo',
        'nombre',
        'descripcion',
        'cantidad',
        'descuento',
        'valor',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'usuario_creacion_id',
        'usuario_creacion_nombre',
        'usuario_modificacion_id',
        'usuario_modificacion_nombre',
    ];

    public static function normalizarCodigo($codigo)
    {
        return strtoupper(trim((string) $codigo));
    }

    // Subconsulta con los usos de cada cupón.
    private static function usosSub()
    {
        return DB::table('reservas')
            ->whereColumn('reservas.cupon_id', 'cupones.id')
            ->whereNotIn('reservas.estado', self::ESTADOS_SIN_USO)
            ->selectRaw('COUNT(*)');
    }

    public static function usos($id)
    {
        return DB::table('reservas')
            ->where('cupon_id', $id)
            ->whereNotIn('estado', self::ESTADOS_SIN_USO)
            ->count();
    }

    public static function experienciasDe($id)
    {
        return DB::table('cupon_experiencia')->where('cupon_id', $id)->pluck('experiencia_id')->map(fn ($e) => (int) $e)->values();
    }

    /**
     * Cupones vigentes para elegir en una reserva del panel. Con proveedor_id trae los del proveedor y los
     * de la plataforma; con experiencia_id, solo los que aplican a esa experiencia.
     */
    public static function obtenerColeccionLigera($dto)
    {
        $hoy = Carbon::now()->toDateString();
        $query = DB::table('cupones')
            ->select(
                'cupones.id',
                DB::raw("CONCAT(cupones.codigo, ' - ', cupones.nombre) as nombre"),
                'cupones.codigo',
                'cupones.proveedor_id',
                'cupones.tipo',
                'cupones.descuento',
                'cupones.valor',
                'cupones.estado',
            )
            ->where('cupones.estado', 1)
            ->where('cupones.fecha_inicio', '<=', $hoy)
            ->where('cupones.fecha_fin', '>=', $hoy);

        if (!empty($dto['proveedor_id'])) {
            $query->where(fn ($q) => $q->where('cupones.proveedor_id', $dto['proveedor_id'])->orWhereNull('cupones.proveedor_id'));
        }
        if (!empty($dto['experiencia_id'])) {
            $proveedorExperiencia = DB::table('experiencias')->where('id', $dto['experiencia_id'])->value('proveedor_id');
            $query->where(fn ($q) => $q->whereNull('cupones.proveedor_id')->orWhere('cupones.proveedor_id', $proveedorExperiencia))
                ->where(fn ($q) => $q
                    ->whereNotExists(fn ($s) => $s->from('cupon_experiencia')->whereColumn('cupon_experiencia.cupon_id', 'cupones.id'))
                    ->orWhereExists(fn ($s) => $s->from('cupon_experiencia')
                        ->whereColumn('cupon_experiencia.cupon_id', 'cupones.id')
                        ->where('cupon_experiencia.experiencia_id', $dto['experiencia_id'])));
        }

        $query->orderBy('cupones.codigo', 'asc');
        return $query->get();
    }

    public static function obtenerColeccion($dto)
    {
        $query = DB::table('cupones')
            ->leftJoin('proveedores_turisticos', 'proveedores_turisticos.id', '=', 'cupones.proveedor_id')
            ->select(
                'cupones.id',
                'cupones.codigo',
                'cupones.proveedor_id',
                'proveedores_turisticos.nombre_comercial as proveedor_nombre',
                'cupones.tipo',
                'cupones.nombre',
                'cupones.descripcion',
                'cupones.cantidad',
                'cupones.descuento',
                'cupones.valor',
                'cupones.fecha_inicio',
                'cupones.fecha_fin',
                'cupones.estado',
                'cupones.usuario_creacion_id',
                'cupones.usuario_creacion_nombre',
                'cupones.usuario_modificacion_id',
                'cupones.usuario_modificacion_nombre',
                'cupones.created_at as fecha_creacion',
                'cupones.updated_at as fecha_modificacion',
            )
            ->selectSub(self::usosSub(), 'usos');

        if (isset($dto['nombre'])) {
            $query->where('cupones.nombre', 'like', '%' . $dto['nombre'] . '%');
        }
        if (isset($dto['codigo'])) {
            $query->where('cupones.codigo', 'like', '%' . self::normalizarCodigo($dto['codigo']) . '%');
        }
        if (!empty($dto['proveedor_id'])) {
            $query->where('cupones.proveedor_id', $dto['proveedor_id']);
        }

        if (isset($dto['ordenar_por']) && count($dto['ordenar_por']) > 0) {
            foreach ($dto['ordenar_por'] as $attribute => $value) {
                if ($attribute == 'nombre') {
                    $query->orderBy('cupones.nombre', $value);
                }
                if ($attribute == 'codigo') {
                    $query->orderBy('cupones.codigo', $value);
                }
                if ($attribute == 'tipo') {
                    $query->orderBy('cupones.tipo', $value);
                }
                if ($attribute == 'fecha_inicio') {
                    $query->orderBy('cupones.fecha_inicio', $value);
                }
                if ($attribute == 'fecha_fin') {
                    $query->orderBy('cupones.fecha_fin', $value);
                }
                if ($attribute == 'estado') {
                    $query->orderBy('cupones.estado', $value);
                }
                if ($attribute == 'fecha_creacion') {
                    $query->orderBy('cupones.created_at', $value);
                }
                if ($attribute == 'fecha_modificacion') {
                    $query->orderBy('cupones.updated_at', $value);
                }
            }
        } else {
            $query->orderBy('cupones.updated_at', 'desc');
        }

        $cupones = $query->paginate($dto['limite'] ?? 100);
        $data = $cupones->items();

        return [
            'datos' => $data,
            'desde' => $cupones->firstItem(),
            'hasta' => $cupones->lastItem(),
            'por_pagina' => $cupones->perPage(),
            'pagina_actual' => $cupones->currentPage(),
            'ultima_pagina' => $cupones->lastPage(),
            'total' => $cupones->total(),
        ];
    }

    public static function cargar($id)
    {
        $cupon = Cupon::find($id);

        return [
            'id' => $cupon->id,
            'codigo' => $cupon->codigo,
            'proveedor_id' => $cupon->proveedor_id,
            'experiencias' => self::experienciasDe($cupon->id),
            'usos' => self::usos($cupon->id),
            'tipo' => $cupon->tipo,
            'nombre' => $cupon->nombre,
            'descripcion' => $cupon->descripcion,
            'cantidad' => $cupon->cantidad,
            'descuento' => $cupon->descuento,
            'valor' => $cupon->valor,
            'fecha_inicio' => $cupon->fecha_inicio,
            'fecha_fin' => $cupon->fecha_fin,
            'estado' => $cupon->estado,
            'usuario_creacion_id' => $cupon->usuario_creacion_id,
            'usuario_creacion_nombre' => $cupon->usuario_creacion_nombre,
            'usuario_modificacion_id' => $cupon->usuario_modificacion_id,
            'usuario_modificacion_nombre' => $cupon->usuario_modificacion_nombre,
            'fecha_creacion' => (new Carbon($cupon->created_at))->format('Y-m-d H:i:s'),
            'fecha_modificacion' => (new Carbon($cupon->updated_at))->format('Y-m-d H:i:s'),
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

        if (isset($dto['codigo'])) {
            $dto['codigo'] = self::normalizarCodigo($dto['codigo']);
        }

        $cupon = isset($dto['id']) ? Cupon::find($dto['id']) : new Cupon();

        $cuponOriginal = $cupon->toJson();

        $cupon->fill($dto);
        $guardado = $cupon->save();
        if (!$guardado) {
            throw new Exception('Ocurrió un error al intentar guardar el cupón.');
        }
        if (array_key_exists('experiencias', $dto)) {
            self::sincronizarExperiencias($cupon->id, $dto['experiencias'] ?? []);
        }

        $auditoriaDto = [
            'id_recurso' => $cupon->id,
            'nombre_recurso' => Cupon::class,
            'descripcion_recurso' => $cupon->nombre,
            'accion' => isset($dto['id']) ? AccionAuditoriaEnum::MODIFICAR : AccionAuditoriaEnum::CREAR,
            'recurso_original' => isset($dto['id']) ? $cuponOriginal : $cupon->toJson(),
            'recurso_resultante' => isset($dto['id']) ? $cupon->toJson() : null,
        ];
        AuditoriaTabla::crear($auditoriaDto);

        return Cupon::cargar($cupon->id);
    }

    private static function sincronizarExperiencias($cuponId, array $experiencias)
    {
        DB::table('cupon_experiencia')->where('cupon_id', $cuponId)->delete();
        $filas = array_map(fn ($e) => ['cupon_id' => $cuponId, 'experiencia_id' => (int) $e], array_values(array_unique($experiencias)));
        if ($filas) {
            DB::table('cupon_experiencia')->insert($filas);
        }
    }

    /**
     * Valida que el cupón se pueda usar en una reserva de la experiencia.
     * $criterio: ['codigo' => 'VERANO10'] (portal) o ['id' => 5] (panel).
     * Con $bloquear toma el cupón con lockForUpdate, para que dos reservas simultáneas no superen la
     * cantidad: llamarlo dentro de la transacción que guarda la reserva.
     * Devuelve [cupón, null] o [null, mensaje de error].
     */
    public static function validarParaReserva(array $criterio, $experienciaId, $bloquear = false)
    {
        $query = isset($criterio['id'])
            ? Cupon::where('id', $criterio['id'])
            : Cupon::where('codigo', self::normalizarCodigo($criterio['codigo'] ?? ''));
        if ($bloquear) {
            $query->lockForUpdate();
        }
        $cupon = $query->first();

        if (!$cupon || !$cupon->estado) {
            return [null, 'El cupón no existe o no está activo.'];
        }
        $hoy = Carbon::now()->toDateString();
        if ($cupon->fecha_inicio > $hoy) {
            return [null, 'El cupón todavía no está vigente.'];
        }
        if ($cupon->fecha_fin < $hoy) {
            return [null, 'El cupón está vencido.'];
        }

        $experiencias = self::experienciasDe($cupon->id);
        $proveedorExperiencia = DB::table('experiencias')->where('id', $experienciaId)->value('proveedor_id');
        $aplica = $experiencias->isNotEmpty()
            ? $experiencias->contains((int) $experienciaId)
            : ($cupon->proveedor_id === null || (int) $cupon->proveedor_id === (int) $proveedorExperiencia);
        if (!$aplica) {
            return [null, 'El cupón no aplica para esta experiencia.'];
        }

        if ($cupon->cantidad !== null && self::usos($cupon->id) >= (int) $cupon->cantidad) {
            return [null, 'El cupón ya se agotó.'];
        }

        return [$cupon, null];
    }

    // Descuento del cupón sobre un valor (nunca mayor que el valor).
    public static function descuentoSobre($cupon, $valor)
    {
        $descuento = $cupon->tipo === 'porcentaje'
            ? round($valor * (float) $cupon->descuento / 100)
            : (float) $cupon->valor;

        return min(max($descuento, 0), $valor);
    }

    public static function eliminar($id)
    {
        $cupon = Cupon::find($id);

        $auditoriaDto = [
            'id_recurso' => $cupon->id,
            'nombre_recurso' => Cupon::class,
            'descripcion_recurso' => $cupon->nombre,
            'accion' => AccionAuditoriaEnum::ELIMINAR,
            'recurso_original' => $cupon->toJson(),
        ];
        AuditoriaTabla::crear($auditoriaDto);

        return $cupon->delete();
    }
}
