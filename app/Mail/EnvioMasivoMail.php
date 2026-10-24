<?php

namespace App\Mail;

use App\Services\EnviosCorreo;
use App\Models\Parametrizacion\Apariencia;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de un envío masivo (Marketing > Envíos de correo). El cuerpo ya viene personalizado
 * (EnviosCorreo::renderizar); la plantilla emails/envio-masivo pone el diseño de la marca.
 */
class EnvioMasivoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $asunto,
        public string $cuerpo,
        public string $proveedorNombre,
        public ?string $proveedorCorreo = null,
    ) {
    }

    public function build()
    {
        // Sale a nombre de la plataforma (marca de Apariencia) desde la cuenta del sistema; las respuestas le
        // llegan al proveedor. Lleva versión HTML y de texto plano (los filtros de spam desconfían de los solo HTML).
        $correo = $this->subject($this->asunto)
            ->from(config('mail.from.address'), Apariencia::nombreMarca())
            ->view('emails.envio-masivo')
            ->text('emails.envio-masivo-texto', ['textoPlano' => EnviosCorreo::textoPlano($this->cuerpo)]);

        if ($this->proveedorCorreo) {
            // Solo la dirección: el correo se presenta con la marca, no con el nombre del proveedor.
            $correo->replyTo($this->proveedorCorreo);
        }

        // Correo masivo: Gmail y Outlook esperan una forma de darse de baja (botón "Anular suscripción").
        $baja = $this->proveedorCorreo ?: config('mail.from.address');
        return $correo->withSymfonyMessage(function ($mensaje) use ($baja) {
            $mensaje->getHeaders()->addTextHeader('List-Unsubscribe', '<mailto:' . $baja . '?subject=Darme%20de%20baja>');
            $mensaje->getHeaders()->addTextHeader('Precedence', 'bulk');
        });
    }
}
