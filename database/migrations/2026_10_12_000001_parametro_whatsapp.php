<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Número de WhatsApp del portal (editable en Parametrización > Parámetros constantes).
return new class extends Migration
{
    private const PARAMETROS = [
        'WHATSAPP_NUMERO' => ['', 'Número de WhatsApp del portal con indicativo del país, sin + ni espacios (ej. 573001234567). Vacío = sin botón de WhatsApp.'],
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
