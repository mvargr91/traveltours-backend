<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Registro de proveedores: tipo de servicio, categorías del proveedor, logo y fotos del negocio,
// y la cuenta bancaria donde recibe sus pagos (solo la ven el administrador y el propio proveedor).
return new class extends Migration
{
    public function up()
    {
        Schema::table('proveedores_turisticos', function (Blueprint $table) {
            $table->string('tipo_servicio', 40)->nullable()->after('nit');
            $table->string('logo', 255)->nullable()->after('descripcion');
            $table->string('banco', 100)->nullable()->after('rnt');
            $table->string('tipo_cuenta', 20)->nullable()->after('banco');
            $table->string('numero_cuenta', 40)->nullable()->after('tipo_cuenta');
            $table->string('titular_cuenta', 150)->nullable()->after('numero_cuenta');
            $table->string('documento_titular', 50)->nullable()->after('titular_cuenta');
        });

        Schema::create('proveedor_categoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->references('id')->on('proveedores_turisticos')->onDelete('cascade');
            $table->foreignId('categoria_id')->references('id')->on('categorias')->onDelete('cascade');
            $table->unique(['proveedor_id', 'categoria_id']);
        });

        Schema::create('proveedor_fotos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->references('id')->on('proveedores_turisticos')->onDelete('cascade');
            $table->string('ruta_archivo', 255);
            $table->unsignedSmallInteger('posicion')->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('proveedor_fotos');
        Schema::dropIfExists('proveedor_categoria');
        Schema::table('proveedores_turisticos', function (Blueprint $table) {
            $table->dropColumn(['tipo_servicio', 'logo', 'banco', 'tipo_cuenta', 'numero_cuenta', 'titular_cuenta', 'documento_titular']);
        });
    }
};
