<?php

namespace App\Models\Parametrizacion;

use Exception;
use Carbon\Carbon;
use App\Enum\AccionAuditoriaEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use App\Models\Seguridad\AuditoriaTabla;
use App\Services\ArchivosService;
use Illuminate\Support\Facades\Storage;

/**
 * Personalización visual del sitio: una sola fila (id 1).
 * "configuracion" guarda colores, textos, menú e iconos; las imágenes van en columnas propias.
 */
class Apariencia extends Model
{
    public const ID = 1;

    // Columnas con la ruta de cada imagen (también son el nombre del campo en la petición).
    public const IMAGENES = [
        'logo_principal',
        'logo_oscuro',
        'isotipo',
        'favicon',
        'imagen_inicio',
        'imagen_login',
    ];

    public const CLAVES_MENU = ['inicio', 'tours', 'destinos', 'promociones', 'mapa'];

    protected $table = 'apariencia';

    protected $fillable = [
        'configuracion',
        'logo_principal',
        'logo_oscuro',
        'isotipo',
        'favicon',
        'imagen_inicio',
        'imagen_login',
        'usuario_creacion_id',
        'usuario_creacion_nombre',
        'usuario_modificacion_id',
        'usuario_modificacion_nombre',
    ];

    protected $casts = [
        'configuracion' => 'array',
    ];

    /**
     * Reglas de "configuracion". Solo se guarda lo que aparece aquí (validated()): los colores
     * se insertan en estilos del portal, así que deben ser hexadecimales (#RRGGBB) y los iconos
     * nombres de Material Icons.
     */
    public static function reglas()
    {
        $color = ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $icono = ['nullable', 'string', 'regex:/^[a-z0-9_]{1,40}$/'];
        // Enlaces que el portal pinta como href/src: solo http(s).
        $enlace = ['nullable', 'string', 'max:300', 'regex:/^https?:\/\/\S+$/i'];

        return [
            'configuracion' => 'present|array',

            'configuracion.marca' => 'array',
            'configuracion.marca.nombre' => 'nullable|string|max:80',
            'configuracion.marca.eslogan' => 'nullable|string|max:120',
            'configuracion.marca.descripcion' => 'nullable|string|max:300',
            'configuracion.marca.pie' => 'nullable|string|max:300',

            // Contacto y redes sociales del pie de página. El WhatsApp es el parámetro WHATSAPP_NUMERO.
            'configuracion.contacto' => 'array',
            'configuracion.contacto.correo' => 'nullable|email|max:120',
            'configuracion.contacto.telefono' => 'nullable|string|max:30',
            'configuracion.contacto.direccion' => 'nullable|string|max:150',
            'configuracion.contacto.facebook' => $enlace,
            'configuracion.contacto.instagram' => $enlace,
            'configuracion.contacto.tiktok' => $enlace,
            'configuracion.contacto.youtube' => $enlace,
            'configuracion.contacto.x' => $enlace,

            'configuracion.portal' => 'array',
            'configuracion.portal.primario' => $color,
            'configuracion.portal.primarioOscuro' => $color,
            'configuracion.portal.mostrarFranja' => 'boolean',
            'configuracion.portal.franja' => 'array|min:2|max:8',
            'configuracion.portal.franja.*' => $color,
            'configuracion.portal.claro' => 'array',
            'configuracion.portal.claro.*' => $color,
            'configuracion.portal.oscuro' => 'array',
            'configuracion.portal.oscuro.*' => $color,
            'configuracion.portal.menu' => 'array|max:' . count(self::CLAVES_MENU),
            'configuracion.portal.menu.*.clave' => 'required|string|distinct|in:' . implode(',', self::CLAVES_MENU),
            'configuracion.portal.menu.*.etiqueta' => 'required|string|max:30',
            'configuracion.portal.menu.*.color' => $color,
            'configuracion.portal.menu.*.icono' => $icono,
            'configuracion.portal.menu.*.visible' => 'boolean',

            'configuracion.inicio' => 'array',
            'configuracion.inicio.textoHero' => $color,
            // Video de fondo del encabezado (enlace directo a un .mp4 o .webm); vacío = solo la imagen.
            'configuracion.inicio.video' => $enlace,
            'configuracion.inicio.oscurecer' => 'numeric|between:0,0.9',
            'configuracion.inicio.propuestas' => 'array|max:6',
            'configuracion.inicio.propuestas.*.icono' => $icono,
            'configuracion.inicio.propuestas.*.titulo' => 'required|string|max:60',
            'configuracion.inicio.propuestas.*.texto' => 'nullable|string|max:200',

            // Textos del portal por página: textos.{página}.{clave} (catálogo en TextosPortal.jsx del frontend).
            'configuracion.textos' => 'array',
            'configuracion.textos.*' => 'array',
            'configuracion.textos.*.*' => 'nullable|string|max:300',

            'configuracion.panel' => 'array',
            'configuracion.panel.principal' => $color,
            'configuracion.panel.acento' => $color,
            'configuracion.panel.encabezado' => $color,
            'configuracion.panel.peligro' => $color,
            'configuracion.panel.menu' => 'array',
            'configuracion.panel.menu.*' => $color,
            'configuracion.panel.claro' => 'array',
            'configuracion.panel.claro.*' => $color,
            'configuracion.panel.oscuro' => 'array',
            'configuracion.panel.oscuro.*' => $color,
        ];
    }

    private static function registro()
    {
        return Apariencia::find(self::ID) ?? new Apariencia(['id' => self::ID]);
    }

    /**
     * Lo que consume el frontend (portal y panel). No incluye datos de auditoría: es público.
     */
    public static function cargar()
    {
        $apariencia = self::registro();

        $imagenes = [];
        foreach (self::IMAGENES as $columna) {
            $imagenes[$columna] = $apariencia->{$columna};
        }

        return [
            // Objeto vacío (no arreglo) cuando aún no se ha personalizado nada.
            'configuracion' => empty($apariencia->configuracion) ? (object) [] : $apariencia->configuracion,
            'imagenes' => $imagenes,
            'contacto' => ['whatsapp' => self::whatsapp()],
            'fecha_modificacion' => $apariencia->updated_at
                ? (new Carbon($apariencia->updated_at))->format('Y-m-d H:i:s')
                : null,
        ];
    }

    // Logo fijo de los correos cuando no se ha subido uno en Apariencia (o el subido no se puede usar).
    private const LOGO_CORREO_DEFECTO = 'images/correo/logo.png';
    private const NOMBRE_DEFECTO = 'Travel City LGTBIQ+';
    private static ?array $logoCorreo = null;

    /**
     * Logo que se incrusta en los correos (emails.partials.logo): el logo principal subido en Apariencia o,
     * sin él, el fijo de resources/images/correo. Devuelve [ruta absoluta, texto alternativo].
     * Los clientes de correo (Outlook) no muestran WebP: un logo WebP se convierte a PNG una vez y se reutiliza.
     * Se calcula una vez por petición o por trabajo de la cola.
     */
    public static function logoCorreo(): array
    {
        if (self::$logoCorreo !== null) {
            return self::$logoCorreo;
        }
        $apariencia = self::registro();
        $nombre = trim((string) ($apariencia->configuracion['marca']['nombre'] ?? '')) ?: self::NOMBRE_DEFECTO;
        $ruta = resource_path(self::LOGO_CORREO_DEFECTO);

        $subido = $apariencia->logo_principal;
        $disco = Storage::disk(ArchivosService::PUBLICO);
        if (ArchivosService::esLocal($subido) && $disco->exists($subido)) {
            $archivo = $disco->path($subido);
            $extension = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
            if (in_array($extension, ['png', 'jpg', 'jpeg'], true)) {
                $ruta = $archivo;
            } elseif ($extension === 'webp') {
                $ruta = self::webpAPng($archivo) ?? $ruta;
            }
        }
        return self::$logoCorreo = [$ruta, $nombre];
    }

    // PNG del logo WebP en storage/app/correo (el nombre cambia si cambia el archivo). Null si no se puede convertir.
    private static function webpAPng(string $archivo): ?string
    {
        $destino = storage_path('app/correo/logo-' . md5($archivo . filemtime($archivo)) . '.png');
        if (is_file($destino)) {
            return $destino;
        }
        if (!function_exists('imagecreatefromwebp') || !($imagen = @imagecreatefromwebp($archivo))) {
            return null;
        }
        if (!is_dir(dirname($destino))) {
            @mkdir(dirname($destino), 0755, true);
        }
        imagealphablending($imagen, false);
        imagesavealpha($imagen, true);
        $ok = @imagepng($imagen, $destino);
        imagedestroy($imagen);
        return $ok ? $destino : null;
    }

    // Número de WhatsApp del portal: parámetro WHATSAPP_NUMERO, solo dígitos. Vacío o inactivo = sin botón.
    private static function whatsapp()
    {
        $numero = DB::table('parametros_constantes')
            ->where('codigo_parametro', 'WHATSAPP_NUMERO')
            ->where('estado', 1)
            ->value('valor_parametro');

        return preg_replace('/\D+/', '', (string) $numero);
    }

    /**
     * Guarda la configuración y deja en null las imágenes de $quitar (vuelven a la predeterminada).
     * Las imágenes nuevas las adjunta el controlador con ArchivosService.
     */
    public static function guardar($configuracion, $quitar = [])
    {
        $apariencia = self::registro();
        $original = $apariencia->toJson();

        $usuario = Auth::user()?->usuario();
        $dto = [
            'configuracion' => empty($configuracion) ? null : $configuracion,
            'usuario_modificacion_id' => $usuario->id ?? 0,
            'usuario_modificacion_nombre' => $usuario->nombre ?? 'Sistema',
        ];
        if (!$apariencia->exists) {
            $apariencia->id = self::ID;
            $dto['usuario_creacion_id'] = $dto['usuario_modificacion_id'];
            $dto['usuario_creacion_nombre'] = $dto['usuario_modificacion_nombre'];
        }
        foreach (array_intersect(self::IMAGENES, $quitar ?? []) as $columna) {
            $dto[$columna] = null;
        }

        $apariencia->fill($dto);
        if (!$apariencia->save()) {
            throw new Exception('Ocurrió un error al intentar guardar la apariencia.');
        }

        // Guardar auditoria
        AuditoriaTabla::crear([
            'id_recurso' => $apariencia->id,
            'nombre_recurso' => Apariencia::class,
            'descripcion_recurso' => 'Apariencia del sitio',
            'accion' => AccionAuditoriaEnum::MODIFICAR,
            'recurso_original' => $original,
            'recurso_resultante' => $apariencia->toJson(),
        ]);

        return self::cargar();
    }

    /** Vuelve a la apariencia predeterminada: sin configuración ni imágenes propias. */
    public static function restablecer()
    {
        return self::guardar(null, self::IMAGENES);
    }
}
