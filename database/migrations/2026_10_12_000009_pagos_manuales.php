<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Pagos recibidos por fuera del portal (efectivo, transferencia, datáfono) que el proveedor o el administrador
// registran en la reserva. Van en `pagos` con pasarela = "manual", junto a los de la pasarela en línea.
return new class extends Migration
{
    public function up()
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->date('fecha_pago')->nullable()->after('metodo_pago');
            $table->string('nota', 255)->nullable()->after('fecha_pago');
            $table->string('registrado_por', 128)->nullable()->after('nota');
            $table->string('anulado_por', 128)->nullable()->after('registrado_por');
            $table->timestamp('anulado_en')->nullable()->after('anulado_por');
        });
    }

    public function down()
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropColumn(['fecha_pago', 'nota', 'registrado_por', 'anulado_por', 'anulado_en']);
        });
    }
};
