<?php

namespace App\Services;

use Throwable;
use App\Mail\AvisoSeguridadMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Models\Parametrizacion\ParametroConstante;

use function Illuminate\Support\defer;

/**
 * Aviso de seguridad cada vez que cambia la pasarela de pago de un proveedor (crear, cambiar llaves, activar,
 * desactivar, eliminar). Va al proveedor dueño y a la administración (CORREO_ADMIN_RESERVAS o MAIL_FROM_ADDRESS):
 * quien cambia las llaves cambia a dónde llega el dinero, así que un cambio que nadie hizo se detecta enseguida.
 * Nunca incluye las llaves: solo qué campos cambiaron, quién, cuándo y desde qué IP.
 * Se envía después de responder (defer); un fallo del correo queda en el log y no deshace el cambio.
 */
class AvisosPasarela
{
    public static function notificar($proveedorId, string $accion, array $detalle = [])
    {
        $usuario = Auth::user()?->usuario();
        $datos = [
            'accion' => $accion,
            'detalle' => $detalle,
            'responsable' => $usuario->nombre ?? 'Sistema',
            'fecha' => now()->format('Y-m-d H:i'),
            'ip' => request()?->ip(),
        ];

        $tarea = function () use ($proveedorId, $datos) {
            $proveedor = DB::table('proveedores_turisticos')
                ->leftJoin('usuarios', 'usuarios.id', '=', 'proveedores_turisticos.usuario_id')
                ->where('proveedores_turisticos.id', $proveedorId)
                ->select('proveedores_turisticos.nombre_comercial', 'proveedores_turisticos.correo', 'usuarios.correo_electronico')
                ->first();
            if (!$proveedor) {
                return;
            }
            $lista = trim(ParametroConstante::cargarParametros()['CORREO_ADMIN_RESERVAS'] ?? '') ?: config('mail.from.address');
            $destinos = collect(explode(',', (string) $lista))
                ->push($proveedor->correo ?: $proveedor->correo_electronico)
                ->map(fn ($c) => trim((string) $c))
                ->filter(fn ($c) => filter_var($c, FILTER_VALIDATE_EMAIL))
                ->unique(fn ($c) => strtolower($c));

            foreach ($destinos as $correo) {
                try {
                    Mail::to($correo)->send(new AvisoSeguridadMail(
                        "Aviso de seguridad: {$datos['accion']} la pasarela de pago de {$proveedor->nombre_comercial}",
                        $proveedor->nombre_comercial,
                        $datos,
                        config('app.front_url') . '/pasarelas-pago',
                    ));
                } catch (Throwable $e) {
                    Log::warning("No se pudo enviar el aviso de cambio de pasarela a {$correo}: {$e->getMessage()}");
                }
            }
        };
        app()->runningInConsole() ? $tarea() : defer($tarea);
    }
}
