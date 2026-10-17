<?php

use Illuminate\Http\Request;
use App\Http\Controllers\Seguridad;
use App\Http\Controllers\Parametrizacion;
use App\Http\Controllers\Turismo;
use App\Http\Controllers\Experiencias;
use App\Http\Controllers\Reservas;
use App\Http\Controllers\Promociones;
use App\Http\Controllers\Proveedores;
use App\Http\Controllers\Resenas;
use App\Http\Controllers\Publico;
use App\Http\Controllers\BackupController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use Laravel\Passport\Http\Controllers\AccessTokenController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:api');

Route::get('/', function () {
    return "hola, tienes acceso";
});

Route::post('/users/token', [UserController::class,'getToken'])->name('oauth.getToken');
Route::post('/forgot-password', [UserController::class,'forgotPassword'])->name('oauth.password.email');
Route::post('/reset-password',[UserController::class,'resetPassword'])->name('oauth.password.update');
Route::post('/register', [UserController::class, 'register'])->name('oauth.register');
Route::post('/login', [UserController::class, 'login'])->name('oauth.login');
// Route::post('oauth/token', [AccessTokenController::class, 'issueToken']);
Route::post('oauth/token', [AccessTokenController::class, 'issueToken'])->name('oauth.token');


// ---------------------- Portal público (sin autenticación) -------------------------- //
Route::group(["prefix" => "publico", "middleware" => ["throttle:120,1"]], function () {
    Route::get('/destinos', [Publico\PortalController::class, 'destinos'])->name('publico.destinos');
    Route::get('/categorias', [Publico\PortalController::class, 'categorias'])->name('publico.categorias');
    Route::get('/caracteristicas', [Publico\PortalController::class, 'caracteristicas'])->name('publico.caracteristicas');
    Route::get('/experiencias', [Publico\PortalController::class, 'experiencias'])->name('publico.experiencias');
    Route::get('/experiencias/{slug}', [Publico\PortalController::class, 'experiencia'])->name('publico.experiencia');
    Route::get('/proveedores/{id}', [Publico\PortalController::class, 'proveedor'])->whereNumber('id')->name('publico.proveedor');
    Route::get('/promociones', [Publico\PortalController::class, 'promociones'])->name('publico.promociones');
    // Colores, logos e imágenes del sitio (Parametrización > Apariencia).
    Route::get('/apariencia', [Parametrizacion\AparienciaController::class, 'show'])->name('publico.apariencia');
    Route::post('/registro', [Publico\RegistroController::class, 'store'])->middleware('throttle:10,1,registro')->name('publico.registro');
    // Reserva sin cuenta: crear y consultar por código + correo.
    Route::post('/reservas', [Publico\ReservaController::class, 'store'])->middleware('throttle:5,1,reserva-invitado')->name('publico.reservas.store');
    Route::get('/reservas/{codigo}', [Publico\ReservaController::class, 'show'])->middleware('throttle:20,1,reserva-consulta')->name('publico.reservas.show');
    // Pago en línea: iniciar el checkout, consultar al volver, retorno y webhook de cada pasarela.
    Route::post('/reservas/{codigo}/pago', [Publico\PagoController::class, 'iniciar'])->middleware('throttle:10,1,pago-iniciar')->name('publico.pagos.iniciar');
    Route::get('/pagos/transaccion/{id}', [Publico\PagoController::class, 'transaccion'])->middleware('throttle:30,1,pago-consulta')->name('publico.pagos.transaccion');
    Route::get('/pagos/{pasarela}/retorno', [Publico\PagoController::class, 'retorno'])->where('pasarela', 'wompi|mercadopago')->name('publico.pagos.retorno');
    Route::post('/pagos/{pasarela}/eventos', [Publico\PagoController::class, 'eventos'])->where('pasarela', 'wompi|mercadopago')->name('publico.pagos.eventos');
    // URLs anteriores de Wompi: la de eventos es la registrada en su panel de comercios.
    Route::get('/pagos/retorno', [Publico\PagoController::class, 'retorno'])->defaults('pasarela', 'wompi');
    Route::post('/wompi/eventos', [Publico\PagoController::class, 'eventos'])->defaults('pasarela', 'wompi')->name('publico.wompi.eventos');
});

Route::group(['middleware' => ['auth:api']], function (){
    // User
    Route::group(["prefix" => "users"],function(){
        Route::get('current/session',  [UserController::class,'getSession'])->name('session.show');
    });

    // Mi cuenta: datos del usuario autenticado (cualquier rol, solo los propios)
    Route::group(["prefix" => "cuenta"], function () {
        Route::get('/', [Seguridad\CuentaController::class, 'show'])->name('cuenta.show');
        Route::put('/', [Seguridad\CuentaController::class, 'update'])->name('cuenta.update');
        Route::put('/clave', [Seguridad\CuentaController::class, 'cambiarClave'])->middleware('throttle:10,1,cuenta-clave')->name('cuenta.clave');
        Route::put('/proveedor', [Seguridad\CuentaController::class, 'actualizarProveedor'])->name('cuenta.proveedor');
        // Logo y fotos del negocio del propio proveedor (el negocio sale del token).
        Route::post('/proveedor/logo', [Proveedores\ProveedorFotoController::class, 'subirLogo'])->name('cuenta.proveedor.logo');
        Route::delete('/proveedor/logo', [Proveedores\ProveedorFotoController::class, 'eliminarLogo'])->name('cuenta.proveedor.logo.delete');
        Route::post('/proveedor/fotos', [Proveedores\ProveedorFotoController::class, 'subirFoto'])->name('cuenta.proveedor.fotos');
        Route::delete('/proveedor/fotos/{fotoId}', [Proveedores\ProveedorFotoController::class, 'eliminarFoto'])->whereNumber('fotoId')->name('cuenta.proveedor.fotos.delete');
    });

    // ---------------------- Seguridad -------------------------- //
    // Todas las rutas exigen el permiso del rol: con el registro público cualquiera obtiene un token,
    // así que sin esto un cliente podría editar usuarios, cambiar claves u otorgarse permisos.

    // Usuarios
    Route::group(["prefix" => "usuarios"],function(){
        // El formulario de reservas lista clientes: se permite también a quien gestiona reservas.
        Route::get('/', [Seguridad\UsuarioController::class,'index'])->name('usuarios.index')->middleware('permission:ListarUsuario|ListarReserva');
        Route::post('/', [Seguridad\UsuarioController::class,'store'])->name('usuarios.store')->middleware('permission:CrearUsuario');
        Route::get('/{id}', [Seguridad\UsuarioController::class,'show'])->name('usuarios.show')->middleware('permission:ListarUsuario');
        Route::put('/cambio-clave', [Seguridad\UsuarioController::class,'changePassword'])->name('usuarios.changePassword')->middleware('permission:CambiarClave');
        Route::put('/{id}', [Seguridad\UsuarioController::class,'update'])->name('usuarios.update')->middleware('permission:ModificarUsuario');
        Route::delete('/{id}', [Seguridad\UsuarioController::class,'destroy'])->name('usuarios.delete')->middleware('permission:EliminarUsuario');
    });

    // Roles
    Route::group(["prefix" => "roles"],function(){
        Route::get('/', [Seguridad\RolController::class,'index'])->name('roles.index')->middleware('permission:ListarRol|ListarUsuario');
        Route::get('/permisos/{id}', [Seguridad\RolController::class,'obtenerPermisos'])->name('roles.permisos')->middleware('permission:PermitirRol');
        Route::post('/permisos', [Seguridad\RolController::class,'otorgarPermisos'])->name('roles.otorgarPermisos')->middleware('permission:PermitirRol');
        Route::put('/permisos', [Seguridad\RolController::class,'revocarPermisos'])->name('roles.revocarPermisos')->middleware('permission:PermitirRol');
        Route::post('/', [Seguridad\RolController::class,'store'])->name('roles.store')->middleware('permission:CrearRol');
        Route::get('/{id}', [Seguridad\RolController::class,'show'])->name('roles.show')->middleware('permission:ListarRol');
        Route::put('/{id}', [Seguridad\RolController::class,'update'])->name('roles.update')->middleware('permission:ModificarRol');
        Route::delete('/{id}', [Seguridad\RolController::class,'destroy'])->name('roles.delete')->middleware('permission:EliminarRol');
    });

    // Aplicaciones
    Route::group(["prefix" => "aplicaciones"],function(){
        Route::get('/', [Seguridad\AplicacionController::class,'index'])->name('aplicaciones.index')->middleware('permission:ListarAplicacion|ListarModulo');
        Route::post('/', [Seguridad\AplicacionController::class,'store'])->name('aplicaciones.store')->middleware('permission:CrearAplicacion');
        Route::get('/{id}', [Seguridad\AplicacionController::class,'show'])->name('aplicaciones.show')->middleware('permission:ListarAplicacion');
        Route::put('/{id}', [Seguridad\AplicacionController::class,'update'])->name('aplicaciones.update')->middleware('permission:ModificarAplicacion');
        Route::delete('/{id}', [Seguridad\AplicacionController::class,'destroy'])->name('aplicaciones.delete')->middleware('permission:EliminarAplicacion');
    });

    // Módulos
    Route::group(["prefix" => "modulos"],function(){
        Route::get('/', [Seguridad\ModuloController::class,'index'])->name('modulos.index')->middleware('permission:ListarModulo|ListarOpcionSistema');
        Route::post('/', [Seguridad\ModuloController::class,'store'])->name('modulos.store')->middleware('permission:CrearModulo');
        Route::get('/{id}', [Seguridad\ModuloController::class,'show'])->name('modulos.show')->middleware('permission:ListarModulo');
        Route::put('/{id}', [Seguridad\ModuloController::class,'update'])->name('modulos.update')->middleware('permission:ModificarModulo');
        Route::delete('/{id}', [Seguridad\ModuloController::class,'destroy'])->name('modulos.delete')->middleware('permission:EliminarModulo');
    });

    // Opciones del Sistema
    Route::group(["prefix" => "opciones-del-sistema"],function(){
        Route::get('/', [Seguridad\OpcionSistemaController::class,'index'])->name('opciones-del-sistema.index')->middleware('permission:ListarOpcionSistema|ListarAccionPermiso');
        Route::post('/', [Seguridad\OpcionSistemaController::class,'store'])->name('opciones-del-sistema.store')->middleware('permission:CrearOpcionSistema');
        Route::get('/{id}', [Seguridad\OpcionSistemaController::class,'show'])->name('opciones-del-sistema.show')->middleware('permission:ListarOpcionSistema');
        Route::put('/{id}', [Seguridad\OpcionSistemaController::class,'update'])->name('opciones-del-sistema.update')->middleware('permission:ModificarOpcionSistema');
        Route::delete('/{id}', [Seguridad\OpcionSistemaController::class,'destroy'])->name('opciones-del-sistema.delete')->middleware('permission:EliminarOpcionSistema');
    });

    // Permisos
    Route::group(["prefix" => "permisos"],function(){
        Route::get('/', [Seguridad\PermisoController::class,'index'])->name('permisos.index')->middleware('permission:ListarAccionPermiso|PermitirRol');
        Route::post('/', [Seguridad\PermisoController::class,'store'])->name('permisos.store')->middleware('permission:CrearAccionPermiso');
        Route::get('/{id}', [Seguridad\PermisoController::class,'show'])->name('permisos.show')->middleware('permission:ListarAccionPermiso');
        Route::put('/{id}', [Seguridad\PermisoController::class,'update'])->name('permisos.update')->middleware('permission:ModificarAccionPermiso');
        Route::delete('/{id}', [Seguridad\PermisoController::class,'destroy'])->name('permisos.delete')->middleware('permission:EliminarAccionPermiso');
    });

    // Auditoria Tablas
    Route::group(["prefix" => "auditoria-tablas", "middleware" => ["permission:ListarAuditorias"]],function(){
        Route::get('/', [Seguridad\AuditoriaTablaController::class,'index'])->name('auditoria-tablas.index');
    });

    // ---------------------- Parametrizacion -------------------------- //

    // Parametros correos
    Route::group(["prefix" => "parametros-correo"],function(){
        Route::get('/', [Parametrizacion\ParametroCorreoController::class,'index'])->name('parametros_correo.index')->middleware('permission:ListarParametroCorreo');
        Route::post('/', [Parametrizacion\ParametroCorreoController::class,'store'])->name('parametros_correo.store')->middleware('permission:CrearParametroCorreo');
        Route::get('/{id}', [Parametrizacion\ParametroCorreoController::class,'show'])->name('parametros_correo.show')->middleware('permission:ListarParametroCorreo');
        Route::put('/{id}', [Parametrizacion\ParametroCorreoController::class,'update'])->name('parametros_correo.update')->middleware('permission:ModificarParametroCorreo');
        Route::delete('/{id}', [Parametrizacion\ParametroCorreoController::class,'destroy'])->name('parametros_correo.delete')->middleware('permission:EliminarParametroCorreo');
    });

    // Parametros Constantes
    Route::group(["prefix" => "parametros-constantes"],function(){
        Route::get('/', [Parametrizacion\ParametroConstanteController::class,'index'])->name('parametros-constantes.index')->middleware('permission:ListarParametroConstante');
        Route::post('/', [Parametrizacion\ParametroConstanteController::class,'store'])->name('parametros-constantes.store')->middleware('permission:CrearParametroConstante');
        Route::get('/consultar', [Parametrizacion\ParametroConstanteController::class,'consultar'])->name('parametros-constantes.consultar')->middleware('permission:ListarParametroConstante');
        Route::get('/{id}', [Parametrizacion\ParametroConstanteController::class,'show'])->name('parametros-constantes.show')->middleware('permission:ListarParametroConstante');
        Route::put('/{id}', [Parametrizacion\ParametroConstanteController::class,'update'])->name('parametros-constantes.update')->middleware('permission:ModificarParametroConstante');
        Route::delete('/{id}', [Parametrizacion\ParametroConstanteController::class,'destroy'])->name('parametros-constantes.delete')->middleware('permission:EliminarParametroConstante');
    });

    // Apariencia del sitio (la lectura es pública: publico/apariencia)
    Route::group(["prefix" => "apariencia", "middleware" => ["permission:ModificarApariencia"]],function(){
        Route::post('/', [Parametrizacion\AparienciaController::class,'update'])->name('apariencia.update');
        Route::delete('/', [Parametrizacion\AparienciaController::class,'restablecer'])->name('apariencia.restablecer');
    });


    // Copia de seguridad de la base de datos (Seguridad > BackUp). No tiene permiso propio: solo el administrador.
    Route::post('/backup', [BackupController::class, 'createBackup'])->middleware('role:SuperSu');

    // ---------------------- Turismo -------------------------- //
    // Cada ruta exige el permiso de su opción del menú (Seguridad > Roles): quitarle la opción a un rol también
    // le cierra el API. Las lecturas admiten además los permisos de las pantallas que usan ese listado en sus
    // formularios. Dentro de lo permitido, AlcancePorRol limita a proveedor y cliente a sus propios registros.

    // Inicio: dashboard (administración y proveedor, cada uno con su alcance) y reportes.
    Route::get('/dashboard', [Turismo\DashboardController::class, 'index'])->name('dashboard.index')->middleware('permission:ListarDashboard');
    Route::get('/reportes', [Turismo\ReporteController::class, 'index'])->name('reportes.index')->middleware('permission:ListarReporte');

    // Destinos
    Route::group(["prefix" => "destinos"], function () {
        Route::get('/', [Turismo\DestinoController::class, 'index'])->name('destinos.index')->middleware('permission:ListarDestino|ListarExperiencia|ListarProveedorTuristico');
        Route::post('/', [Turismo\DestinoController::class, 'store'])->name('destinos.store')->middleware('permission:CrearDestino');
        Route::get('/{id}', [Turismo\DestinoController::class, 'show'])->name('destinos.show')->middleware('permission:ListarDestino|ListarExperiencia|ListarProveedorTuristico');
        Route::put('/{id}', [Turismo\DestinoController::class, 'update'])->name('destinos.update')->middleware('permission:ModificarDestino');
        Route::delete('/{id}', [Turismo\DestinoController::class, 'destroy'])->name('destinos.delete')->middleware('permission:EliminarDestino');
    });

    // Categorias
    Route::group(["prefix" => "categorias"], function () {
        Route::get('/', [Turismo\CategoriaController::class, 'index'])->name('categorias.index')->middleware('permission:ListarCategoria|ListarExperiencia');
        Route::post('/', [Turismo\CategoriaController::class, 'store'])->name('categorias.store')->middleware('permission:CrearCategoria');
        Route::get('/{id}', [Turismo\CategoriaController::class, 'show'])->name('categorias.show')->middleware('permission:ListarCategoria|ListarExperiencia');
        Route::put('/{id}', [Turismo\CategoriaController::class, 'update'])->name('categorias.update')->middleware('permission:ModificarCategoria');
        Route::delete('/{id}', [Turismo\CategoriaController::class, 'destroy'])->name('categorias.delete')->middleware('permission:EliminarCategoria');
    });

    // Caracteristicas
    Route::group(["prefix" => "caracteristicas"], function () {
        Route::get('/', [Turismo\CaracteristicaController::class, 'index'])->name('caracteristicas.index')->middleware('permission:ListarCaracteristica|ListarExperiencia');
        Route::post('/', [Turismo\CaracteristicaController::class, 'store'])->name('caracteristicas.store')->middleware('permission:CrearCaracteristica');
        Route::get('/{id}', [Turismo\CaracteristicaController::class, 'show'])->name('caracteristicas.show')->middleware('permission:ListarCaracteristica|ListarExperiencia');
        Route::put('/{id}', [Turismo\CaracteristicaController::class, 'update'])->name('caracteristicas.update')->middleware('permission:ModificarCaracteristica');
        Route::delete('/{id}', [Turismo\CaracteristicaController::class, 'destroy'])->name('caracteristicas.delete')->middleware('permission:EliminarCaracteristica');
    });

    // Proveedores turisticos
    Route::group(["prefix" => "proveedores-turisticos"], function () {
        Route::get('/', [Proveedores\ProveedorTuristicoController::class, 'index'])->name('proveedores-turisticos.index')->middleware('permission:ListarProveedorTuristico|ListarExperiencia|ListarReserva');
        Route::post('/', [Proveedores\ProveedorTuristicoController::class, 'store'])->name('proveedores-turisticos.store')->middleware('permission:CrearProveedorTuristico');
        Route::get('/{id}', [Proveedores\ProveedorTuristicoController::class, 'show'])->name('proveedores-turisticos.show')->middleware('permission:ListarProveedorTuristico|ListarExperiencia|ListarReserva');
        Route::put('/{id}', [Proveedores\ProveedorTuristicoController::class, 'update'])->name('proveedores-turisticos.update')->middleware('permission:ModificarProveedorTuristico');
        Route::put('/{id}/verificar', [Proveedores\ProveedorTuristicoController::class, 'verificar'])->name('proveedores-turisticos.verificar')->middleware('permission:ModificarProveedorTuristico');
        Route::post('/{id}/logo', [Proveedores\ProveedorFotoController::class, 'subirLogo'])->whereNumber('id')->name('proveedores-turisticos.logo')->middleware('permission:ModificarProveedorTuristico');
        Route::delete('/{id}/logo', [Proveedores\ProveedorFotoController::class, 'eliminarLogo'])->whereNumber('id')->name('proveedores-turisticos.logo.delete')->middleware('permission:ModificarProveedorTuristico');
        Route::post('/{id}/fotos', [Proveedores\ProveedorFotoController::class, 'subirFoto'])->whereNumber('id')->name('proveedores-turisticos.fotos')->middleware('permission:ModificarProveedorTuristico');
        Route::delete('/{id}/fotos/{fotoId}', [Proveedores\ProveedorFotoController::class, 'eliminarFotoDeProveedor'])->whereNumber('id')->name('proveedores-turisticos.fotos.delete')->middleware('permission:ModificarProveedorTuristico');
        Route::delete('/{id}', [Proveedores\ProveedorTuristicoController::class, 'destroy'])->name('proveedores-turisticos.delete')->middleware('permission:EliminarProveedorTuristico');
    });

    // Documentos proveedor (también el proveedor sobre los suyos: Mi Cuenta > Mis Documentos)
    Route::group(["prefix" => "documentos-proveedor"], function () {
        Route::get('/', [Proveedores\DocumentoProveedorController::class, 'index'])->name('documentos-proveedor.index')->middleware('permission:ListarProveedorTuristico|ListarMiDocumento');
        Route::post('/', [Proveedores\DocumentoProveedorController::class, 'store'])->name('documentos-proveedor.store')->middleware('permission:CrearProveedorTuristico|CrearMiDocumento');
        Route::get('/{id}', [Proveedores\DocumentoProveedorController::class, 'show'])->name('documentos-proveedor.show')->middleware('permission:ListarProveedorTuristico|ListarMiDocumento');
        Route::get('/{id}/archivo', [Proveedores\DocumentoProveedorController::class, 'archivo'])->name('documentos-proveedor.archivo')->middleware('permission:ListarProveedorTuristico|ListarMiDocumento');
        Route::put('/{id}', [Proveedores\DocumentoProveedorController::class, 'update'])->name('documentos-proveedor.update')->middleware('permission:ModificarProveedorTuristico|ModificarMiDocumento');
        Route::delete('/{id}', [Proveedores\DocumentoProveedorController::class, 'destroy'])->name('documentos-proveedor.delete')->middleware('permission:EliminarProveedorTuristico|EliminarMiDocumento');
    });

    // Cupones
    Route::group(["prefix" => "cupones"], function () {
        Route::get('/', [Turismo\CuponController::class, 'index'])->name('cupones.index')->middleware('permission:ListarCupon|ListarReserva');
        Route::post('/', [Turismo\CuponController::class, 'store'])->name('cupones.store')->middleware('permission:CrearCupon');
        Route::get('/{id}', [Turismo\CuponController::class, 'show'])->name('cupones.show')->middleware('permission:ListarCupon|ListarReserva');
        Route::put('/{id}', [Turismo\CuponController::class, 'update'])->name('cupones.update')->middleware('permission:ModificarCupon');
        Route::delete('/{id}', [Turismo\CuponController::class, 'destroy'])->name('cupones.delete')->middleware('permission:EliminarCupon');
    });

    // Experiencias
    Route::group(["prefix" => "experiencias"], function () {
        Route::get('/', [Experiencias\ExperienciaController::class, 'index'])->name('experiencias.index')->middleware('permission:ListarExperiencia|ListarReserva|ListarPromocion|ListarResena');
        Route::post('/', [Experiencias\ExperienciaController::class, 'store'])->name('experiencias.store')->middleware('permission:CrearExperiencia');
        Route::get('/{id}', [Experiencias\ExperienciaController::class, 'show'])->name('experiencias.show')->middleware('permission:ListarExperiencia|ListarReserva|ListarPromocion|ListarResena');
        Route::put('/{id}', [Experiencias\ExperienciaController::class, 'update'])->name('experiencias.update')->middleware('permission:ModificarExperiencia');
        Route::put('/{id}/estado', [Experiencias\ExperienciaController::class, 'cambiarEstado'])->name('experiencias.cambiarEstado')->middleware('permission:ModificarExperiencia');
        Route::delete('/{id}', [Experiencias\ExperienciaController::class, 'destroy'])->name('experiencias.delete')->middleware('permission:EliminarExperiencia');
    });

    // Experiencia categorias
    Route::group(["prefix" => "experiencia-categorias"], function () {
        Route::get('/', [Experiencias\ExperienciaCategoriaController::class, 'index'])->name('experiencia-categorias.index')->middleware('permission:ListarExperiencia');
        Route::post('/', [Experiencias\ExperienciaCategoriaController::class, 'store'])->name('experiencia-categorias.store')->middleware('permission:CrearExperiencia|ModificarExperiencia');
        Route::delete('/{id}', [Experiencias\ExperienciaCategoriaController::class, 'destroy'])->name('experiencia-categorias.delete')->middleware('permission:CrearExperiencia|ModificarExperiencia');
    });

    // Experiencia precios
    Route::group(["prefix" => "experiencia-precios"], function () {
        Route::get('/', [Experiencias\ExperienciaPrecioController::class, 'index'])->name('experiencia-precios.index')->middleware('permission:ListarExperiencia');
        Route::post('/', [Experiencias\ExperienciaPrecioController::class, 'store'])->name('experiencia-precios.store')->middleware('permission:CrearExperiencia|ModificarExperiencia');
        Route::get('/{id}', [Experiencias\ExperienciaPrecioController::class, 'show'])->name('experiencia-precios.show')->middleware('permission:ListarExperiencia');
        Route::put('/{id}', [Experiencias\ExperienciaPrecioController::class, 'update'])->name('experiencia-precios.update')->middleware('permission:CrearExperiencia|ModificarExperiencia');
        Route::delete('/{id}', [Experiencias\ExperienciaPrecioController::class, 'destroy'])->name('experiencia-precios.delete')->middleware('permission:CrearExperiencia|ModificarExperiencia');
    });

    // Experiencia multimedia
    Route::group(["prefix" => "experiencia-multimedia"], function () {
        Route::get('/', [Experiencias\ExperienciaMultimediaController::class, 'index'])->name('experiencia-multimedia.index')->middleware('permission:ListarExperiencia');
        Route::post('/', [Experiencias\ExperienciaMultimediaController::class, 'store'])->name('experiencia-multimedia.store')->middleware('permission:CrearExperiencia|ModificarExperiencia');
        Route::get('/{id}', [Experiencias\ExperienciaMultimediaController::class, 'show'])->name('experiencia-multimedia.show')->middleware('permission:ListarExperiencia');
        Route::put('/{id}', [Experiencias\ExperienciaMultimediaController::class, 'update'])->name('experiencia-multimedia.update')->middleware('permission:CrearExperiencia|ModificarExperiencia');
        Route::delete('/{id}', [Experiencias\ExperienciaMultimediaController::class, 'destroy'])->name('experiencia-multimedia.delete')->middleware('permission:CrearExperiencia|ModificarExperiencia');
    });

    // Experiencia caracteristicas
    Route::group(["prefix" => "experiencia-caracteristicas"], function () {
        Route::get('/', [Experiencias\ExperienciaCaracteristicaController::class, 'index'])->name('experiencia-caracteristicas.index')->middleware('permission:ListarExperiencia');
        Route::post('/', [Experiencias\ExperienciaCaracteristicaController::class, 'store'])->name('experiencia-caracteristicas.store')->middleware('permission:CrearExperiencia|ModificarExperiencia');
        Route::put('/{id}', [Experiencias\ExperienciaCaracteristicaController::class, 'update'])->name('experiencia-caracteristicas.update')->middleware('permission:CrearExperiencia|ModificarExperiencia');
        Route::delete('/{id}', [Experiencias\ExperienciaCaracteristicaController::class, 'destroy'])->name('experiencia-caracteristicas.delete')->middleware('permission:CrearExperiencia|ModificarExperiencia');
    });

    // Experiencia horarios
    Route::group(["prefix" => "experiencia-horarios"], function () {
        Route::get('/', [Experiencias\ExperienciaHorarioController::class, 'index'])->name('experiencia-horarios.index')->middleware('permission:ListarExperiencia');
        Route::post('/', [Experiencias\ExperienciaHorarioController::class, 'store'])->name('experiencia-horarios.store')->middleware('permission:CrearExperiencia|ModificarExperiencia');
        Route::get('/{id}', [Experiencias\ExperienciaHorarioController::class, 'show'])->name('experiencia-horarios.show')->middleware('permission:ListarExperiencia');
        Route::put('/{id}', [Experiencias\ExperienciaHorarioController::class, 'update'])->name('experiencia-horarios.update')->middleware('permission:CrearExperiencia|ModificarExperiencia');
        Route::delete('/{id}', [Experiencias\ExperienciaHorarioController::class, 'destroy'])->name('experiencia-horarios.delete')->middleware('permission:CrearExperiencia|ModificarExperiencia');
    });

    // Crea a mano las fechas de los tours con horario semanal (Parametrización > Generar fechas).
    Route::post('/disponibilidad/generar', [Experiencias\ExperienciaDisponibilidadController::class, 'generar'])
        ->name('disponibilidad.generar')->middleware('permission:ModificarFechasTour');

    // Experiencia disponibilidad
    Route::group(["prefix" => "experiencia-disponibilidad"], function () {
        Route::get('/', [Experiencias\ExperienciaDisponibilidadController::class, 'index'])->name('experiencia-disponibilidad.index')->middleware('permission:ListarExperiencia|ListarReserva');
        Route::post('/', [Experiencias\ExperienciaDisponibilidadController::class, 'store'])->name('experiencia-disponibilidad.store')->middleware('permission:CrearExperiencia|ModificarExperiencia');
        Route::post('/lote', [Experiencias\ExperienciaDisponibilidadController::class, 'lote'])->name('experiencia-disponibilidad.lote')->middleware('permission:CrearExperiencia|ModificarExperiencia');
        Route::get('/{id}', [Experiencias\ExperienciaDisponibilidadController::class, 'show'])->name('experiencia-disponibilidad.show')->middleware('permission:ListarExperiencia|ListarReserva');
        Route::put('/{id}', [Experiencias\ExperienciaDisponibilidadController::class, 'update'])->name('experiencia-disponibilidad.update')->middleware('permission:CrearExperiencia|ModificarExperiencia');
        Route::delete('/{id}', [Experiencias\ExperienciaDisponibilidadController::class, 'destroy'])->name('experiencia-disponibilidad.delete')->middleware('permission:CrearExperiencia|ModificarExperiencia');
    });

    // Reservas
    Route::group(["prefix" => "reservas"], function () {
        Route::get('/', [Reservas\ReservaController::class, 'index'])->name('reservas.index')->middleware('permission:ListarReserva|ListarMiReserva');
        Route::post('/', [Reservas\ReservaController::class, 'store'])->name('reservas.store')->middleware('permission:CrearReserva|ListarMiReserva');
        Route::get('/{id}', [Reservas\ReservaController::class, 'show'])->name('reservas.show')->middleware('permission:ListarReserva|ListarMiReserva');
        Route::put('/{id}', [Reservas\ReservaController::class, 'update'])->name('reservas.update')->middleware('permission:ModificarReserva');
        Route::put('/{id}/estado', [Reservas\ReservaController::class, 'cambiarEstado'])->name('reservas.cambiarEstado')->middleware('permission:ModificarReserva|ModificarMiReserva');
        // Pagos recibidos por fuera del portal (los registra el proveedor o el administrador).
        Route::post('/{id}/pagos', [Reservas\ReservaController::class, 'registrarPago'])->name('reservas.pagos.registrar')->middleware('permission:ModificarReserva');
        Route::put('/{id}/pagos/{pagoId}/anular', [Reservas\ReservaController::class, 'anularPago'])->name('reservas.pagos.anular')->middleware('permission:ModificarReserva|ReembolsarReserva');
        // Dinero devuelto al viajero por fuera del portal: solo administración.
        Route::post('/{id}/reembolsos', [Reservas\ReservaController::class, 'registrarReembolso'])->name('reservas.reembolsos.registrar')->middleware('permission:ReembolsarReserva');
        Route::delete('/{id}', [Reservas\ReservaController::class, 'destroy'])->name('reservas.delete')->middleware('permission:EliminarReserva');
    });

    // Acompanantes reserva
    Route::group(["prefix" => "acompanantes-reserva"], function () {
        Route::get('/', [Reservas\AcompananteReservaController::class, 'index'])->name('acompanantes-reserva.index')->middleware('permission:ListarReserva|ListarMiReserva');
        Route::post('/', [Reservas\AcompananteReservaController::class, 'store'])->name('acompanantes-reserva.store')->middleware('permission:CrearReserva|ModificarReserva');
        Route::get('/{id}', [Reservas\AcompananteReservaController::class, 'show'])->name('acompanantes-reserva.show')->middleware('permission:ListarReserva|ListarMiReserva');
        Route::put('/{id}', [Reservas\AcompananteReservaController::class, 'update'])->name('acompanantes-reserva.update')->middleware('permission:CrearReserva|ModificarReserva');
        Route::delete('/{id}', [Reservas\AcompananteReservaController::class, 'destroy'])->name('acompanantes-reserva.delete')->middleware('permission:CrearReserva|ModificarReserva');
    });

    // Favoritos
    Route::group(["prefix" => "favoritos"], function () {
        Route::get('/', [Turismo\FavoritoController::class, 'index'])->name('favoritos.index')->middleware('permission:ListarMiFavorito');
        Route::post('/', [Turismo\FavoritoController::class, 'store'])->name('favoritos.store')->middleware('permission:ListarMiFavorito');
        Route::delete('/{id}', [Turismo\FavoritoController::class, 'destroy'])->name('favoritos.delete')->middleware('permission:EliminarMiFavorito');
    });

    // Resenas (moderación; el cliente las suyas en Mi Cuenta > Mis Reseñas; el proveedor consulta las de sus experiencias)
    Route::group(["prefix" => "resenas"], function () {
        Route::get('/', [Resenas\ResenaController::class, 'index'])->name('resenas.index')->middleware('permission:ListarResena|ListarMiResena');
        Route::post('/', [Resenas\ResenaController::class, 'store'])->name('resenas.store')->middleware('permission:CrearResena|CrearMiResena');
        Route::get('/{id}', [Resenas\ResenaController::class, 'show'])->name('resenas.show')->middleware('permission:ListarResena|ListarMiResena');
        Route::put('/{id}', [Resenas\ResenaController::class, 'update'])->name('resenas.update')->middleware('permission:ModificarResena|ModificarMiResena');
        Route::delete('/{id}', [Resenas\ResenaController::class, 'destroy'])->name('resenas.delete')->middleware('permission:EliminarResena|EliminarMiResena');
    });

    // Multimedia resena
    Route::group(["prefix" => "multimedia-resena"], function () {
        Route::get('/', [Resenas\MultimediaResenaController::class, 'index'])->name('multimedia-resena.index')->middleware('permission:ListarResena');
        Route::post('/', [Resenas\MultimediaResenaController::class, 'store'])->name('multimedia-resena.store')->middleware('permission:CrearResena|ModificarResena');
        Route::delete('/{id}', [Resenas\MultimediaResenaController::class, 'destroy'])->name('multimedia-resena.delete')->middleware('permission:ModificarResena|EliminarResena');
    });

    // Promociones
    Route::group(["prefix" => "promociones"], function () {
        Route::get('/', [Promociones\PromocionController::class, 'index'])->name('promociones.index')->middleware('permission:ListarPromocion');
        Route::post('/', [Promociones\PromocionController::class, 'store'])->name('promociones.store')->middleware('permission:CrearPromocion');
        Route::get('/{id}', [Promociones\PromocionController::class, 'show'])->name('promociones.show')->middleware('permission:ListarPromocion');
        Route::put('/{id}', [Promociones\PromocionController::class, 'update'])->name('promociones.update')->middleware('permission:ModificarPromocion');
        Route::delete('/{id}', [Promociones\PromocionController::class, 'destroy'])->name('promociones.delete')->middleware('permission:EliminarPromocion');
    });

    // Promocion experiencias
    Route::group(["prefix" => "promocion-experiencias"], function () {
        Route::get('/', [Promociones\PromocionExperienciaController::class, 'index'])->name('promocion-experiencias.index')->middleware('permission:ListarPromocion');
        Route::post('/', [Promociones\PromocionExperienciaController::class, 'store'])->name('promocion-experiencias.store')->middleware('permission:CrearPromocion');
        Route::delete('/{id}', [Promociones\PromocionExperienciaController::class, 'destroy'])->name('promocion-experiencias.delete')->middleware('permission:EliminarPromocion');
    });

    // Notificaciones
    Route::group(["prefix" => "notificaciones"], function () {
        Route::get('/', [Turismo\NotificacionController::class, 'index'])->name('notificaciones.index');
        Route::post('/', [Turismo\NotificacionController::class, 'store'])->name('notificaciones.store');
        Route::put('/{id}/leida', [Turismo\NotificacionController::class, 'marcarLeida'])->name('notificaciones.marcarLeida');
        Route::delete('/{id}', [Turismo\NotificacionController::class, 'destroy'])->name('notificaciones.delete');
    });
});