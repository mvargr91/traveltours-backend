<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Cupones que el viajero puede usar con un código:
//  - codigo: lo que escribe el viajero (único, en mayúsculas).
//  - proveedor_id: dueño del cupón; vacío = cupón de la plataforma (lo crea el administrador).
//  - cupon_experiencia: si tiene experiencias, solo aplica a ellas; si no, a todas las del proveedor (o a todas).
//  - reservas.valor_descuento_cupon: cuánto descontó el cupón en la reserva (para reportes y para el viajero).
// Los usos no se guardan: se cuentan las reservas con el cupón que no están canceladas ni rechazadas.
return new class extends Migration
{
    public function up()
    {
        Schema::table('cupones', function (Blueprint $table) {
            $table->string('codigo', 40)->nullable()->after('id');
            $table->foreignId('proveedor_id')->nullable()->after('codigo')
                ->references('id')->on('proveedores_turisticos')->nullOnDelete();
        });

        // Los cupones que ya existan reciben un código a partir de su id.
        DB::table('cupones')->whereNull('codigo')->orderBy('id')->each(function ($cupon) {
            DB::table('cupones')->where('id', $cupon->id)->update(['codigo' => 'CUPON' . $cupon->id]);
        });

        Schema::table('cupones', function (Blueprint $table) {
            $table->string('codigo', 40)->nullable(false)->change();
            $table->unique('codigo');
        });

        Schema::create('cupon_experiencia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cupon_id')->references('id')->on('cupones')->cascadeOnDelete();
            $table->foreignId('experiencia_id')->references('id')->on('experiencias')->cascadeOnDelete();
            $table->unique(['cupon_id', 'experiencia_id']);
        });

        Schema::table('reservas', function (Blueprint $table) {
            $table->decimal('valor_descuento_cupon', 12, 2)->default(0)->after('cupon_id');
        });
    }

    public function down()
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropColumn('valor_descuento_cupon');
        });
        Schema::dropIfExists('cupon_experiencia');
        Schema::table('cupones', function (Blueprint $table) {
            $table->dropUnique(['codigo']);
            $table->dropConstrainedForeignId('proveedor_id');
            $table->dropColumn('codigo');
        });
    }
};
