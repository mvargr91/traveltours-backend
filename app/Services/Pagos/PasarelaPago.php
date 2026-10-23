<?php

namespace App\Services\Pagos;

use Illuminate\Http\Request;

/**
 * Contrato de una pasarela de pagos. Cada instancia trabaja con las credenciales de una cuenta
 * (cuentas_pasarela: la del proveedor o la de la plataforma) y traduce su API a una transacción
 * normalizada, que es lo único que conoce PagoService:
 *
 *  [
 *      'id' => id de la transacción en la pasarela,
 *      'referencia' => referencia de `pagos` o `pagos_liquidacion`,
 *      'estado' => pendiente | aprobado | rechazado | anulado | error,
 *      'monto_centavos' => int,
 *      'moneda' => 'COP',
 *      'metodo_pago' => string|null,
 *      'respuesta' => respuesta original de la pasarela,
 *  ]
 */
interface PasarelaPago
{
    /**
     * @param array  $credenciales Llaves de la cuenta (ver campos()).
     * @param string $ambiente     sandbox | produccion
     */
    public function __construct(array $credenciales = [], string $ambiente = 'sandbox');

    // Nombre para mostrar (auditoría y logs).
    public function nombre(): string;

    /**
     * Credenciales que pide el formulario de la cuenta: campo => [etiqueta, requerido, secreto, ayuda].
     * Los secretos nunca vuelven al navegador (solo enmascarados).
     */
    public static function campos(): array;

    // Tiene las llaves necesarias para cobrar.
    public function configurada(): bool;

    /**
     * Valida las llaves contra la API de la pasarela.
     * Devuelve ['ok' => bool, 'comercio' => nombre del comercio|null, 'error' => mensaje|null].
     */
    public function probarConexion(): array;

    /**
     * URL del checkout a la que se redirige al pagador. El monto sale del servidor, nunca del navegador.
     * $datos: referencia, monto_centavos, descripcion, correo, nombre, retorno_url, notificacion_url.
     */
    public function crearCheckout(array $datos): string;

    // Consulta la transacción en la pasarela. Devuelve la transacción normalizada o null si no existe.
    public function consultarTransaccion(string $id): ?array;

    // Id de la transacción con el que la pasarela devuelve al pagador después del checkout.
    public function idDeRetorno(Request $request): ?string;

    // Firma del webhook.
    public function verificarEvento(Request $request): bool;

    // Transacción normalizada que trae el webhook, o null si el evento no es de un pago.
    public function transaccionDeEvento(Request $request): ?array;
}
