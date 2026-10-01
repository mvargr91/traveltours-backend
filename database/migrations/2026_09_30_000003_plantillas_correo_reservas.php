<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Services\NotificadorReservas;

// Plantillas editables de los correos de reservas (Parametrización > Parámetros de correo).
return new class extends Migration
{
    public function up()
    {
        foreach (NotificadorReservas::PLANTILLAS as $nombre => [$asunto, $texto]) {
            if (DB::table('parametros_correos')->where('nombre', $nombre)->exists()) {
                continue;
            }
            DB::table('parametros_correos')->insert([
                'nombre' => $nombre,
                'asunto' => $asunto,
                'texto' => $texto,
                'parametros' => NotificadorReservas::VARIABLES,
                'estado' => 1,
                'usuario_creacion_id' => 0,
                'usuario_creacion_nombre' => 'Sistema',
                'usuario_modificacion_id' => 0,
                'usuario_modificacion_nombre' => 'Sistema',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down()
    {
        DB::table('parametros_correos')->whereIn('nombre', array_keys(NotificadorReservas::PLANTILLAS))->delete();
    }
};
