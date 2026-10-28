<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use App\Services\ControlProveedores;

/**
 * Control de proveedores (Finanzas > Control de proveedores, solo administración):
 *  - Parámetros del cierre automático de reservas, de la alerta de cancelaciones y del resumen semanal por correo.
 *  - Opción del menú y su permiso (ListarControlProveedor). También está en TurismoMenuSeeder (instalaciones nuevas).
 */
return new class extends Migration
{
    private const ROL_ADMINISTRADOR_ID = 1;
    private const URL = '/control-proveedores';
    private const PERMISO = 'ListarControlProveedor';

    public function up()
    {
        foreach (ControlProveedores::PARAMETROS as $codigo => [$valor, $descripcion]) {
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

        $moduloId = DB::table('modulos')->where('nombre', 'Finanzas')->value('id');
        if (!$moduloId) {
            return; // Sin el menú de Finanzas aún: lo crean los seeders completos.
        }
        $auditoria = [
            'usuario_creacion_id' => 1,
            'usuario_creacion_nombre' => 'SuperUser',
            'usuario_modificacion_id' => 1,
            'usuario_modificacion_nombre' => 'SuperUser',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('opciones_del_sistema')->updateOrInsert(
            ['url' => self::URL],
            array_merge($auditoria, [
                'nombre' => 'Control de proveedores',
                'modulo_id' => $moduloId,
                'posicion' => (int) DB::table('opciones_del_sistema')->where('modulo_id', $moduloId)->max('posicion') + 1,
                'icono_menu' => 'fact_check',
                'estado' => true,
            ])
        );
        $opcionId = DB::table('opciones_del_sistema')->where('url', self::URL)->value('id');
        DB::table('permissions')->updateOrInsert(
            ['name' => self::PERMISO, 'guard_name' => 'api'],
            [
                'option_id' => $opcionId,
                'title' => 'Listar',
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
        DB::table('opciones_del_sistema')->where('url', self::URL)->delete();
        DB::table('parametros_constantes')->whereIn('codigo_parametro', array_keys(ControlProveedores::PARAMETROS))->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
