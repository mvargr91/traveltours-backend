<?php

namespace App\Services;

use Throwable;
use App\Mail\ReservaMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Models\Parametrizacion\ParametroConstante;

use function Illuminate\Support\defer;

/**
 * Correos de reservas:
 *  - Reserva creada: al viajero (solicitud recibida), al proveedor (nueva reserva) y al administrador (copia).
 *  - Cambio de estado: al viajero; si la reserva se cancela, también al proveedor.
 *
 * Asunto y texto salen de parametros_correos (Parametrización > Parámetros de correo), buscados por
 * nombre (las claves de PLANTILLAS). El texto es HTML (CKEditor, igual que recuperar contraseña) y va
 * dentro del diseño fijo de emails/reserva. Una plantilla inactiva apaga ese correo; si no existe se usa
 * el texto de PLANTILLAS. Variables: ver VARIABLES (sus valores se escapan antes de insertarlos).
 *
 * Se envían después de responder (defer) y cada envío va en su try/catch: un fallo del SMTP
 * queda en el log pero nunca deshace ni retrasa la reserva.
 * Parámetros: NOTIFICAR_RESERVAS_CORREO (SI/NO) y CORREO_ADMIN_RESERVAS (lista separada por coma).
 */
class NotificadorReservas
{
    public const VARIABLES = '{nombre} {codigo} {experiencia} {fecha} {hora} {personas} {total} {proveedor}';

    // nombre => [asunto, texto HTML, título del correo]
    public const PLANTILLAS = [
        'RESERVA_CLIENTE_CREADA' => [
            'Recibimos tu solicitud de reserva {codigo}',
            '<p>Recibimos tu solicitud para <strong>{experiencia}</strong> el {fecha} a las {hora}.</p><p>{proveedor} la revisará y te avisaremos por este medio cuando la confirme.</p>',
            '¡Hola, {nombre}!',
        ],
        'RESERVA_CLIENTE_PENDIENTE_PAGO' => [
            'Tu reserva {codigo} está pendiente de pago',
            '<p>Separamos tu cupo para <strong>{experiencia}</strong> el {fecha} a las {hora}.</p><p>Esta experiencia se paga en línea: el total es <strong>{total}</strong>. Usa el botón para pagar y confirmar tu reserva.</p>',
            '¡Hola, {nombre}!',
        ],
        'RESERVA_EQUIPO_NUEVA' => [
            'Nueva reserva {codigo} · {experiencia}',
            '<p><strong>{nombre}</strong> solicitó {personas} cupo(s) para el {fecha} a las {hora}.</p><p>Revísala y acéptala o recházala desde el panel.</p>',
            'Tienes una nueva reserva',
        ],
        'RESERVA_CLIENTE_ACEPTADA' => [
            '¡Tu reserva {codigo} está confirmada!',
            '<p>{proveedor} aceptó tu reserva. Te esperamos en el punto de encuentro el <strong>{fecha} a las {hora}</strong>.</p>',
            '¡Tu reserva está confirmada!',
        ],
        'RESERVA_CLIENTE_RECHAZADA' => [
            'Tu reserva {codigo} no pudo ser aceptada',
            '<p>{proveedor} no puede atender esta reserva. Puedes elegir otra fecha u otra experiencia en nuestro portal.</p>',
            'Tu reserva no pudo ser aceptada',
        ],
        'RESERVA_CLIENTE_CANCELADA' => [
            'Tu reserva {codigo} fue cancelada',
            '<p>Esta reserva quedó cancelada. Si no fuiste tú, comunícate con el proveedor.</p>',
            'Tu reserva fue cancelada',
        ],
        'RESERVA_CLIENTE_FINALIZADA' => [
            '¡Gracias por viajar con nosotros!',
            '<p>Tu experiencia <strong>{experiencia}</strong> ya se realizó. Esperamos que la hayas disfrutado.</p>',
            '¡Gracias por viajar con nosotros!',
        ],
        'RESERVA_EQUIPO_CANCELADA' => [
            'Reserva cancelada {codigo}',
            '<p>La reserva de <strong>{nombre}</strong> para el {fecha} a las {hora} quedó cancelada.</p>',
            'Se canceló una reserva',
        ],
    ];

    public static function reservaCreada($reservaId)
    {
        self::diferir(function () use ($reservaId) {
            $reserva = self::cargar($reservaId);
            if (!$reserva) {
                return;
            }

            self::enviar($reserva->correo, self::correo(
                $reserva->estado === 'pendiente_pago' ? 'RESERVA_CLIENTE_PENDIENTE_PAGO' : 'RESERVA_CLIENTE_CREADA',
                $reserva,
                $reserva->estado === 'pendiente_pago' ? 'Pagar ahora' : 'Consultar mi reserva',
                self::urlFront('/reserva?codigo=' . $reserva->codigo_reserva),
            ));

            // Un Mailable acumula destinatarios al enviarse: se arma uno nuevo por destinatario.
            $paraEquipo = fn () => self::correo(
                'RESERVA_EQUIPO_NUEVA',
                $reserva,
                'Gestionar reserva',
                self::urlFront("/reservas/{$reserva->id}/ver"),
                true,
            );
            $proveedor = self::correoProveedor($reserva);
            self::enviar($proveedor, $paraEquipo());
            foreach (array_diff(self::correosAdmin(), [$proveedor]) as $correo) {
                self::enviar($correo, $paraEquipo());
            }
        });
    }

    public static function estadoCambiado($reservaId, $estadoAnterior)
    {
        self::diferir(function () use ($reservaId, $estadoAnterior) {
            $reserva = self::cargar($reservaId);
            $plantilla = 'RESERVA_CLIENTE_' . strtoupper($reserva->estado ?? '');
            if (!$reserva || $reserva->estado === $estadoAnterior || !isset(self::PLANTILLAS[$plantilla])) {
                return;
            }

            $rechazada = $reserva->estado === 'rechazada';
            self::enviar($reserva->correo, self::correo(
                $plantilla,
                $reserva,
                $rechazada ? 'Ver otras experiencias' : 'Consultar mi reserva',
                $rechazada ? self::urlFront('/tours') : self::urlFront('/reserva?codigo=' . $reserva->codigo_reserva),
            ));

            if ($reserva->estado === 'cancelada') {
                self::enviar(self::correoProveedor($reserva), self::correo(
                    'RESERVA_EQUIPO_CANCELADA',
                    $reserva,
                    'Ver reserva',
                    self::urlFront("/reservas/{$reserva->id}/ver"),
                    true,
                ));
            }
        });
    }

    // Arma el correo con la plantilla de BD (o la de PLANTILLAS). Null si la plantilla está inactiva.
    private static function correo($nombre, $reserva, $botonTexto, $botonUrl, $paraEquipo = false)
    {
        [$asunto, $texto, $titulo] = self::PLANTILLAS[$nombre];
        $guardada = DB::table('parametros_correos')->where('nombre', $nombre)->select('asunto', 'texto', 'estado')->first();
        if ($guardada && !$guardada->estado) {
            return null;
        }
        if ($guardada) {
            [$asunto, $texto] = [$guardada->asunto, $guardada->texto];
        }

        $variables = [
            '{nombre}' => $reserva->nombre,
            '{codigo}' => $reserva->codigo_reserva,
            '{experiencia}' => $reserva->experiencia_nombre,
            '{fecha}' => $reserva->fecha,
            '{hora}' => $reserva->hora_inicio ? substr($reserva->hora_inicio, 0, 5) : '',
            '{personas}' => $reserva->cantidad_personas,
            '{total}' => '$ ' . number_format((float) $reserva->valor_total, 0, ',', '.'),
            '{proveedor}' => $reserva->proveedor_nombre,
        ];

        // El texto es HTML: los datos que escribió el viajero se escapan para no inyectar marcado.
        $variablesHtml = array_map(fn ($valor) => e((string) $valor), $variables);

        return new ReservaMail(
            strtr($asunto, $variables),
            strtr($titulo, $variables),
            strtr($texto, $variablesHtml),
            $reserva,
            $botonTexto,
            $botonUrl,
            $paraEquipo,
        );
    }


    private static function diferir(callable $tarea)
    {
        $parametros = ParametroConstante::cargarParametros();
        if (strtoupper(trim($parametros['NOTIFICAR_RESERVAS_CORREO'] ?? 'SI')) === 'NO') {
            return;
        }
        // En consola (artisan, tinker) no hay respuesta HTTP que esperar: se envía de una vez.
        app()->runningInConsole() ? $tarea() : defer($tarea);
    }

    private static function enviar($correo, ?ReservaMail $mail)
    {
        if (!$mail || !$correo || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        try {
            Mail::to($correo)->send($mail);
        } catch (Throwable $e) {
            Log::warning("No se pudo enviar el correo de la reserva {$mail->reserva->codigo_reserva} a {$correo}: {$e->getMessage()}");
        }
    }

    private static function cargar($reservaId)
    {
        return DB::table('reservas')
            ->join('experiencias', 'experiencias.id', '=', 'reservas.experiencia_id')
            ->join('proveedores_turisticos', 'proveedores_turisticos.id', '=', 'reservas.proveedor_id')
            ->leftJoin('usuarios as usuario_proveedor', 'usuario_proveedor.id', '=', 'proveedores_turisticos.usuario_id')
            ->where('reservas.id', $reservaId)
            ->select(
                'reservas.id',
                'reservas.codigo_reserva',
                'reservas.estado',
                'reservas.fecha',
                'reservas.hora_inicio',
                'reservas.cantidad_personas',
                'reservas.valor_total',
                'reservas.nombre',
                'reservas.correo',
                'reservas.telefono',
                'reservas.observaciones',
                'experiencias.nombre as experiencia_nombre',
                'experiencias.punto_encuentro',
                'proveedores_turisticos.nombre_comercial as proveedor_nombre',
                'proveedores_turisticos.telefono as proveedor_telefono',
                'proveedores_turisticos.correo as proveedor_correo',
                'usuario_proveedor.correo_electronico as proveedor_correo_usuario',
            )
            ->first();
    }

    private static function correoProveedor($reserva)
    {
        return $reserva->proveedor_correo ?: $reserva->proveedor_correo_usuario;
    }

    private static function correosAdmin()
    {
        $parametros = ParametroConstante::cargarParametros();
        $lista = trim($parametros['CORREO_ADMIN_RESERVAS'] ?? '') ?: config('mail.from.address');
        return array_filter(array_map('trim', explode(',', (string) $lista)));
    }

    private static function urlFront($ruta)
    {
        return rtrim(env('APP_FRONT_URL', ''), '/') . $ruta;
    }
}
