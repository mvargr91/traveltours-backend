<?php

namespace App\Services\Pagos;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

/**
 * Mercado Pago (Checkout Pro): se crea una "preferencia" con el monto y Mercado Pago devuelve la URL del
 * checkout. El webhook solo trae el id del pago: el detalle siempre se consulta en la API con el access token.
 *
 * Llaves en .env: MERCADOPAGO_ACCESS_TOKEN, MERCADOPAGO_WEBHOOK_SECRET.
 */
class MercadoPagoPasarela implements PasarelaPago
{
    // Estados de Mercado Pago -> estados de `pagos`.
    private const ESTADOS = [
        'approved' => 'aprobado',
        'authorized' => 'pendiente',
        'pending' => 'pendiente',
        'in_process' => 'pendiente',
        'in_mediation' => 'pendiente',
        'rejected' => 'rechazado',
        'cancelled' => 'anulado',
        'refunded' => 'anulado',
        'charged_back' => 'anulado',
    ];

    public function nombre(): string
    {
        return 'Mercado Pago';
    }

    public function configurada(): bool
    {
        return !empty(config('services.mercadopago.access_token'));
    }

    public function crearCheckout($reserva, string $referencia, int $montoCentavos): string
    {
        $retorno = route('publico.pagos.retorno', ['pasarela' => 'mercadopago']);
        $preferencia = [
            'items' => [[
                'title' => 'Reserva ' . $reserva->codigo_reserva,
                'quantity' => 1,
                'unit_price' => $montoCentavos / 100,
                'currency_id' => 'COP',
            ]],
            'payer' => ['email' => $reserva->correo, 'name' => $reserva->nombre],
            'external_reference' => $referencia,
            'back_urls' => ['success' => $retorno, 'failure' => $retorno, 'pending' => $retorno],
        ];
        // Mercado Pago solo acepta auto_return con URLs https: en local el viajero vuelve con el botón "Volver al sitio".
        if (Str::startsWith($retorno, 'https://')) {
            $preferencia['auto_return'] = 'approved';
        }

        $respuesta = $this->api()->post('/checkout/preferences', $preferencia)->throw();
        return $respuesta['init_point'];
    }

    public function consultarTransaccion(string $id): ?array
    {
        $respuesta = $this->api()->get('/v1/payments/' . urlencode($id));
        if ($respuesta->status() === 404) {
            return null;
        }
        $pago = $respuesta->throw()->json();

        return [
            'id' => (string) $pago['id'],
            'referencia' => $pago['external_reference'] ?? '',
            'estado' => self::ESTADOS[$pago['status'] ?? ''] ?? 'pendiente',
            'monto_centavos' => (int) round(((float) ($pago['transaction_amount'] ?? 0)) * 100),
            'moneda' => $pago['currency_id'] ?? '',
            'metodo_pago' => $pago['payment_type_id'] ?? null,
            'respuesta' => $pago,
        ];
    }

    // Mercado Pago vuelve con ?payment_id=...&status=...&external_reference=... (payment_id=null si no pagó).
    public function idDeRetorno(Request $request): ?string
    {
        $id = (string) ($request->query('payment_id') ?? $request->query('collection_id'));
        return ctype_digit($id) ? $id : null;
    }

    /**
     * Firma del webhook: cabecera x-signature (ts=...,v1=...), donde v1 es el HMAC-SHA256 con el secreto de
     * "id:{data.id};request-id:{x-request-id};ts:{ts};".
     * https://www.mercadopago.com.co/developers/es/docs/your-integrations/notifications/webhooks
     */
    public function verificarEvento(Request $request): bool
    {
        $secreto = config('services.mercadopago.webhook_secret');
        $firma = (string) $request->header('x-signature');
        if (!$secreto || !preg_match('/ts=(\d+)/', $firma, $ts) || !preg_match('/v1=([a-f0-9]+)/i', $firma, $v1)) {
            return false;
        }

        $manifiesto = '';
        if ($id = $this->idDeEvento($request)) {
            $manifiesto .= 'id:' . strtolower($id) . ';';
        }
        if ($requestId = $request->header('x-request-id')) {
            $manifiesto .= "request-id:{$requestId};";
        }
        $manifiesto .= "ts:{$ts[1]};";

        return hash_equals(hash_hmac('sha256', $manifiesto, $secreto), strtolower($v1[1]));
    }

    public function transaccionDeEvento(Request $request): ?array
    {
        $id = $this->idDeEvento($request);
        if ($request->input('type') !== 'payment' || !$id) {
            return null;
        }
        return $this->consultarTransaccion($id);
    }

    // El id llega en la URL como ?data.id=... (PHP lo convierte en data_id) y en el cuerpo como data.id.
    private function idDeEvento(Request $request): ?string
    {
        $id = $request->query('data_id') ?? $request->input('data.id');
        return $id === null ? null : (string) $id;
    }

    private function api()
    {
        return Http::timeout(15)
            ->baseUrl(config('services.mercadopago.api_url'))
            ->withToken(config('services.mercadopago.access_token'));
    }
}
