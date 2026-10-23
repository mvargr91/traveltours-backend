<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso de seguridad por un cambio en la pasarela de pago de un proveedor (AvisosPasarela).
 */
class AvisoSeguridadMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $asunto,
        public string $proveedorNombre,
        public array $datos,
        public string $botonUrl,
    ) {
    }

    public function build()
    {
        return $this->subject($this->asunto)->view('emails.aviso-seguridad');
    }
}
