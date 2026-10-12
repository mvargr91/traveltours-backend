<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Personalización visual del sitio (Parametrización > Apariencia).
// Una sola fila (id 1): los colores, textos, menú e iconos van en "configuracion" (JSON)
// y cada imagen en su columna, con la ruta relativa al disco público (ver ArchivosService).
// Lo que esté en null usa el valor predeterminado del frontend.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apariencia', function (Blueprint $table) {
            $table->id();
            $table->json('configuracion')->nullable();
            $table->string('logo_principal')->nullable();
            $table->string('logo_oscuro')->nullable();
            $table->string('isotipo')->nullable();
            $table->string('favicon')->nullable();
            $table->string('imagen_inicio')->nullable();
            $table->string('imagen_login')->nullable();

            // Auditoria datos
            $table->bigInteger('usuario_creacion_id');
            $table->string('usuario_creacion_nombre');
            $table->bigInteger('usuario_modificacion_id');
            $table->string('usuario_modificacion_nombre');
            $table->timestamps();
        });

        DB::table('apariencia')->insert([
            'id' => 1,
            'usuario_creacion_id' => 0,
            'usuario_creacion_nombre' => 'Sistema',
            'usuario_modificacion_id' => 0,
            'usuario_modificacion_nombre' => 'Sistema',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('apariencia');
    }
};
