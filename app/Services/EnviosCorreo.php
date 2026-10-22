<?php

namespace App\Services;

use Carbon\Carbon;
use App\Jobs\EnviarCorreoMasivo;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Correos masivos de un proveedor a sus clientes (mismo esquema que el ERP de academias):
 * calcula los destinatarios de una audiencia y crea el envío con un trabajo en cola por
 * destinatario (cada correo sale personalizado con sus variables).
 *
 * Los clientes de un proveedor son las personas que le han reservado (reservas.correo, sin repetir
 * correos): incluye a los viajeros con cuenta y a los invitados del portal.
 */
class EnviosCorreo
{
    public const AUDIENCIAS = [
        'todos' => 'Todos los clientes',
        'experiencia' => 'Clientes de una experiencia',
        'proximas' => 'Clientes con una reserva próxima',
        'seleccion' => 'Clientes seleccionados',
    ];

    public const VARIABLES = [
        'nombre' => 'Nombre del cliente',
        'proveedor' => 'Nombre del proveedor',
        'experiencia' => 'Experiencia (la del envío o la última que reservó)',
    ];

    // Reservas que siguen activas: las de "reserva próxima".
    private const ESTADOS_ACTIVOS = ['pendiente', 'pendiente_pago', 'aceptada'];

    /** Límite diario aproximado de los servidores de correo compartidos: se advierte antes de superarlo. */
    public const LIMITE_DIARIO = 450;

    // Imágenes del contenido (disco público) y ancho útil del cuerpo del correo (560 - márgenes).
    public const CARPETA_IMAGENES = 'envios-correo';
    private const ANCHO_CORREO = 496;

    /**
     * Clientes del proveedor según la audiencia: [nombre, correo, variables].
     * Por cada correo se toma la reserva más reciente (su nombre y su experiencia).
     */
    public static function destinatarios(int $proveedorId, string $audiencia, array $filtro = []): Collection
    {
        $proveedor = DB::table('proveedores_turisticos')->where('id', $proveedorId)->value('nombre_comercial') ?? '';

        $reservas = DB::table('reservas')
            ->join('experiencias', 'experiencias.id', '=', 'reservas.experiencia_id')
            ->where('reservas.proveedor_id', $proveedorId)
            ->whereNotNull('reservas.correo')
            ->where('reservas.correo', '!=', '');

        switch ($audiencia) {
            case 'experiencia':
                $reservas->where('reservas.experiencia_id', $filtro['experiencia_id'] ?? 0);
                break;
            case 'proximas':
                $reservas->whereIn('reservas.estado', self::ESTADOS_ACTIVOS)
                    ->where('reservas.fecha', '>=', Carbon::now()->toDateString());
                break;
            case 'seleccion':
                $correos = array_map(fn ($c) => strtolower(trim($c)), $filtro['correos'] ?? []);
                $reservas->whereIn(DB::raw('LOWER(reservas.correo)'), $correos ?: ['']);
                break;
        }

        return $reservas
            ->orderByDesc('reservas.created_at')
            ->get(['reservas.nombre', 'reservas.correo', 'experiencias.nombre as experiencia'])
            ->unique(fn ($r) => strtolower(trim($r->correo)))
            ->sortBy('nombre', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn ($r) => [
                'nombre' => $r->nombre,
                'correo' => trim($r->correo),
                'variables' => [
                    'nombre' => $r->nombre,
                    'proveedor' => $proveedor,
                    'experiencia' => $r->experiencia,
                ],
            ])
            ->values();
    }

    /** Clientes del proveedor para elegirlos uno a uno (audiencia "seleccion"). */
    public static function clientes(int $proveedorId): Collection
    {
        return self::destinatarios($proveedorId, 'todos')
            ->map(fn ($d) => ['id' => $d['correo'], 'nombre' => "{$d['nombre']} <{$d['correo']}>"]);
    }

    /** Resumen previo: cuántos reciben el correo y si se supera el límite diario. */
    public static function resumen(int $proveedorId, string $audiencia, array $filtro = []): array
    {
        $total = self::destinatarios($proveedorId, $audiencia, $filtro)->count();
        return [
            'total' => $total,
            'supera_limite' => $total > self::LIMITE_DIARIO,
            'limite' => self::LIMITE_DIARIO,
        ];
    }

    /** Crea el envío y encola un correo por destinatario. Devuelve el id del envío. */
    public static function enviar(array $datos, object $usuario): int
    {
        $destinatarios = self::destinatarios($datos['proveedor_id'], $datos['audiencia'], $datos['filtro'] ?? []);
        $datos['texto'] = self::limpiarHtml($datos['texto']);
        $ahora = Carbon::now();

        $envioId = DB::transaction(function () use ($datos, $usuario, $destinatarios, $ahora) {
            $envioId = DB::table('envios_correo')->insertGetId([
                'proveedor_id' => $datos['proveedor_id'],
                'asunto' => $datos['asunto'],
                'texto' => $datos['texto'],
                'audiencia' => $datos['audiencia'],
                'filtro' => json_encode($datos['filtro'] ?? []),
                'total' => $destinatarios->count(),
                'estado' => $destinatarios->isEmpty() ? 'completado' : 'enviando',
                'usuario_creacion_id' => $usuario->id,
                'usuario_creacion_nombre' => $usuario->nombre,
                'usuario_modificacion_id' => $usuario->id,
                'usuario_modificacion_nombre' => $usuario->nombre,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
            $filas = $destinatarios->map(fn ($d) => [
                'envio_id' => $envioId,
                'nombre' => mb_substr($d['nombre'], 0, 200),
                'correo' => $d['correo'],
                'variables' => json_encode($d['variables'], JSON_UNESCAPED_UNICODE),
                'estado' => 'pendiente',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ])->all();
            foreach (array_chunk($filas, 500) as $bloque) {
                DB::table('envio_correo_destinatarios')->insert($bloque);
            }
            return $envioId;
        });

        DB::table('envio_correo_destinatarios')->where('envio_id', $envioId)->pluck('id')
            ->each(fn ($id) => EnviarCorreoMasivo::dispatch((int) $id));

        return $envioId;
    }

    // Quita del HTML del editor lo que no debe ir en un correo (scripts, marcos, eventos on*, javascript:).
    private static function limpiarHtml(string $html): string
    {
        $html = preg_replace('#<(script|style|iframe|object|embed|form)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = preg_replace('#<(script|iframe|object|embed)\b[^>]*/?>#i', '', $html);
        $html = preg_replace('#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        return preg_replace('#\b(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', '$1="#"', $html);
    }

    /** Guarda una imagen del contenido de un envío (disco público) y devuelve su URL. */
    public static function guardarImagen(UploadedFile $imagen): string
    {
        $ruta = $imagen->storeAs(
            self::CARPETA_IMAGENES . '/' . Carbon::now()->format('Y-m'),
            Str::uuid() . '.' . strtolower($imagen->getClientOriginalExtension() ?: $imagen->extension()),
            'public'
        );
        return Storage::disk('public')->url($ruta);
    }

    /**
     * Incrusta en el correo (CID) las imágenes subidas desde el editor: así se ven en cualquier cliente de
     * correo aunque el backend no sea público (en local Gmail no puede descargar http://traveltours.test).
     * Las imágenes externas quedan enlazadas. Todas se ajustan al ancho del correo (Outlook usa el atributo width).
     * $message solo existe al enviar; sin él (vista previa) las imágenes quedan con su URL.
     */
    public static function incrustarImagenes(string $html, $message = null): string
    {
        $carpeta = realpath(storage_path('app/public/' . self::CARPETA_IMAGENES));

        return preg_replace_callback('#<img\b[^>]*>#i', function ($m) use ($message, $carpeta) {
            $etiqueta = $m[0];
            preg_match('#\bsrc\s*=\s*["\']([^"\']+)["\']#i', $etiqueta, $src);
            preg_match('#\bwidth\s*=\s*["\']?(\d+)#i', $etiqueta, $ancho);
            preg_match('#\balt\s*=\s*["\']([^"\']*)["\']#i', $etiqueta, $alt);
            if (empty($src[1])) {
                return '';
            }

            $url = $src[1];
            // Imagen propia: lo que va después de /storage/envios-correo/ (sin importar el dominio con que se subió).
            if ($message && $carpeta && preg_match('#/storage/' . self::CARPETA_IMAGENES . '/([A-Za-z0-9/_.-]+)$#', parse_url($url, PHP_URL_PATH) ?? '', $rel)) {
                $archivo = realpath($carpeta . DIRECTORY_SEPARATOR . $rel[1]);
                if ($archivo && str_starts_with($archivo, $carpeta) && is_file($archivo)) {
                    $url = $message->embed($archivo);
                }
            }

            // Con ancho conocido (CKEditor lo pone) se limita al del correo; sin ancho no se agranda.
            $ancho = !empty($ancho[1]) ? min((int) $ancho[1], self::ANCHO_CORREO) : null;
            return '<img src="' . e($url) . '" alt="' . e($alt[1] ?? '') . '"' . ($ancho ? ' width="' . $ancho . '"' : '')
                . ' style="display:block; max-width:100%;' . ($ancho ? ' width:' . $ancho . 'px;' : '') . ' height:auto; margin:8px auto; border:0;">';
        }, $html);
    }

    /**
     * Versión en texto plano del cuerpo HTML (parte text/plain del correo). Los filtros de spam desconfían
     * de los correos que solo traen HTML. Los enlaces quedan como "texto (url)" y las imágenes por su descripción.
     */
    public static function textoPlano(string $html): string
    {
        $texto = preg_replace_callback('#<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function ($m) {
            $etiqueta = trim(strip_tags($m[2]));
            return ($etiqueta && $etiqueta !== $m[1]) ? "{$etiqueta} ({$m[1]})" : $m[1];
        }, $html);
        $texto = preg_replace_callback('#<img\b[^>]*>#i', function ($m) {
            preg_match('#\balt\s*=\s*["\']([^"\']+)["\']#i', $m[0], $alt);
            return !empty($alt[1]) ? "[{$alt[1]}]" : '';
        }, $texto);
        $texto = preg_replace('#<li\b[^>]*>#i', "\n- ", $texto);
        $texto = preg_replace('#<br\s*/?>|</(p|div|h[1-6]|ul|ol|figure|blockquote|tr)>#i', "\n", $texto);
        $texto = html_entity_decode(strip_tags($texto), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = preg_replace("/[ \t\x{00A0}]+/u", ' ', $texto);
        $texto = preg_replace("/ *\n */", "\n", $texto);

        return trim(preg_replace("/\n{3,}/", "\n\n", $texto));
    }

    /** Reemplaza {variable} en el asunto (texto plano) y en el cuerpo (HTML, con los valores escapados). */
    public static function renderizar(string $asunto, string $cuerpo, array $variables): array
    {
        $reemplazar = fn (string $texto, bool $html) => preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($variables, $html) {
            if (!array_key_exists($m[1], $variables)) {
                return $m[0];
            }
            $valor = (string) ($variables[$m[1]] ?? '');
            return $html ? e($valor) : $valor;
        }, $texto);

        return ['asunto' => $reemplazar($asunto, false), 'html' => $reemplazar($cuerpo, true)];
    }
}
