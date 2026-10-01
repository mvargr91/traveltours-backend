<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de reservas (cliente, proveedor y administrador). El texto lo arma NotificadorReservas;
 * la plantilla emails/reserva pinta el encabezado de la marca, el resumen y el botón.
 */
class ReservaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $asunto,
        public string $titulo,
        public string $mensaje,
        public object $reserva,
        public ?string $botonTexto = null,
        public ?string $botonUrl = null,
        public bool $verContactoCliente = false,
    ) {
    }

    public function build()
    {
        $correo = $this->subject($this->asunto)->view('emails.reserva');

        // El proveedor/administrador responde directamente al viajero.
        if ($this->verContactoCliente) {
            $correo->replyTo($this->reserva->correo, $this->reserva->nombre);
        }
        return $correo;
    }
}
