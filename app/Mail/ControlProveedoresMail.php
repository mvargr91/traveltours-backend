<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Resumen semanal del control de proveedores para la administración (ControlProveedores::enviarResumenSemanal).
 */
class ControlProveedoresMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $datos,
        public string $botonUrl,
    ) {
    }

    public function build()
    {
        $conAlertas = $this->datos['resumen']['con_alertas'];
        $asunto = $conAlertas
            ? "Control de proveedores: {$conAlertas} proveedor(es) para revisar"
            : 'Control de proveedores: todo en orden esta semana';
        return $this->subject($asunto)->view('emails.control-proveedores', ['asunto' => $asunto]);
    }
}
