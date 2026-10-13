<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Temporada de un horario semanal (ej. sábados del 1 de noviembre al 31 de diciembre).
// Con "vigente_hasta" se crean de una vez todas las fechas de la temporada, aunque falten meses.
return new class extends Migration
{
    public function up()
    {
        Schema::table('experiencia_horarios', function (Blueprint $table) {
            $table->date('vigente_desde')->nullable()->after('capacidad');
            $table->date('vigente_hasta')->nullable()->after('vigente_desde');
        });
    }

    public function down()
    {
        Schema::table('experiencia_horarios', function (Blueprint $table) {
            $table->dropColumn(['vigente_desde', 'vigente_hasta']);
        });
    }
};
