<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Pagos en línea (Wompi). Cada intento de pago de una reserva es un registro con su referencia única.
return new class extends Migration
{
    public function up()
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reserva_id')->references('id')->on('reservas')->onDelete('cascade');
            $table->string('pasarela', 20)->default('wompi');
            $table->string('referencia', 60)->unique();
            $table->unsignedBigInteger('monto_centavos');
            $table->string('moneda', 3)->default('COP');
            // pendiente | aprobado | rechazado | anulado | error
            $table->string('estado', 20)->default('pendiente');
            $table->string('transaccion_id', 60)->nullable()->index();
            $table->string('metodo_pago', 40)->nullable();
            $table->json('respuesta')->nullable();
            $table->timestamps();
        });

        // Evita descontar (o devolver) dos veces los cupos de una reserva pagada.
        Schema::table('reservas', function (Blueprint $table) {
            $table->boolean('cupos_descontados')->default(false)->after('estado');
        });

        // Con la pasarela conectada el correo ya lleva el botón "Pagar ahora" (solo si nadie editó la plantilla).
        DB::table('parametros_correos')
            ->where('nombre', 'RESERVA_CLIENTE_PENDIENTE_PAGO')
            ->where('usuario_modificacion_nombre', 'Sistema')
            ->update(['texto' => '<p>Separamos tu cupo para <strong>{experiencia}</strong> el {fecha} a las {hora}.</p><p>Esta experiencia se paga en línea: el total es <strong>{total}</strong>. Usa el botón para pagar y confirmar tu reserva.</p>']);
    }

    public function down()
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropColumn('cupos_descontados');
        });
        Schema::dropIfExists('pagos');
    }
};
