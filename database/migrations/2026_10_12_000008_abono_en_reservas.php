<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\NotificadorReservas;

// Abono (pago parcial) en experiencias de pago en línea: el viajero separa el cupo con un porcentaje
// y paga el saldo después. Cada pago sigue siendo un registro de `pagos`, ahora con su concepto.
return new class extends Migration
{
    private const PLANTILLA = 'RESERVA_CLIENTE_PAGO_RECIBIDO';

    public function up()
    {
        // Porcentaje mínimo para separar el cupo (1 a 99). Null = solo pago total.
        Schema::table('experiencias', function (Blueprint $table) {
            $table->unsignedTinyInteger('porcentaje_abono')->nullable()->after('modalidad_pago');
        });
        Schema::table('reservas', function (Blueprint $table) {
            $table->decimal('valor_pagado', 12, 2)->default(0)->after('valor_total');
        });
        // total | abono | saldo
        Schema::table('pagos', function (Blueprint $table) {
            $table->string('concepto', 10)->default('total')->after('pasarela');
        });

        // Las reservas que ya se pagaron en línea quedan con su valor pagado.
        DB::statement("UPDATE reservas SET valor_pagado = (
            SELECT COALESCE(SUM(p.monto_centavos), 0) / 100 FROM pagos p WHERE p.reserva_id = reservas.id AND p.estado = 'aprobado'
        )");

        if (!DB::table('parametros_correos')->where('nombre', self::PLANTILLA)->exists()) {
            [$asunto, $texto] = NotificadorReservas::PLANTILLAS[self::PLANTILLA];
            DB::table('parametros_correos')->insert([
                'nombre' => self::PLANTILLA,
                'asunto' => $asunto,
                'texto' => $texto,
                'parametros' => NotificadorReservas::VARIABLES,
                'estado' => 1,
                'usuario_creacion_id' => 0,
                'usuario_creacion_nombre' => 'Sistema',
                'usuario_modificacion_id' => 0,
                'usuario_modificacion_nombre' => 'Sistema',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down()
    {
        DB::table('parametros_correos')->where('nombre', self::PLANTILLA)->delete();
        Schema::table('pagos', fn (Blueprint $table) => $table->dropColumn('concepto'));
        Schema::table('reservas', fn (Blueprint $table) => $table->dropColumn('valor_pagado'));
        Schema::table('experiencias', fn (Blueprint $table) => $table->dropColumn('porcentaje_abono'));
    }
};
