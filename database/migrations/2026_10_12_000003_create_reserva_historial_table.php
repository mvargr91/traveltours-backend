<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Historial de estados de cada reserva: quién la pasó a qué estado y cuándo.
return new class extends Migration
{
    public function up()
    {
        Schema::create('reserva_historial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reserva_id')->references('id')->on('reservas')->onDelete('cascade');
            $table->string('estado_anterior', 30)->nullable();
            $table->string('estado', 30);
            $table->bigInteger('usuario_id')->nullable();
            $table->string('usuario_nombre', 128);
            $table->timestamp('created_at')->nullable();
        });

        // Las reservas que ya existían arrancan su historial con el estado que tienen hoy.
        DB::statement("INSERT INTO reserva_historial (reserva_id, estado_anterior, estado, usuario_id, usuario_nombre, created_at)
            SELECT id, NULL, estado, NULL, 'Sistema', updated_at FROM reservas");
    }

    public function down()
    {
        Schema::dropIfExists('reserva_historial');
    }
};
