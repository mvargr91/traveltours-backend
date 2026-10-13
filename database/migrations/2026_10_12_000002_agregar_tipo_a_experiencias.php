<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tipo de experiencia (grupal, privada, personalizada, autoguiada): filtro del portal aparte de la categoría.
return new class extends Migration
{
    public function up()
    {
        Schema::table('experiencias', function (Blueprint $table) {
            $table->string('tipo', 30)->nullable()->after('idioma');
        });
    }

    public function down()
    {
        Schema::table('experiencias', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
