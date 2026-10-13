<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Duración en días de los tours de varios días: con ella el portal y los correos muestran la fecha de regreso.
// "duracion" sigue siendo el texto que se ve en la ficha (ej. "4 días / 3 noches").
return new class extends Migration
{
    public function up()
    {
        Schema::table('experiencias', function (Blueprint $table) {
            $table->unsignedSmallInteger('duracion_dias')->nullable()->after('duracion');
        });
    }

    public function down()
    {
        Schema::table('experiencias', function (Blueprint $table) {
            $table->dropColumn('duracion_dias');
        });
    }
};
