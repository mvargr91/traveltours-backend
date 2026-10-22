<?php

namespace App\Jobs;

use Throwable;
use Carbon\Carbon;
use App\Mail\EnvioMasivoMail;
use App\Services\EnviosCorreo;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Envía el correo de un envío masivo a un destinatario, personalizado con sus variables.
 * Sale a nombre del proveedor y las respuestas le llegan a él (reply-to con su correo).
 */
class EnviarCorreoMasivo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $destinatarioId)
    {
    }

    public function handle(): void
    {
        $d = DB::table('envio_correo_destinatarios')->find($this->destinatarioId);
        if (!$d || $d->estado === 'enviado') {
            return;
        }
        $envio = DB::table('envios_correo')->find($d->envio_id);
        $proveedor = DB::table('proveedores_turisticos')->where('id', $envio->proveedor_id)->first(['nombre_comercial', 'correo']);
        $correo = EnviosCorreo::renderizar($envio->asunto, $envio->texto, json_decode($d->variables ?? '{}', true) ?: []);

        try {
            Mail::to($d->correo, $d->nombre)->send(new EnvioMasivoMail(
                $correo['asunto'],
                $correo['html'],
                $proveedor->nombre_comercial ?? config('app.name'),
                $proveedor->correo ?? null,
            ));
            DB::table('envio_correo_destinatarios')->where('id', $d->id)
                ->update(['estado' => 'enviado', 'error' => null, 'enviado_en' => Carbon::now(), 'updated_at' => Carbon::now()]);
        } catch (Throwable $e) {
            DB::table('envio_correo_destinatarios')->where('id', $d->id)
                ->update(['estado' => 'error', 'error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => Carbon::now()]);
        }

        // Cuando no quedan pendientes, el envío queda completado.
        if (!DB::table('envio_correo_destinatarios')->where('envio_id', $d->envio_id)->where('estado', 'pendiente')->exists()) {
            DB::table('envios_correo')->where('id', $d->envio_id)->update(['estado' => 'completado', 'updated_at' => Carbon::now()]);
        }
    }
}
