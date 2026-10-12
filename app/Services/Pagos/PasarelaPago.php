<?php

namespace App\Services\Pagos;

use Illuminate\Http\Request;

/**
 * Contrato de una pasarela de pagos. Cada pasarela traduce su API a una transacción normalizada, que es
 * lo único que conoce PagoService:
 *
 *  [
 *      'id' => id de la transacción en la pasarela,
 *      'referencia' => referencia de `pagos`,
 *      'estado' => pendiente | aprobado | rechazado | anulado | error,
 *      'monto_centavos' => int,
 *      'moneda' => 'COP',
 *      'metodo_pago' => string|null,
 *      'respuesta' => respuesta original de la pasarela,
 *  ]
 */
interface PasarelaPago
{
    // Nombre para mostrar (auditoría y logs).
    public function nombre(): string;

    // Tiene las llaves necesarias en .env.
    public function configurada(): bool;

    // URL del checkout a la que se redirige al viajero. El monto sale de la reserva, nunca del navegador.
    public function crearCheckout($reserva, string $referencia, int $montoCentavos): string;

    // Consulta la transacción en la pasarela. Devuelve la transacción normalizada o null si no existe.
    public function consultarTransaccion(string $id): ?array;

    // Id de la transacción con el que la pasarela devuelve al viajero después del checkout.
    public function idDeRetorno(Request $request): ?string;

    // Firma del webhook.
    public function verificarEvento(Request $request): bool;

    // Transacción normalizada que trae el webhook, o null si el evento no es de un pago.
    public function transaccionDeEvento(Request $request): ?array;
}
