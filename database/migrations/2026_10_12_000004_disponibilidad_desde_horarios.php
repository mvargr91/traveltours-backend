<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fechas de disponibilidad generadas desde los horarios semanales (App\Services\GeneradorDisponibilidad).
return new class extends Migration
{
    public function up()
    {
        // De qué horario salió la fecha; null = la creó el proveedor a mano.
        Schema::table('experiencia_disponibilidad', function (Blueprint $table) {
            $table->foreignId('horario_id')->nullable()->after('experiencia_id')
                ->constrained('experiencia_horarios')->nullOnDelete();
        });

        DB::table('parametros_constantes')->insertOrIgnore([
            'codigo_parametro' => 'DIAS_DISPONIBILIDAD',
            'descripcion_parametro' => 'Días hacia adelante para los que se crean solas las fechas de los tours con horario semanal (1 a 365).',
            'valor_parametro' => '90',
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
        Schema::table('experiencia_disponibilidad', function (Blueprint $table) {
            $table->dropConstrainedForeignId('horario_id');
        });
        DB::table('parametros_constantes')->where('codigo_parametro', 'DIAS_DISPONIBILIDAD')->delete();
    }
};
