<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// Opción "Generar fechas" del menú Parametrización (también está en TurismoMenuSeeder para instalaciones nuevas).
return new class extends Migration
{
    private const URL = '/generar-fechas';
    private const PERMISOS = ['Listar' => 'ListarFechasTour', 'Modificar' => 'ModificarFechasTour'];
    private const ROL_ADMINISTRADOR_ID = 1;

    public function up()
    {
        $moduloId = DB::table('modulos')->where('nombre', 'Parametrización')->value('id');
        if (!$moduloId) {
            return; // Sin el menú de turismo aún: lo crea el seeder completo.
        }

        if (!DB::table('opciones_del_sistema')->where('url', self::URL)->exists()) {
            DB::table('opciones_del_sistema')->insert([
                'nombre' => 'Generar fechas',
                'url' => self::URL,
                'modulo_id' => $moduloId,
                'posicion' => (int) DB::table('opciones_del_sistema')->where('modulo_id', $moduloId)->max('posicion') + 1,
                'icono_menu' => 'event_repeat',
                'estado' => true,
                'usuario_creacion_id' => 1,
                'usuario_creacion_nombre' => 'SuperUser',
                'usuario_modificacion_id' => 1,
                'usuario_modificacion_nombre' => 'SuperUser',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $opcionId = DB::table('opciones_del_sistema')->where('url', self::URL)->value('id');

        foreach (self::PERMISOS as $titulo => $nombre) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $nombre, 'guard_name' => 'api'],
                [
                    'option_id' => $opcionId,
                    'title' => $titulo,
                    'user_creation_id' => 1,
                    'user_creation_name' => 'SuperUser',
                    'user_modification_id' => 1,
                    'user_modification_name' => 'SuperUser',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => DB::table('permissions')->where('name', $nombre)->where('guard_name', 'api')->value('id'),
                'role_id' => self::ROL_ADMINISTRADOR_ID,
            ]);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down()
    {
        DB::table('permissions')->whereIn('name', array_values(self::PERMISOS))->where('guard_name', 'api')->delete();
        DB::table('opciones_del_sistema')->where('url', self::URL)->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
