<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Parámetros de los correos de reservas (editables en Parametrización > Parámetros constantes).
return new class extends Migration
{
    private const PARAMETROS = [
        'NOTIFICAR_RESERVAS_CORREO' => ['SI', 'SI/NO: enviar correos al crear una reserva y al cambiar su estado.'],
        'CORREO_ADMIN_RESERVAS' => ['', 'Correos del administrador (separados por coma) con copia de cada reserva nueva. Vacío = MAIL_FROM_ADDRESS.'],
    ];

    public function up()
    {
        foreach (self::PARAMETROS as $codigo => [$valor, $descripcion]) {
            DB::table('parametros_constantes')->insertOrIgnore([
                'codigo_parametro' => $codigo,
                'descripcion_parametro' => $descripcion,
                'valor_parametro' => $valor,
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
        DB::table('parametros_constantes')->whereIn('codigo_parametro', array_keys(self::PARAMETROS))->delete();
    }
};
