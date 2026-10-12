<?php

namespace App\Services\Pagos;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Wompi (Web Checkout): el checkout se abre con los datos firmados con el secreto de integridad, para que
 * el monto no se pueda alterar en el navegador.
 *
 * Llaves en .env: WOMPI_PUBLIC_KEY, WOMPI_PRIVATE_KEY, WOMPI_INTEGRITY_SECRET, WOMPI_EVENTS_SECRET, WOMPI_API_URL.
 */
class WompiPasarela implements PasarelaPago
{
    // Estados de Wompi -> estados de `pagos`.
    private const ESTADOS = [
        'APPROVED' => 'aprobado',
        'DECLINED' => 'rechazado',
        'VOIDED' => 'anulado',
        'ERROR' => 'error',
        'PENDING' => 'pendiente',
    ];

    public function nombre(): string
    {
        return 'Wompi';
    }

    public function configurada(): bool
    {
        $config = config('services.wompi');
        return !empty($config['public_key']) && !empty($config['integrity_secret']);
    }

    public function crearCheckout($reserva, string $referencia, int $montoCentavos): string
    {
        $config = config('services.wompi');
        $firma = hash('sha256', $referencia . $montoCentavos . 'COP' . $config['integrity_secret']);

        return $config['checkout_url'] . '?' . http_build_query([
            'public-key' => $config['public_key'],
            'currency' => 'COP',
            'amount-in-cents' => $montoCentavos,
            'reference' => $referencia,
            'signature:integrity' => $firma,
            // Wompi rechaza redirect-url con localhost: vuelve al backend y este reenvía al front.
            'redirect-url' => route('publico.pagos.retorno', ['pasarela' => 'wompi']),
            'customer-data:email' => $reserva->correo,
            'customer-data:full-name' => $reserva->nombre,
        ]);
    }

    // Endpoint público de Wompi: no necesita la llave privada.
    public function consultarTransaccion(string $id): ?array
    {
        $respuesta = Http::timeout(15)->get(config('services.wompi.api_url') . '/transactions/' . urlencode($id));
        if (!$respuesta->successful() || !isset($respuesta['data'])) {
            return null;
        }
        return $this->normalizar($respuesta['data']);
    }

    // Wompi vuelve con ?id={transacción}&env=test.
    public function idDeRetorno(Request $request): ?string
    {
        return $request->query('id');
    }

    /**
     * Firma del evento: SHA256 de los valores de signature.properties (en orden) + timestamp + secreto de eventos.
     * https://docs.wompi.co/docs/colombia/eventos/
     */
    public function verificarEvento(Request $request): bool
    {
        $evento = $request->all();
        $propiedades = $evento['signature']['properties'] ?? null;
        $checksum = $evento['signature']['checksum'] ?? null;
        if (!is_array($propiedades) || !$checksum || !isset($evento['timestamp'])) {
            return false;
        }

        $cadena = '';
        foreach ($propiedades as $ruta) {
            $cadena .= data_get($evento['data'] ?? [], $ruta);
        }
        $cadena .= $evento['timestamp'] . config('services.wompi.events_secret');

        return hash_equals(strtolower($checksum), hash('sha256', $cadena));
    }

    public function transaccionDeEvento(Request $request): ?array
    {
        $transaccion = $request->input('data.transaction');
        if ($request->input('event') !== 'transaction.updated' || !is_array($transaccion)) {
            return null;
        }
        return $this->normalizar($transaccion);
    }

    private function normalizar(array $transaccion): array
    {
        return [
            'id' => $transaccion['id'] ?? null,
            'referencia' => $transaccion['reference'] ?? '',
            'estado' => self::ESTADOS[$transaccion['status'] ?? ''] ?? 'pendiente',
            'monto_centavos' => (int) ($transaccion['amount_in_cents'] ?? 0),
            'moneda' => $transaccion['currency'] ?? '',
            'metodo_pago' => $transaccion['payment_method_type'] ?? null,
            'respuesta' => $transaccion,
        ];
    }
}
