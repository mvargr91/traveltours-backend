<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Moneda de las experiencias (COP o USD) y TRM para convertir a pesos lo vendido en dólares.
//  - experiencias.moneda: moneda de todos sus precios.
//  - reservas.moneda: la de la experiencia al crearse la reserva (no cambia si la experiencia cambia de moneda).
//  - comisiones.moneda_reserva / trm: la comisión siempre es en pesos; si la reserva era en dólares queda la TRM usada.
// Los PDF (itinerario, políticas…) son experiencia_multimedia de tipo "documento": no requieren columnas nuevas.
return new class extends Migration
{
    private const PARAMETROS = [
        'TRM_USD' => ['4000', 'Pesos colombianos por 1 dólar (TRM). Convierte a pesos las comisiones de las experiencias en dólares y su precio en los filtros del portal.'],
    ];

    public function up()
    {
        Schema::table('experiencias', function (Blueprint $table) {
            $table->string('moneda', 3)->default('COP')->after('precio_desde');
        });
        Schema::table('reservas', function (Blueprint $table) {
            $table->string('moneda', 3)->default('COP')->after('valor_total');
        });
        Schema::table('comisiones', function (Blueprint $table) {
            $table->string('moneda_reserva', 3)->default('COP')->after('base');
            $table->decimal('trm', 12, 2)->nullable()->after('moneda_reserva');
        });

        foreach (self::PARAMETROS as $codigo => [$valor, $descripcion]) {
            DB::table('parametros_constantes')->insertOrIgnore([
                'codigo_parametro' => $codigo,
                'descripcion_parametro' => $descripcion,
                'valor_parametro' => $valor,
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
        DB::table('parametros_constantes')->whereIn('codigo_parametro', array_keys(self::PARAMETROS))->delete();

        Schema::table('comisiones', function (Blueprint $table) {
            $table->dropColumn(['moneda_reserva', 'trm']);
        });
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropColumn('moneda');
        });
        Schema::table('experiencias', function (Blueprint $table) {
            $table->dropColumn('moneda');
        });
    }
};
