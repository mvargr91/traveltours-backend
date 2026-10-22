<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// Inicio > Reportes también para el proveedor: ve solo los datos de su negocio (ReporteController aplica
// el alcance desde el token); la administración sigue viendo todo. También está en PortalRolesSeeder.
return new class extends Migration
{
    public function up()
    {
        $permisoId = DB::table('permissions')->where('name', 'ListarReporte')->where('guard_name', 'api')->value('id');
        $rolId = DB::table('roles')->where('name', 'Proveedor')->where('guard_name', 'api')->value('id');
        if ($permisoId && $rolId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permisoId, 'role_id' => $rolId]);
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down()
    {
        $permisoId = DB::table('permissions')->where('name', 'ListarReporte')->where('guard_name', 'api')->value('id');
        $rolId = DB::table('roles')->where('name', 'Proveedor')->where('guard_name', 'api')->value('id');
        DB::table('role_has_permissions')->where('permission_id', $permisoId)->where('role_id', $rolId)->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
