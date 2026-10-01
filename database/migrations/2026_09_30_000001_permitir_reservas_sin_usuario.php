<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reservas de invitados desde el portal: se guardan sin usuario, solo con los datos de contacto.
return new class extends Migration
{
    public function up()
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->unsignedBigInteger('usuario_id')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->unsignedBigInteger('usuario_id')->nullable(false)->change();
        });
    }
};
