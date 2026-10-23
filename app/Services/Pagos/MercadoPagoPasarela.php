<?php

namespace App\Services\Pagos;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

/**
 * Mercado Pago (Checkout Pro): se crea una "preferencia" con el monto y Mercado Pago devuelve la URL del
 * checkout. El webhook solo trae el id del pago: el detalle siempre se consulta en la API con el access token
 * de la cuenta, así que un evento falso no puede aprobar nada.
 *
 * Credenciales de la cuenta (Tus integraciones > Credenciales): access token y, opcional, la clave secreta de
 * webhooks. La URL de notificación va en cada preferencia: no hay que registrarla en el panel.
 */
class MercadoPagoPasarela implements PasarelaPago
{
    private const API = 'https://api.mercadopago.com';

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

    // Las credenciales de prueba y de producción usan la misma API: el ambiente es informativo.
    public function __construct(private array $credenciales = [], private string $ambiente = 'sandbox')
    {
    }

    public function nombre(): string
    {
        return 'Mercado Pago';
    }

    public static function campos(): array
    {
        return [
            'access_token' => ['Access token', true, true, 'Tus integraciones > Credenciales. Empieza por APP_USR- o TEST-.'],
            'webhook_secret' => ['Clave secreta de webhooks', false, true, 'Opcional: si la configuras, se valida la firma de cada notificación.'],
        ];
    }

    public function configurada(): bool
    {
        return !empty($this->credenciales['access_token']);
    }

    public function probarConexion(): array
    {
        if (!$this->configurada()) {
            return ['ok' => false, 'comercio' => null, 'error' => 'Falta el access token.'];
        }
        try {
            $respuesta = $this->api()->get('/users/me');
        } catch (Throwable $e) {
            return ['ok' => false, 'comercio' => null, 'error' => 'No fue posible conectarse con Mercado Pago. Inténtalo de nuevo.'];
        }
        if (!$respuesta->successful()) {
            return ['ok' => false, 'comercio' => null, 'error' => 'Mercado Pago no reconoce el access token.'];
        }
        $usuario = $respuesta->json();
        $nombre = trim(($usuario['first_name'] ?? '') . ' ' . ($usuario['last_name'] ?? ''));
        return ['ok' => true, 'comercio' => $usuario['nickname'] ?? ($nombre ?: null), 'error' => null];
    }

    public function crearCheckout(array $datos): string
    {
        $retorno = $datos['retorno_url'];
        $preferencia = [
            'items' => [[
                'title' => $datos['descripcion'],
                'quantity' => 1,
                'unit_price' => $datos['monto_centavos'] / 100,
                'currency_id' => 'COP',
            ]],
            'payer' => ['email' => $datos['correo'], 'name' => $datos['nombre']],
            'external_reference' => $datos['referencia'],
            'back_urls' => ['success' => $retorno, 'failure' => $retorno, 'pending' => $retorno],
            'notification_url' => $datos['notificacion_url'],
        ];
        // Mercado Pago solo acepta auto_return y notification_url con URLs https: en local el pagador vuelve
        // con el botón "Volver al sitio" y el pago se confirma con la consulta del portal.
        if (Str::startsWith($retorno, 'https://')) {
            $preferencia['auto_return'] = 'approved';
        }
        if (!Str::startsWith($datos['notificacion_url'], 'https://')) {
            unset($preferencia['notification_url']);
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
     * Sin clave secreta en la cuenta se acepta el evento: el pago igual se consulta en la API con el token.
     */
    public function verificarEvento(Request $request): bool
    {
        $secreto = $this->credenciales['webhook_secret'] ?? null;
        if (!$secreto) {
            return true;
        }
        $firma = (string) $request->header('x-signature');
        if (!preg_match('/ts=(\d+)/', $firma, $ts) || !preg_match('/v1=([a-f0-9]+)/i', $firma, $v1)) {
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
        $tipo = $request->input('type') ?? $request->query('topic');
        if ($tipo !== 'payment' || !$id) {
            return null;
        }
        return $this->consultarTransaccion($id);
    }

    // El id llega en la URL como ?data.id=... (PHP lo convierte en data_id) o ?id=..., y en el cuerpo como data.id.
    private function idDeEvento(Request $request): ?string
    {
        $id = $request->query('data_id') ?? $request->input('data.id') ?? $request->query('id');
        return $id === null ? null : (string) $id;
    }

    private function api()
    {
        return Http::timeout(15)
            ->baseUrl(self::API)
            ->withToken((string) ($this->credenciales['access_token'] ?? ''));
    }
}
