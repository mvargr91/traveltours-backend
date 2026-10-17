<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// Opciones nuevas del menú (también están en TurismoMenuSeeder y PortalRolesSeeder para instalaciones nuevas):
//  - Inicio > Dashboard (administrador y proveedor) y Reportes (administrador).
//  - Mi Cuenta > Mis Reseñas (cliente) y Mis Documentos (proveedor).
//  - El proveedor consulta las reseñas de sus experiencias (ListarResena).
// El módulo Inicio va de primero: tras ingresar, el panel abre la primera opción del menú (el dashboard).
return new class extends Migration
{
    private const ROL_ADMINISTRADOR_ID = 1;

    // modulo => [icono, posicion, opciones[nombre, url, icono, entidad, permisos, roles]]
    private const MENU = [
        'Inicio' => ['space_dashboard', 0, [
            ['Dashboard', '/dashboard', 'insights', 'Dashboard', ['Listar'], ['Administrador', 'Proveedor']],
            ['Reportes', '/reportes', 'assessment', 'Reporte', ['Listar'], ['Administrador']],
        ]],
        'Mi Cuenta' => ['account_circle', 1, [
            ['Mis Reseñas', '/mis-resenas', 'rate_review', 'MiResena', ['Listar', 'Crear', 'Modificar', 'Eliminar'], ['Cliente']],
            ['Mis Documentos', '/mis-documentos', 'description', 'MiDocumento', ['Listar', 'Crear', 'Modificar', 'Eliminar'], ['Proveedor']],
        ]],
    ];

    public function up()
    {
        $aplicacionId = DB::table('aplicaciones')->orderBy('id')->value('id');
        if (!$aplicacionId || !DB::table('opciones_del_sistema')->where('url', '/reservas')->exists()) {
            return; // Sin el menú de turismo aún: lo crean los seeders completos.
        }
        $auditoria = [
            'usuario_creacion_id' => 1,
            'usuario_creacion_nombre' => 'SuperUser',
            'usuario_modificacion_id' => 1,
            'usuario_modificacion_nombre' => 'SuperUser',
        ];

        foreach (self::MENU as $nombreModulo => [$iconoModulo, $posicionModulo, $opciones]) {
            $moduloId = DB::table('modulos')->where('nombre', $nombreModulo)->where('aplicacion_id', $aplicacionId)->value('id');
            if (!$moduloId) {
                $moduloId = DB::table('modulos')->insertGetId(array_merge($auditoria, [
                    'nombre' => $nombreModulo,
                    'aplicacion_id' => $aplicacionId,
                    'icono_menu' => $iconoModulo,
                    'posicion' => $posicionModulo,
                    'estado' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            foreach ($opciones as [$nombreOpcion, $url, $iconoOpcion, $entidad, $permisos, $roles]) {
                if (!DB::table('opciones_del_sistema')->where('url', $url)->exists()) {
                    DB::table('opciones_del_sistema')->insert(array_merge($auditoria, [
                        'nombre' => $nombreOpcion,
                        'url' => $url,
                        'modulo_id' => $moduloId,
                        'posicion' => (int) DB::table('opciones_del_sistema')->where('modulo_id', $moduloId)->max('posicion') + 1,
                        'icono_menu' => $iconoOpcion,
                        'estado' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));
                }
                $opcionId = DB::table('opciones_del_sistema')->where('url', $url)->value('id');

                foreach ($permisos as $titulo) {
                    $permisoId = $this->permiso($titulo . $entidad, $titulo, $opcionId);
                    foreach ($roles as $rol) {
                        $this->conceder($permisoId, $rol);
                    }
                }
            }
        }

        $this->conceder(DB::table('permissions')->where('name', 'ListarResena')->where('guard_name', 'api')->value('id'), 'Proveedor');

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down()
    {
        $permisos = [];
        foreach (self::MENU as [, , $opciones]) {
            foreach ($opciones as [, $url, , $entidad, $titulos]) {
                foreach ($titulos as $titulo) {
                    $permisos[] = $titulo . $entidad;
                }
                DB::table('opciones_del_sistema')->where('url', $url)->delete();
            }
        }
        DB::table('permissions')->whereIn('name', $permisos)->where('guard_name', 'api')->delete();
        DB::table('modulos')->where('nombre', 'Inicio')->delete();

        $proveedorRolId = DB::table('roles')->where('name', 'Proveedor')->where('guard_name', 'api')->value('id');
        $listarResenaId = DB::table('permissions')->where('name', 'ListarResena')->where('guard_name', 'api')->value('id');
        DB::table('role_has_permissions')->where('role_id', $proveedorRolId)->where('permission_id', $listarResenaId)->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function permiso($nombre, $titulo, $opcionId)
    {
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
        return DB::table('permissions')->where('name', $nombre)->where('guard_name', 'api')->value('id');
    }

    // "Administrador" es el rol 1 (como en los demás seeders); los del portal se buscan por nombre.
    private function conceder($permisoId, $rol)
    {
        $rolId = $rol === 'Administrador'
            ? self::ROL_ADMINISTRADOR_ID
            : DB::table('roles')->where('name', $rol)->where('guard_name', 'api')->value('id');
        if ($permisoId && $rolId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permisoId, 'role_id' => $rolId]);
        }
    }
};
