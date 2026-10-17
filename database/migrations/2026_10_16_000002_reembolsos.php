<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use App\Services\NotificadorReservas;

// Reembolsos manuales: el administrador devuelve el dinero por fuera del portal y lo registra en la reserva.
// Cada reembolso es un registro de `pagos` con concepto "reembolso"; reservas.valor_reembolsado lleva la suma.
// Solo el rol con el permiso ReembolsarReserva (por defecto el administrador) puede registrarlos.
return new class extends Migration
{
    private const PLANTILLA = 'RESERVA_CLIENTE_REEMBOLSO';
    private const PERMISO = 'ReembolsarReserva';
    private const ROL_ADMINISTRADOR_ID = 1;

    public function up()
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->decimal('valor_reembolsado', 12, 2)->default(0)->after('valor_pagado');
        });

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

        $opcionId = DB::table('opciones_del_sistema')->where('url', '/reservas')->value('id');
        if (!$opcionId) {
            return; // Sin el menú de turismo aún: lo crea TurismoMenuSeeder.
        }
        DB::table('permissions')->updateOrInsert(
            ['name' => self::PERMISO, 'guard_name' => 'api'],
            [
                'option_id' => $opcionId,
                'title' => 'Reembolsar',
                'user_creation_id' => 1,
                'user_creation_name' => 'SuperUser',
                'user_modification_id' => 1,
                'user_modification_name' => 'SuperUser',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DB::table('role_has_permissions')->insertOrIgnore([
            'permission_id' => DB::table('permissions')->where('name', self::PERMISO)->where('guard_name', 'api')->value('id'),
            'role_id' => self::ROL_ADMINISTRADOR_ID,
        ]);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down()
    {
        DB::table('permissions')->where('name', self::PERMISO)->where('guard_name', 'api')->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        DB::table('parametros_correos')->where('nombre', self::PLANTILLA)->delete();
        Schema::table('reservas', fn (Blueprint $table) => $table->dropColumn('valor_reembolsado'));
    }
};
