<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Envíos de correo masivos a los clientes de un proveedor (Marketing > Envíos de correo).
 *  - envios_correo: el mensaje (copia de lo enviado), a quién (audiencia + filtro) y el proveedor.
 *  - envio_correo_destinatarios: un registro por destinatario con su estado (pendiente | enviado | error).
 * El administrador elige el proveedor; el proveedor solo escribe a sus clientes.
 * La opción del menú también está en TurismoMenuSeeder y PortalRolesSeeder (instalaciones nuevas).
 */
return new class extends Migration
{
    private const ROL_ADMINISTRADOR_ID = 1;
    private const PERMISOS = ['Listar', 'Crear'];

    public function up()
    {
        Schema::create('envios_correo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->references('id')->on('proveedores_turisticos')->cascadeOnDelete();
            $table->string('asunto', 150);
            $table->longText('texto'); // copia de lo enviado
            $table->string('audiencia', 20); // todos | experiencia | proximas | seleccion
            $table->text('filtro')->nullable(); // JSON (experiencia_id, correos[])
            $table->unsignedInteger('total')->default(0);
            $table->string('estado', 15)->default('enviando'); // enviando | completado
            $table->bigInteger('usuario_creacion_id');
            $table->string('usuario_creacion_nombre', 128);
            $table->bigInteger('usuario_modificacion_id');
            $table->string('usuario_modificacion_nombre', 128);
            $table->timestamps();
        });

        Schema::create('envio_correo_destinatarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envio_id')->references('id')->on('envios_correo')->cascadeOnDelete();
            $table->string('nombre', 200);
            $table->string('correo', 150);
            $table->text('variables')->nullable(); // JSON con los valores para personalizar
            $table->string('estado', 15)->default('pendiente'); // pendiente | enviado | error
            $table->text('error')->nullable();
            $table->dateTime('enviado_en')->nullable();
            $table->timestamps();
            $table->index(['envio_id', 'estado']);
        });

        $this->crearOpcionMenu();
    }

    public function down()
    {
        Schema::dropIfExists('envio_correo_destinatarios');
        Schema::dropIfExists('envios_correo');

        DB::table('permissions')->whereIn('name', ['ListarEnvioCorreo', 'CrearEnvioCorreo'])->where('guard_name', 'api')->delete();
        DB::table('opciones_del_sistema')->where('url', '/envios-correo')->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function crearOpcionMenu()
    {
        $moduloId = DB::table('modulos')->where('nombre', 'Marketing')->value('id');
        if (!$moduloId) {
            return; // Sin el menú de turismo aún: lo crean los seeders completos.
        }
        $auditoria = [
            'usuario_creacion_id' => 1,
            'usuario_creacion_nombre' => 'SuperUser',
            'usuario_modificacion_id' => 1,
            'usuario_modificacion_nombre' => 'SuperUser',
        ];

        if (!DB::table('opciones_del_sistema')->where('url', '/envios-correo')->exists()) {
            DB::table('opciones_del_sistema')->insert(array_merge($auditoria, [
                'nombre' => 'Envíos de correo',
                'url' => '/envios-correo',
                'modulo_id' => $moduloId,
                'posicion' => (int) DB::table('opciones_del_sistema')->where('modulo_id', $moduloId)->max('posicion') + 1,
                'icono_menu' => 'forward_to_inbox',
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
        $opcionId = DB::table('opciones_del_sistema')->where('url', '/envios-correo')->value('id');
        $roles = array_filter([
            self::ROL_ADMINISTRADOR_ID,
            DB::table('roles')->where('name', 'Proveedor')->where('guard_name', 'api')->value('id'),
        ]);

        foreach (self::PERMISOS as $titulo) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $titulo . 'EnvioCorreo', 'guard_name' => 'api'],
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
            $permisoId = DB::table('permissions')->where('name', $titulo . 'EnvioCorreo')->where('guard_name', 'api')->value('id');
            foreach ($roles as $rolId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permisoId, 'role_id' => $rolId]);
            }
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
