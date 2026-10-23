<?php

namespace App\Services\Pagos;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

/**
 * Wompi (Web Checkout): el checkout se abre con los datos firmados con el secreto de integridad, para que
 * el monto no se pueda alterar en el navegador.
 *
 * Credenciales de la cuenta (panel de Wompi > Desarrolladores): llave pública, llave privada (opcional),
 * secreto de integridad y secreto de eventos. La URL de eventos de la cuenta se registra en ese mismo panel.
 */
class WompiPasarela implements PasarelaPago
{
    private const API = [
        'sandbox' => 'https://sandbox.wompi.co/v1',
        'produccion' => 'https://production.wompi.co/v1',
    ];
    private const CHECKOUT_URL = 'https://checkout.wompi.co/p/';

    // Estados de Wompi -> estados de `pagos`.
    private const ESTADOS = [
        'APPROVED' => 'aprobado',
        'DECLINED' => 'rechazado',
        'VOIDED' => 'anulado',
        'ERROR' => 'error',
        'PENDING' => 'pendiente',
    ];

    public function __construct(private array $credenciales = [], private string $ambiente = 'sandbox')
    {
    }

    public function nombre(): string
    {
        return 'Wompi';
    }

    public static function campos(): array
    {
        return [
            'public_key' => ['Llave pública', true, false, 'Empieza por pub_test_ (pruebas) o pub_prod_ (producción).'],
            'private_key' => ['Llave privada', false, true, 'Opcional. Empieza por prv_test_ o prv_prod_.'],
            'integrity_secret' => ['Secreto de integridad', true, true, 'Desarrolladores > Secretos para integración técnica.'],
            'events_secret' => ['Secreto de eventos', true, true, 'Desarrolladores > Secretos para integración técnica.'],
        ];
    }

    public function configurada(): bool
    {
        return !empty($this->credenciales['public_key']) && !empty($this->credenciales['integrity_secret']);
    }

    public function probarConexion(): array
    {
        $llave = (string) ($this->credenciales['public_key'] ?? '');
        $prefijo = $this->ambiente === 'produccion' ? 'pub_prod_' : 'pub_test_';
        if (!Str::startsWith($llave, $prefijo)) {
            return $this->fallo("La llave pública de " . ($this->ambiente === 'produccion' ? 'producción' : 'pruebas') . " debe empezar por {$prefijo}.");
        }
        try {
            // Endpoint público: devuelve el comercio dueño de la llave.
            $respuesta = Http::timeout(15)->get($this->api() . '/merchants/' . urlencode($llave));
        } catch (Throwable $e) {
            return $this->fallo('No fue posible conectarse con Wompi. Inténtalo de nuevo.');
        }
        if (!$respuesta->successful() || !isset($respuesta['data'])) {
            return $this->fallo('Wompi no reconoce la llave pública.');
        }
        return ['ok' => true, 'comercio' => $respuesta['data']['name'] ?? $respuesta['data']['legal_name'] ?? null, 'error' => null];
    }

    public function crearCheckout(array $datos): string
    {
        $firma = hash('sha256', $datos['referencia'] . $datos['monto_centavos'] . 'COP' . $this->credenciales['integrity_secret']);

        return self::CHECKOUT_URL . '?' . http_build_query([
            'public-key' => $this->credenciales['public_key'],
            'currency' => 'COP',
            'amount-in-cents' => $datos['monto_centavos'],
            'reference' => $datos['referencia'],
            'signature:integrity' => $firma,
            // Wompi rechaza redirect-url con localhost: vuelve al backend y este reenvía al front.
            'redirect-url' => $datos['retorno_url'],
            'customer-data:email' => $datos['correo'],
            'customer-data:full-name' => $datos['nombre'],
        ]);
    }

    // Endpoint público de Wompi: no necesita la llave privada.
    public function consultarTransaccion(string $id): ?array
    {
        $respuesta = Http::timeout(15)->get($this->api() . '/transactions/' . urlencode($id));
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
        $secreto = $this->credenciales['events_secret'] ?? null;
        if (!is_array($propiedades) || !$checksum || !isset($evento['timestamp']) || !$secreto) {
            return false;
        }

        $cadena = '';
        foreach ($propiedades as $ruta) {
            $cadena .= data_get($evento['data'] ?? [], $ruta);
        }
        $cadena .= $evento['timestamp'] . $secreto;

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

    private function api(): string
    {
        return self::API[$this->ambiente] ?? self::API['sandbox'];
    }

    private function fallo(string $error): array
    {
        return ['ok' => false, 'comercio' => null, 'error' => $error];
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
