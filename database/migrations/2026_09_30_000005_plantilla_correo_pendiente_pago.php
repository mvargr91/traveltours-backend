<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Services\NotificadorReservas;

// Plantilla del correo al viajero cuando la experiencia es de pago en línea (reserva "pendiente_pago").
return new class extends Migration
{
    private const NOMBRE = 'RESERVA_CLIENTE_PENDIENTE_PAGO';

    public function up()
    {
        if (DB::table('parametros_correos')->where('nombre', self::NOMBRE)->exists()) {
            return;
        }
        [$asunto, $texto] = NotificadorReservas::PLANTILLAS[self::NOMBRE];
        DB::table('parametros_correos')->insert([
            'nombre' => self::NOMBRE,
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

    public function down()
    {
        DB::table('parametros_correos')->where('nombre', self::NOMBRE)->delete();
    }
};
