<?php

namespace App\Models\Finanzas;

use Exception;
use InvalidArgumentException;
use Illuminate\Support\Str;
use App\Enum\AccionAuditoriaEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use App\Models\Seguridad\AuditoriaTabla;
use App\Services\AvisosPasarela;
use App\Services\Pagos\PasarelaPago;
use App\Services\Pagos\WompiPasarela;
use App\Services\Pagos\MercadoPagoPasarela;

/**
 * Pasarela de pago donde un proveedor recibe sus pagos en línea (una cuenta por proveedor). El proveedor de la
 * plataforma (es_plataforma) tiene la del administrador: con ella se cobran sus tours y las liquidaciones.
 *
 * Estados: pendiente (guardada sin probar o con la prueba fallida) | activa (la prueba de conexión pasó) |
 * inactiva (apagada a mano). Solo con una cuenta activa las experiencias del proveedor ofrecen pago en línea.
 * Las credenciales se guardan cifradas y nunca vuelven al navegador: cargar() las devuelve enmascaradas.
 */
class CuentaPasarela extends Model
{
    protected $table = 'cuentas_pasarela';

    public const PASARELAS = [
        'wompi' => WompiPasarela::class,
        'mercadopago' => MercadoPagoPasarela::class,
    ];
    public const NOMBRES_PASARELA = ['wompi' => 'Wompi', 'mercadopago' => 'Mercado Pago'];
    public const AMBIENTES = ['sandbox' => 'Pruebas (sandbox)', 'produccion' => 'Producción'];
    public const ESTADOS = ['pendiente' => 'Pendiente de verificar', 'activa' => 'Activa', 'inactiva' => 'Inactiva'];

    protected $fillable = [
        'proveedor_id',
        'pasarela',
        'ambiente',
        'credenciales',
        'codigo',
        'estado',
        'comercio_nombre',
        'verificada_en',
        'ultimo_error',
        'usuario_creacion_id',
        'usuario_creacion_nombre',
        'usuario_modificacion_id',
        'usuario_modificacion_nombre',
    ];

    protected $hidden = ['credenciales'];

    protected $casts = [
        'credenciales' => 'encrypted:array',
        'verificada_en' => 'datetime',
    ];

    // Instancia de la pasarela con las credenciales de esta cuenta.
    public function pasarela(): PasarelaPago
    {
        return self::instancia($this->pasarela, $this->credenciales ?? [], $this->ambiente);
    }

    public static function instancia(string $pasarela, array $credenciales = [], string $ambiente = 'sandbox'): PasarelaPago
    {
        if (!isset(self::PASARELAS[$pasarela])) {
            throw new InvalidArgumentException("Pasarela de pagos desconocida: {$pasarela}");
        }
        $clase = self::PASARELAS[$pasarela];
        return new $clase($credenciales, $ambiente);
    }

    public function urlRetorno(array $parametros = []): string
    {
        return route('publico.pagos.retorno.cuenta', ['pasarela' => $this->pasarela, 'cuenta' => $this->codigo] + $parametros);
    }

    public function urlEventos(): string
    {
        return route('publico.pagos.eventos.cuenta', ['pasarela' => $this->pasarela, 'cuenta' => $this->codigo]);
    }

    // ---------------------- Resolución -------------------------- //

    // Cuenta activa con la que cobra el proveedor, o null si no tiene.
    public static function activaDe($proveedorId): ?self
    {
        return self::where('proveedor_id', $proveedorId)->where('estado', 'activa')->first();
    }

    public static function idProveedorPlataforma(): ?int
    {
        $id = DB::table('proveedores_turisticos')->where('es_plataforma', true)->orderBy('id')->value('id');
        return $id ? (int) $id : null;
    }

    // Cuenta activa de la plataforma (tours propios y cobro de liquidaciones).
    public static function dePlataforma(): ?self
    {
        $id = self::idProveedorPlataforma();
        return $id ? self::activaDe($id) : null;
    }

    /**
     * Cuenta de una URL de retorno o de eventos. Con código, la de ese código (si es de esa pasarela); sin código
     * (URL anteriores a las cuentas por proveedor, como /wompi/eventos), la de la plataforma si usa esa pasarela.
     */
    public static function resolver(string $pasarela, ?string $codigo = null): ?self
    {
        if ($codigo) {
            return self::where('codigo', $codigo)->where('pasarela', $pasarela)->first();
        }
        $id = self::idProveedorPlataforma();
        return $id ? self::where('proveedor_id', $id)->where('pasarela', $pasarela)->first() : null;
    }

    /**
     * Expresión SQL de la modalidad de pago que de verdad aplica a la experiencia: "pago_en_linea" solo si su
     * proveedor tiene una cuenta activa; si no, "reserva". Va en los select con el alias que se indique.
     */
    public static function sqlModalidadEfectiva(string $alias = 'modalidad_pago'): \Illuminate\Contracts\Database\Query\Expression
    {
        return DB::raw(
            "CASE WHEN experiencias.modalidad_pago = 'pago_en_linea' AND EXISTS (SELECT 1 FROM cuentas_pasarela cp " .
            "WHERE cp.proveedor_id = experiencias.proveedor_id AND cp.estado = 'activa') THEN 'pago_en_linea' ELSE 'reserva' END as {$alias}"
        );
    }

    public static function modalidadEfectiva($experienciaId): string
    {
        $experiencia = DB::table('experiencias')->where('id', $experienciaId)->select('modalidad_pago', 'proveedor_id')->first();
        return $experiencia && $experiencia->modalidad_pago === 'pago_en_linea' && self::activaDe($experiencia->proveedor_id)
            ? 'pago_en_linea'
            : 'reserva';
    }

    // ---------------------- CRUD -------------------------- //

    private static function consulta()
    {
        return DB::table('cuentas_pasarela')
            ->join('proveedores_turisticos', 'proveedores_turisticos.id', '=', 'cuentas_pasarela.proveedor_id')
            ->select(
                'cuentas_pasarela.id',
                'cuentas_pasarela.proveedor_id',
                'proveedores_turisticos.nombre_comercial as proveedor_nombre',
                'proveedores_turisticos.es_plataforma',
                'cuentas_pasarela.pasarela',
                'cuentas_pasarela.ambiente',
                'cuentas_pasarela.estado',
                'cuentas_pasarela.comercio_nombre',
                'cuentas_pasarela.verificada_en',
                'cuentas_pasarela.ultimo_error',
                'cuentas_pasarela.usuario_modificacion_nombre',
                'cuentas_pasarela.created_at as fecha_creacion',
                'cuentas_pasarela.updated_at as fecha_modificacion',
            );
    }

    private static function formatear(object $cuenta): array
    {
        return (array) $cuenta + [
            'pasarela_nombre' => self::NOMBRES_PASARELA[$cuenta->pasarela] ?? $cuenta->pasarela,
            'ambiente_nombre' => self::AMBIENTES[$cuenta->ambiente] ?? $cuenta->ambiente,
            'estado_nombre' => self::ESTADOS[$cuenta->estado] ?? $cuenta->estado,
        ];
    }

    public static function obtenerColeccion($dto)
    {
        $query = self::consulta()
            ->when($dto['proveedor_id'] ?? null, fn ($q, $v) => $q->where('cuentas_pasarela.proveedor_id', $v))
            ->when($dto['pasarela'] ?? null, fn ($q, $v) => $q->where('cuentas_pasarela.pasarela', $v))
            ->when($dto['estado'] ?? null, fn ($q, $v) => $q->where('cuentas_pasarela.estado', $v))
            ->orderByDesc('proveedores_turisticos.es_plataforma')
            ->orderBy('proveedores_turisticos.nombre_comercial');
        $pagina = $query->paginate((int) ($dto['limite'] ?? 100));

        return [
            'datos' => $pagina->getCollection()->map(fn ($c) => self::formatear($c))->all(),
            'desde' => $pagina->firstItem(),
            'hasta' => $pagina->lastItem(),
            'por_pagina' => $pagina->perPage(),
            'pagina_actual' => $pagina->currentPage(),
            'ultima_pagina' => $pagina->lastPage(),
            'total' => $pagina->total(),
        ];
    }

    /**
     * Las llaves secretas no salen nunca (ni una parte): llegan vacías y credenciales_guardadas dice cuáles ya están.
     * Los campos no secretos (la llave pública de Wompi, que va en todo checkout) se muestran completos.
     */
    public static function cargar($id)
    {
        $cuenta = self::find($id);
        $datos = self::formatear(self::consulta()->where('cuentas_pasarela.id', $id)->first());
        $campos = self::PASARELAS[$cuenta->pasarela]::campos();
        $guardadas = $cuenta->credenciales ?? [];
        $datos['credenciales'] = collect($campos)->mapWithKeys(fn ($campo, $nombre) => [
            $nombre => $campo[2] ? '' : (string) ($guardadas[$nombre] ?? ''),
        ]);
        $datos['credenciales_guardadas'] = array_values(array_intersect(array_keys($campos), array_keys(array_filter($guardadas))));
        $datos['url_eventos'] = $cuenta->urlEventos();
        return $datos;
    }

    /**
     * Crea o modifica la cuenta. Las credenciales que lleguen vacías conservan el valor guardado.
     * Cambiar la pasarela, el ambiente o alguna llave obliga a probar de nuevo: la cuenta queda pendiente.
     * Cada cambio se avisa por correo al proveedor y a la administración (AvisosPasarela).
     */
    public static function modificarOCrear($dto)
    {
        $usuario = Auth::user()?->usuario();
        $cuenta = isset($dto['id']) ? self::find($dto['id']) : new self();
        $original = $cuenta->exists ? json_encode(self::sinSecretos($cuenta)) : null;

        $anteriores = $cuenta->exists && $cuenta->pasarela === $dto['pasarela'] ? ($cuenta->credenciales ?? []) : [];
        $credenciales = [];
        foreach (array_keys(self::PASARELAS[$dto['pasarela']]::campos()) as $campo) {
            $valor = trim((string) ($dto['credenciales'][$campo] ?? ''));
            $credenciales[$campo] = ($valor === '' || str_starts_with($valor, '••••')) ? ($anteriores[$campo] ?? '') : $valor;
        }
        $credenciales = array_filter($credenciales, fn ($v) => $v !== '');

        $cambio = !$cuenta->exists
            || $cuenta->pasarela !== $dto['pasarela']
            || $cuenta->ambiente !== $dto['ambiente']
            || $credenciales != ($cuenta->credenciales ?? []);
        $detalle = self::detalleCambio($cuenta, $dto, $credenciales);
        $nueva = !$cuenta->exists;

        if (!$cuenta->exists) {
            $cuenta->proveedor_id = $dto['proveedor_id'];
            $cuenta->codigo = Str::lower(Str::random(24));
            $cuenta->usuario_creacion_id = $usuario->id ?? 0;
            $cuenta->usuario_creacion_nombre = $usuario->nombre ?? 'Sistema';
        }
        $cuenta->fill([
            'pasarela' => $dto['pasarela'],
            'ambiente' => $dto['ambiente'],
            'credenciales' => $credenciales,
            'usuario_modificacion_id' => $usuario->id ?? 0,
            'usuario_modificacion_nombre' => $usuario->nombre ?? 'Sistema',
        ]);
        if ($cambio) {
            $cuenta->fill(['estado' => 'pendiente', 'verificada_en' => null, 'comercio_nombre' => null, 'ultimo_error' => null]);
        }
        if (!$cuenta->save()) {
            throw new Exception('Ocurrió un error al intentar guardar la pasarela de pago.');
        }

        AuditoriaTabla::crear([
            'id_recurso' => $cuenta->id,
            'nombre_recurso' => self::class,
            'descripcion_recurso' => (self::NOMBRES_PASARELA[$cuenta->pasarela] ?? $cuenta->pasarela) . ' del proveedor ' . $cuenta->proveedor_id,
            'accion' => $original ? AccionAuditoriaEnum::MODIFICAR : AccionAuditoriaEnum::CREAR,
            'recurso_original' => $original ?? json_encode(self::sinSecretos($cuenta)),
            'recurso_resultante' => $original ? json_encode(self::sinSecretos($cuenta)) : null,
        ]);

        if ($cambio) {
            AvisosPasarela::notificar($cuenta->proveedor_id, $nueva ? 'se configuró' : 'se modificó', $detalle);
        }
        return $cuenta;
    }

    // Qué cambió, para el aviso: nombres de campos, nunca sus valores.
    private static function detalleCambio(self $cuenta, array $dto, array $credenciales): array
    {
        $nombre = fn ($p) => self::NOMBRES_PASARELA[$p] ?? $p;
        if (!$cuenta->exists) {
            return [$nombre($dto['pasarela']) . ' (' . (self::AMBIENTES[$dto['ambiente']] ?? $dto['ambiente']) . ')'];
        }
        $detalle = [];
        if ($cuenta->pasarela !== $dto['pasarela']) {
            $detalle[] = 'Pasarela: ' . $nombre($cuenta->pasarela) . ' → ' . $nombre($dto['pasarela']);
        }
        if ($cuenta->ambiente !== $dto['ambiente']) {
            $detalle[] = 'Ambiente: ' . (self::AMBIENTES[$cuenta->ambiente] ?? $cuenta->ambiente) . ' → ' . (self::AMBIENTES[$dto['ambiente']] ?? $dto['ambiente']);
        }
        $anteriores = $cuenta->credenciales ?? [];
        $campos = self::PASARELAS[$dto['pasarela']]::campos();
        $cambiadas = collect($campos)
            ->filter(fn ($campo, $clave) => ($credenciales[$clave] ?? '') !== ($anteriores[$clave] ?? ''))
            ->map(fn ($campo) => $campo[0])
            ->values()
            ->all();
        if ($cambiadas) {
            $detalle[] = 'Llaves cambiadas: ' . implode(', ', $cambiadas);
        }
        return $detalle;
    }

    /**
     * Prueba las llaves contra la pasarela. Si pasa, la cuenta queda activa (salvo que estuviera apagada a mano y
     * no se pida activarla); si falla, pendiente con el error.
     */
    public static function probar($id, $activar = true)
    {
        $cuenta = self::find($id);
        $resultado = $cuenta->pasarela()->probarConexion();
        if (!$cuenta->pasarela()->configurada()) {
            $resultado = ['ok' => false, 'comercio' => null, 'error' => 'Faltan credenciales obligatorias.'];
        }

        $cuenta->fill($resultado['ok']
            ? ['estado' => $activar ? 'activa' : $cuenta->estado, 'verificada_en' => now(), 'comercio_nombre' => $resultado['comercio'], 'ultimo_error' => null]
            : ['estado' => 'pendiente', 'ultimo_error' => mb_substr((string) $resultado['error'], 0, 500)]);
        $cuenta->save();

        return $resultado;
    }

    public static function cambiarEstado($id, $estado)
    {
        $cuenta = self::find($id);
        if ($estado === 'activa' && !$cuenta->verificada_en) {
            throw new Exception('Prueba la conexión antes de activar la pasarela.');
        }
        $original = json_encode(self::sinSecretos($cuenta));
        $cuenta->estado = $estado;
        $cuenta->save();

        AuditoriaTabla::crear([
            'id_recurso' => $cuenta->id,
            'nombre_recurso' => self::class,
            'descripcion_recurso' => 'Estado de la pasarela del proveedor ' . $cuenta->proveedor_id,
            'accion' => AccionAuditoriaEnum::MODIFICAR,
            'recurso_original' => $original,
            'recurso_resultante' => json_encode(self::sinSecretos($cuenta)),
        ]);
        AvisosPasarela::notificar($cuenta->proveedor_id, $estado === 'activa' ? 'se activó' : 'se desactivó', [
            self::NOMBRES_PASARELA[$cuenta->pasarela] ?? $cuenta->pasarela,
        ]);
        return $cuenta;
    }

    // Una cuenta con pagos registrados no se borra (la historia de esos pagos depende de ella): se desactiva.
    public static function eliminar($id)
    {
        $cuenta = self::find($id);
        $conPagos = DB::table('pagos')->where('cuenta_pasarela_id', $id)->exists()
            || DB::table('pagos_liquidacion')->where('cuenta_pasarela_id', $id)->exists();
        if ($conPagos) {
            throw new Exception('Esta pasarela ya recibió pagos: desactívala en lugar de eliminarla.');
        }
        AuditoriaTabla::crear([
            'id_recurso' => $cuenta->id,
            'nombre_recurso' => self::class,
            'descripcion_recurso' => 'Pasarela del proveedor ' . $cuenta->proveedor_id,
            'accion' => AccionAuditoriaEnum::ELIMINAR,
            'recurso_original' => json_encode(self::sinSecretos($cuenta)),
        ]);
        AvisosPasarela::notificar($cuenta->proveedor_id, 'se eliminó', [self::NOMBRES_PASARELA[$cuenta->pasarela] ?? $cuenta->pasarela]);
        return $cuenta->delete();
    }

    private static function sinSecretos(self $cuenta): array
    {
        return collect($cuenta->getAttributes())->except('credenciales')->all();
    }
}
