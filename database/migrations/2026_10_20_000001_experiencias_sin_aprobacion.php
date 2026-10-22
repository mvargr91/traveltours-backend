<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Las experiencias ya no pasan por aprobación: cada proveedor o administrador las publica directamente.
// Los estados del flujo anterior (en_revision, aprobada, rechazada) dejan de existir.
return new class extends Migration
{
    public function up()
    {
        DB::table('experiencias')->whereIn('estado', ['en_revision', 'aprobada'])->update(['estado' => 'publicada']);
        DB::table('experiencias')->where('estado', 'rechazada')->update(['estado' => 'borrador']);
    }

    public function down()
    {
        // Sin reversa: no se puede saber qué experiencias estaban en revisión o aprobadas.
    }
};
