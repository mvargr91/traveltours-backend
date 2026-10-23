<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use App\Services\CobroProveedores;

/**
 * Pasarela de pago por proveedor y cobro de la plataforma a los proveedores (menú Finanzas).
 *  - proveedores_turisticos.es_plataforma: el "proveedor" de los tours propios del administrador. Cobra con la
 *    pasarela de la plataforma y no tiene modelo de cobro. suspendido_por_mora: liquidación vencida sin pagar.
 *  - cuentas_pasarela: la pasarela (Wompi o Mercado Pago) donde cada proveedor recibe sus pagos en línea.
 *    Sin cuenta activa, sus experiencias se reservan sin pago en línea.
 *  - modelos_cobro_proveedor: suscripción, comisión o mixto, con historial por mes (vigente_desde).
 *  - comisiones: una fila por reserva finalizada (y ajustes si cambia después de liquidada).
 *  - liquidaciones y pagos_liquidacion: el cobro mensual al proveedor y cómo lo pagó.
 * La pasarela de .env (si está configurada) pasa a ser la cuenta del proveedor de la plataforma.
 * El menú también está en TurismoMenuSeeder y PortalRolesSeeder (instalaciones nuevas).
 */
return new class extends Migration
{
    private const ROL_ADMINISTRADOR_ID = 1;

    // url => [nombre, icono, entidad, permisos del administrador, permisos del proveedor]
    private const OPCIONES = [
        '/pasarelas-pago' => ['Pasarelas de pago', 'credit_card', 'CuentaPasarela', ['Listar', 'Crear', 'Modificar', 'Eliminar'], ['Listar', 'Crear', 'Modificar']],
        '/modelos-cobro' => ['Modelos de cobro', 'request_quote', 'ModeloCobro', ['Listar', 'Modificar'], []],
        '/liquidaciones' => ['Liquidaciones', 'receipt_long', 'Liquidacion', ['Listar', 'Crear', 'Modificar'], ['Listar']],
    ];

    public function up()
    {
        Schema::table('proveedores_turisticos', function (Blueprint $table) {
            $table->boolean('es_plataforma')->default(false)->after('estado');
            $table->boolean('suspendido_por_mora')->default(false)->after('es_plataforma');
        });

        Schema::create('cuentas_pasarela', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->unique()->references('id')->on('proveedores_turisticos')->cascadeOnDelete();
            $table->string('pasarela', 20); // wompi | mercadopago
            $table->string('ambiente', 15)->default('sandbox'); // sandbox | produccion
            $table->text('credenciales'); // JSON cifrado (cast encrypted:array)
            $table->string('codigo', 40)->unique(); // identifica la cuenta en las URL de retorno y eventos
            $table->string('estado', 15)->default('pendiente'); // pendiente | activa | inactiva
            $table->string('comercio_nombre', 150)->nullable(); // nombre del comercio que devolvió la prueba
            $table->timestamp('verificada_en')->nullable();
            $table->string('ultimo_error', 500)->nullable();
            $table->bigInteger('usuario_creacion_id');
            $table->string('usuario_creacion_nombre', 128);
            $table->bigInteger('usuario_modificacion_id');
            $table->string('usuario_modificacion_nombre', 128);
            $table->timestamps();
        });

        Schema::table('pagos', function (Blueprint $table) {
            $table->foreignId('cuenta_pasarela_id')->nullable()->after('pasarela')->references('id')->on('cuentas_pasarela');
        });

        Schema::create('modelos_cobro_proveedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->references('id')->on('proveedores_turisticos')->cascadeOnDelete();
            $table->string('tipo', 15); // suscripcion | comision | mixto
            $table->decimal('valor_mensual', 12, 2)->default(0);
            $table->decimal('porcentaje_comision', 5, 2)->default(0);
            $table->date('vigente_desde'); // siempre el día 1 del mes
            $table->string('observaciones', 500)->nullable();
            $table->bigInteger('usuario_creacion_id');
            $table->string('usuario_creacion_nombre', 128);
            $table->bigInteger('usuario_modificacion_id');
            $table->string('usuario_modificacion_nombre', 128);
            $table->timestamps();
            $table->unique(['proveedor_id', 'vigente_desde']);
        });

        Schema::create('liquidaciones', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->foreignId('proveedor_id')->references('id')->on('proveedores_turisticos')->cascadeOnDelete();
            $table->char('periodo', 7); // YYYY-MM
            $table->string('tipo_modelo', 15);
            $table->decimal('porcentaje_comision', 5, 2)->default(0);
            $table->decimal('valor_suscripcion', 12, 2)->default(0);
            $table->decimal('total_ventas', 12, 2)->default(0);
            $table->decimal('total_comisiones', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('porcentaje_iva', 5, 2)->default(0);
            $table->decimal('valor_iva', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('valor_pagado', 12, 2)->default(0);
            $table->string('estado', 15)->default('borrador'); // borrador | emitida | pagada | anulada
            $table->date('fecha_emision')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->date('fecha_pago')->nullable();
            $table->date('recordatorio_enviado')->nullable(); // aviso de vencida (uno solo)
            $table->string('factura_numero', 50)->nullable(); // factura electrónica emitida por fuera
            $table->text('observaciones')->nullable();
            $table->bigInteger('usuario_creacion_id');
            $table->string('usuario_creacion_nombre', 128);
            $table->bigInteger('usuario_modificacion_id');
            $table->string('usuario_modificacion_nombre', 128);
            $table->timestamps();
            $table->unique(['proveedor_id', 'periodo']);
        });

        Schema::create('comisiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->references('id')->on('proveedores_turisticos')->cascadeOnDelete();
            $table->foreignId('reserva_id')->nullable()->references('id')->on('reservas')->nullOnDelete();
            $table->string('codigo_reserva', 30);
            $table->char('periodo', 7); // mes de la experiencia (YYYY-MM)
            $table->string('tipo', 10)->default('comision'); // comision | ajuste
            $table->decimal('base', 12, 2);
            $table->decimal('porcentaje', 5, 2);
            $table->decimal('valor', 12, 2);
            $table->string('descripcion', 255)->nullable();
            $table->foreignId('liquidacion_id')->nullable()->references('id')->on('liquidaciones')->nullOnDelete();
            $table->timestamps();
            $table->index(['proveedor_id', 'periodo']);
        });

        Schema::create('pagos_liquidacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liquidacion_id')->references('id')->on('liquidaciones')->cascadeOnDelete();
            $table->string('pasarela', 20); // wompi | mercadopago | manual
            $table->foreignId('cuenta_pasarela_id')->nullable()->references('id')->on('cuentas_pasarela');
            $table->string('referencia', 60)->unique();
            $table->unsignedBigInteger('monto_centavos');
            $table->string('moneda', 3)->default('COP');
            $table->string('estado', 20)->default('pendiente');
            $table->string('transaccion_id', 60)->nullable()->index();
            $table->string('metodo_pago', 40)->nullable();
            $table->json('respuesta')->nullable();
            $table->date('fecha_pago')->nullable();
            $table->string('nota', 255)->nullable();
            $table->string('registrado_por', 128)->nullable();
            $table->string('anulado_por', 128)->nullable();
            $table->timestamp('anulado_en')->nullable();
            $table->timestamps();
        });

        $plataformaId = $this->crearProveedorPlataforma();
        $this->migrarPasarelaDeEnv($plataformaId);
        $this->crearParametros();
        $this->crearPlantillas();
        $this->asignarModeloPorDefecto();
        $this->crearMenu();
    }

    public function down()
    {
        $this->eliminarMenu();
        DB::table('parametros_correos')->whereIn('nombre', array_keys(CobroProveedores::PLANTILLAS))->delete();
        DB::table('parametros_constantes')->whereIn('codigo_parametro', array_keys(CobroProveedores::PARAMETROS))->delete();

        Schema::dropIfExists('pagos_liquidacion');
        Schema::dropIfExists('comisiones');
        Schema::dropIfExists('liquidaciones');
        Schema::dropIfExists('modelos_cobro_proveedor');
        Schema::table('pagos', fn (Blueprint $table) => $table->dropConstrainedForeignId('cuenta_pasarela_id'));
        Schema::dropIfExists('cuentas_pasarela');
        // El proveedor de la plataforma se queda (puede tener experiencias y reservas): solo pierde la marca.
        Schema::table('proveedores_turisticos', fn (Blueprint $table) => $table->dropColumn(['es_plataforma', 'suspendido_por_mora']));
    }

    private function auditoria(): array
    {
        return [
            'usuario_creacion_id' => 0,
            'usuario_creacion_nombre' => 'Sistema',
            'usuario_modificacion_id' => 0,
            'usuario_modificacion_nombre' => 'Sistema',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function crearProveedorPlataforma()
    {
        // Tras un rollback el registro sigue ahí (sin la marca): se reutiliza.
        $existente = DB::table('proveedores_turisticos')
            ->whereNull('usuario_id')
            ->where('descripcion', 'Experiencias propias de la plataforma.')
            ->orderBy('id')
            ->value('id');
        if ($existente) {
            DB::table('proveedores_turisticos')->where('id', $existente)->update(['es_plataforma' => true]);
            return $existente;
        }
        return DB::table('proveedores_turisticos')->insertGetId(array_merge($this->auditoria(), [
            'nombre_comercial' => 'Travel City',
            'descripcion' => 'Experiencias propias de la plataforma.',
            'correo' => config('mail.from.address'),
            'estado_verificacion' => 'aprobado',
            'verificado_en' => now(),
            'estado' => true,
            'es_plataforma' => true,
        ]));
    }

    // La pasarela de .env queda como la cuenta de la plataforma; los pagos en línea anteriores se cobraron con ella.
    private function migrarPasarelaDeEnv($plataformaId)
    {
        $pasarela = config('services.pagos.pasarela');
        $credenciales = match ($pasarela) {
            'wompi' => array_filter([
                'public_key' => config('services.wompi.public_key'),
                'private_key' => config('services.wompi.private_key'),
                'integrity_secret' => config('services.wompi.integrity_secret'),
                'events_secret' => config('services.wompi.events_secret'),
            ]),
            'mercadopago' => array_filter([
                'access_token' => config('services.mercadopago.access_token'),
                'webhook_secret' => config('services.mercadopago.webhook_secret'),
            ]),
            default => [],
        };
        $completa = $pasarela === 'wompi'
            ? isset($credenciales['public_key'], $credenciales['integrity_secret'])
            : isset($credenciales['access_token']);
        if (!$credenciales || !$completa) {
            return;
        }

        $ambiente = $pasarela === 'wompi'
            ? (str_contains((string) config('services.wompi.api_url'), 'sandbox') ? 'sandbox' : 'produccion')
            : (str_starts_with($credenciales['access_token'], 'TEST-') ? 'sandbox' : 'produccion');

        $cuentaId = DB::table('cuentas_pasarela')->insertGetId(array_merge($this->auditoria(), [
            'proveedor_id' => $plataformaId,
            'pasarela' => $pasarela,
            'ambiente' => $ambiente,
            // Mismo formato que el cast encrypted:array del modelo CuentaPasarela.
            'credenciales' => Crypt::encryptString(json_encode($credenciales)),
            'codigo' => Str::lower(Str::random(24)),
            'estado' => 'activa',
            'verificada_en' => now(),
        ]));

        DB::table('pagos')->where('pasarela', $pasarela)->whereNull('cuenta_pasarela_id')->update(['cuenta_pasarela_id' => $cuentaId]);
    }

    private function crearParametros()
    {
        foreach (CobroProveedores::PARAMETROS as $codigo => [$valor, $descripcion]) {
            DB::table('parametros_constantes')->insertOrIgnore(array_merge($this->auditoria(), [
                'codigo_parametro' => $codigo,
                'descripcion_parametro' => $descripcion,
                'valor_parametro' => $valor,
                'estado' => 1,
            ]));
        }
    }

    private function crearPlantillas()
    {
        foreach (CobroProveedores::PLANTILLAS as $nombre => [$asunto, $texto]) {
            if (DB::table('parametros_correos')->where('nombre', $nombre)->exists()) {
                continue;
            }
            DB::table('parametros_correos')->insert(array_merge($this->auditoria(), [
                'nombre' => $nombre,
                'asunto' => $asunto,
                'texto' => $texto,
                'parametros' => CobroProveedores::VARIABLES,
                'estado' => 1,
            ]));
        }
    }

    // Los proveedores ya aprobados quedan con el modelo por defecto desde este mes.
    private function asignarModeloPorDefecto()
    {
        [$tipo, $valor, $porcentaje] = [
            CobroProveedores::PARAMETROS['COBRO_MODELO_DEFECTO'][0],
            (float) CobroProveedores::PARAMETROS['COBRO_SUSCRIPCION_DEFECTO'][0],
            (float) CobroProveedores::PARAMETROS['COBRO_PORCENTAJE_DEFECTO'][0],
        ];
        $proveedores = DB::table('proveedores_turisticos')->where('es_plataforma', false)->where('estado_verificacion', 'aprobado')->pluck('id');
        foreach ($proveedores as $proveedorId) {
            DB::table('modelos_cobro_proveedor')->insert(array_merge($this->auditoria(), [
                'proveedor_id' => $proveedorId,
                'tipo' => $tipo,
                'valor_mensual' => $tipo === 'comision' ? 0 : $valor,
                'porcentaje_comision' => $tipo === 'suscripcion' ? 0 : $porcentaje,
                'vigente_desde' => now()->startOfMonth()->toDateString(),
                'observaciones' => 'Modelo por defecto asignado al activar el cobro a proveedores.',
            ]));
        }
    }

    private function crearMenu()
    {
        $aplicacionId = DB::table('aplicaciones')->orderBy('id')->value('id');
        if (!$aplicacionId || !DB::table('modulos')->where('nombre', 'Ventas')->exists()) {
            return; // Sin el menú de turismo aún: lo crean los seeders completos.
        }
        $auditoria = [
            'usuario_creacion_id' => 1,
            'usuario_creacion_nombre' => 'SuperUser',
            'usuario_modificacion_id' => 1,
            'usuario_modificacion_nombre' => 'SuperUser',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('modulos')->updateOrInsert(
            ['nombre' => 'Finanzas', 'aplicacion_id' => $aplicacionId],
            array_merge($auditoria, ['icono_menu' => 'account_balance', 'posicion' => 14, 'estado' => true])
        );
        $moduloId = DB::table('modulos')->where('nombre', 'Finanzas')->where('aplicacion_id', $aplicacionId)->value('id');
        $proveedorRolId = DB::table('roles')->where('name', 'Proveedor')->where('guard_name', 'api')->value('id');

        $posicion = 0;
        foreach (self::OPCIONES as $url => [$nombre, $icono, $entidad, $permisosAdmin, $permisosProveedor]) {
            DB::table('opciones_del_sistema')->updateOrInsert(
                ['url' => $url],
                array_merge($auditoria, ['nombre' => $nombre, 'modulo_id' => $moduloId, 'posicion' => ++$posicion, 'icono_menu' => $icono, 'estado' => true])
            );
            $opcionId = DB::table('opciones_del_sistema')->where('url', $url)->value('id');

            foreach ($permisosAdmin as $titulo) {
                DB::table('permissions')->updateOrInsert(
                    ['name' => $titulo . $entidad, 'guard_name' => 'api'],
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
                $permisoId = DB::table('permissions')->where('name', $titulo . $entidad)->where('guard_name', 'api')->value('id');
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permisoId, 'role_id' => self::ROL_ADMINISTRADOR_ID]);
                if ($proveedorRolId && in_array($titulo, $permisosProveedor, true)) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permisoId, 'role_id' => $proveedorRolId]);
                }
            }
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function eliminarMenu()
    {
        foreach (self::OPCIONES as $url => [, , $entidad, $permisos]) {
            DB::table('permissions')->whereIn('name', array_map(fn ($t) => $t . $entidad, $permisos))->where('guard_name', 'api')->delete();
            DB::table('opciones_del_sistema')->where('url', $url)->delete();
        }
        DB::table('modulos')->where('nombre', 'Finanzas')->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
