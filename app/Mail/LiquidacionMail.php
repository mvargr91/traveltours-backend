<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de una liquidación al proveedor (emitida, vencida, pagada, suspensión por mora). El texto lo arma
 * CobroProveedores; la plantilla emails/liquidacion pinta el resumen y el botón al panel.
 */
class LiquidacionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $asunto,
        public string $titulo,
        public string $mensaje,
        public array $liquidacion,
        public string $botonUrl,
    ) {
    }

    public function build()
    {
        return $this->subject($this->asunto)->view('emails.liquidacion');
    }
}
