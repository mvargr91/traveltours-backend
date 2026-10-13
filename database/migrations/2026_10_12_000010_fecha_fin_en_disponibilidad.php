<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Salidas de varios días con su propio rango (ej. del 9 al 12): "fecha" es la salida y "fecha_fin" el regreso.
// Null = la salida dura lo que diga experiencias.duracion_dias (o un solo día).
return new class extends Migration
{
    public function up()
    {
        Schema::table('experiencia_disponibilidad', function (Blueprint $table) {
            $table->date('fecha_fin')->nullable()->after('fecha');
        });
    }

    public function down()
    {
        Schema::table('experiencia_disponibilidad', function (Blueprint $table) {
            $table->dropColumn('fecha_fin');
        });
    }
};
