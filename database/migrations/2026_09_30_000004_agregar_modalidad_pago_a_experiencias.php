<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cómo se reserva cada experiencia:
//  - reserva: solicitud que el proveedor acepta; se paga directamente con él.
//  - pago_en_linea: el viajero paga en el portal (la reserva queda "pendiente_pago" hasta conectar la pasarela).
return new class extends Migration
{
    public function up()
    {
        Schema::table('experiencias', function (Blueprint $table) {
            $table->string('modalidad_pago', 20)->default('reserva')->after('capacidad_maxima');
        });
    }

    public function down()
    {
        Schema::table('experiencias', function (Blueprint $table) {
            $table->dropColumn('modalidad_pago');
        });
    }
};
