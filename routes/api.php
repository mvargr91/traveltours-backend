<?php

use Illuminate\Http\Request;
use App\Http\Controllers\Seguridad;
use App\Http\Controllers\Parametrizacion;
use App\Http\Controllers\Inversiones;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\ExtractoController;
use App\Http\Controllers\PortafolioController;
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


Route::group(['middleware' => ['auth:api']], function (){
    // User
    Route::group(["prefix" => "users"],function(){
        Route::get('current/session',  [UserController::class,'getSession'])->name('session.show');
    });

    // Usuarios
    Route::group(["prefix" => "usuarios"],function(){
        Route::get('/', [Seguridad\UsuarioController::class,'index'])->name('usuarios.index');
        Route::post('/', [Seguridad\UsuarioController::class,'store'])->name('usuarios.store');
            // ->middleware(['permission:CrearUsuario']);
        Route::get('/{id}', [Seguridad\UsuarioController::class,'show'])->name('usuarios.show');
        // ->middleware(['permission:ListarUsuario']);
        Route::put('/cambio-clave', [Seguridad\UsuarioController::class,'changePassword'])->name('usuarios.changePassword');
        Route::put('/{id}', [Seguridad\UsuarioController::class,'update'])->name('usuarios.update');
            // ->middleware(['permission:ModificarUsuario']);
        Route::delete('/{id}', [Seguridad\UsuarioController::class,'destroy'])->name('usuarios.delete');
            // ->middleware(['permission:EliminarUsuario']);
    });

    // Roles
    Route::group(["prefix" => "roles"],function(){
        Route::get('/', [Seguridad\RolController::class,'index'])->name('roles.index');
        Route::get('/permisos/{id}', [Seguridad\RolController::class,'obtenerPermisos'])->name('roles.permisos');
            // ->middleware(['permission:PermitirRol']);
        Route::post('/permisos', [Seguridad\RolController::class,'otorgarPermisos'])->name('roles.otorgarPermisos');
            // ->middleware(['permission:PermitirRol']);
        Route::put('/permisos', [Seguridad\RolController::class,'revocarPermisos'])->name('roles.revocarPermisos');
            // ->middleware(['permission:PermitirRol']);
        Route::post('/', [Seguridad\RolController::class,'store'])->name('roles.store');
            // ->middleware(['permission:CrearRol']);
        Route::get('/{id}', [Seguridad\RolController::class,'show'])->name('roles.show');
            // ->middleware(['permission:ListarRol']);
        Route::put('/{id}', [Seguridad\RolController::class,'update'])->name('roles.update');
            // ->middleware(['permission:ModificarRol']);
        Route::delete('/{id}', [Seguridad\RolController::class,'destroy'])->name('roles.delete');
            // ->middleware(['permission:EliminarRol']);
    });

    // Aplicaciones
    Route::group(["prefix" => "aplicaciones"],function(){
        Route::get('/', [Seguridad\AplicacionController::class,'index'])->name('aplicaciones.index');
        Route::post('/', [Seguridad\AplicacionController::class,'store'])->name('aplicaciones.store');
            // ->middleware(['permission:CrearAplicacion']);
        Route::get('/{id}', [Seguridad\AplicacionController::class,'show'])->name('aplicaciones.show');
            // ->middleware(['permission:ListarAplicacion']);
        Route::put('/{id}', [Seguridad\AplicacionController::class,'update'])->name('aplicaciones.update');
            // ->middleware(['permission:ModificarAplicacion']);
        Route::delete('/{id}', [Seguridad\AplicacionController::class,'destroy'])->name('aplicaciones.delete');
            // ->middleware(['permission:EliminarAplicacion']);
    });

    // Módulos
    Route::group(["prefix" => "modulos"],function(){
        Route::get('/', [Seguridad\ModuloController::class,'index'])->name('modulos.index');
        Route::post('/', [Seguridad\ModuloController::class,'store'])->name('modulos.store');
            // ->middleware(['permission:CrearModulo']);
        Route::get('/{id}', [Seguridad\ModuloController::class,'show'])->name('modulos.show');
            // ->middleware(['permission:ListarModulo']);
        Route::put('/{id}', [Seguridad\ModuloController::class,'update'])->name('modulos.update');
            // ->middleware(['permission:ModificarModulo']);
        Route::delete('/{id}', [Seguridad\ModuloController::class,'destroy'])->name('modulos.delete');
            // ->middleware(['permission:EliminarModulo']);
    });

    // Opciones del Sistema
    Route::group(["prefix" => "opciones-del-sistema"],function(){
        Route::get('/', [Seguridad\OpcionSistemaController::class,'index'])->name('opciones-del-sistema.index');
        Route::post('/', [Seguridad\OpcionSistemaController::class,'store'])->name('opciones-del-sistema.store');
            // ->middleware(['permission:CrearOpcionSistema']);
        Route::get('/{id}', [Seguridad\OpcionSistemaController::class,'show'])->name('opciones-del-sistema.show');
            // ->middleware(['permission:ListarOpcionSistema']);
        Route::put('/{id}', [Seguridad\OpcionSistemaController::class,'update'])->name('opciones-del-sistema.update');
            // ->middleware(['permission:ModificarOpcionSistema']);
        Route::delete('/{id}', [Seguridad\OpcionSistemaController::class,'destroy'])->name('opciones-del-sistema.delete');
            // ->middleware(['permission:EliminarOpcionSistema']);
    });

    // Permisos
    Route::group(["prefix" => "permisos"],function(){
        Route::get('/', [Seguridad\PermisoController::class,'index'])->name('permisos.index');
        Route::post('/', [Seguridad\PermisoController::class,'store'])->name('permisos.store');
        Route::get('/{id}', [Seguridad\PermisoController::class,'show'])->name('permisos.show');
        Route::put('/{id}', [Seguridad\PermisoController::class,'update'])->name('permisos.update');
        Route::delete('/{id}', [Seguridad\PermisoController::class,'destroy'])->name('permisos.delete');
    });

    // Auditoria Tablas
    Route::group(["prefix" => "auditoria-tablas"],function(){
        Route::get('/', [Seguridad\AuditoriaTablaController::class,'index'])->name('auditoria-tablas.index');
    });

    // ---------------------- Parametrizacion -------------------------- //

     // Parametros correos
     Route::group(["prefix" => "parametros-correo"],function(){
        Route::get('/', [Parametrizacion\ParametroCorreoController::class,'index'])->name('parametros_correo.index');
        Route::post('/', [Parametrizacion\ParametroCorreoController::class,'store'])->name('parametros_correo.store');
            // ->middleware(['permission:CrearAplicacion']);
        Route::get('/{id}', [Parametrizacion\ParametroCorreoController::class,'show'])->name('parametros_correo.show');
            // ->middleware(['permission:ListarAplicacion']);
        Route::put('/{id}', [Parametrizacion\ParametroCorreoController::class,'update'])->name('parametros_correo.update');
            // ->middleware(['permission:ModificarAplicacion']);
        Route::delete('/{id}', [Parametrizacion\ParametroCorreoController::class,'destroy'])->name('parametros_correo.delete');
            // ->middleware(['permission:EliminarAplicacion']);
    });

    // Parametros Constantes
    Route::group(["prefix" => "parametros-constantes"],function(){
        Route::get('/', [Parametrizacion\ParametroConstanteController::class,'index'])->name('parametros-constantes.index');
        Route::post('/', [Parametrizacion\ParametroConstanteController::class,'store'])->name('parametros-constantes.store');
        Route::get('/consultar', [Parametrizacion\ParametroConstanteController::class,'consultar'])->name('parametros-constantes.consultar');
        Route::get('/{id}', [Parametrizacion\ParametroConstanteController::class,'show'])->name('parametros-constantes.show');
        Route::put('/{id}', [Parametrizacion\ParametroConstanteController::class,'update'])->name('parametros-constantes.update');
        Route::delete('/{id}', [Parametrizacion\ParametroConstanteController::class,'destroy'])->name('parametros-constantes.delete');
    });


    // Ciudades
    Route::group(["prefix" => "ciudades"],function(){
        Route::get('/', [Parametrizacion\CiudadController::class,'index'])->name('ciudades.index');
        Route::post('/', [Parametrizacion\CiudadController::class,'store'])->name('ciudades.store');
        Route::get('/{id}', [Parametrizacion\CiudadController::class,'show'])->name('ciudades.show');
        Route::put('/{id}', [Parametrizacion\CiudadController::class,'update'])->name('ciudades.update');
        Route::delete('/{id}', [Parametrizacion\CiudadController::class,'destroy'])->name('ciudades.delete');
    });

    // Tipos de Proyectos
    Route::group(["prefix" => "tipos-proyectos"],function(){
        Route::get('/', [Parametrizacion\TipoProyectoController::class,'index'])->name('tipos-proyectos.index');
        Route::post('/', [Parametrizacion\TipoProyectoController::class,'store'])->name('tipos-proyectos.store');
        Route::get('/{id}', [Parametrizacion\TipoProyectoController::class,'show'])->name('tipos-proyectos.show');
        Route::put('/{id}', [Parametrizacion\TipoProyectoController::class,'update'])->name('tipos-proyectos.update');
        Route::delete('/{id}', [Parametrizacion\TipoProyectoController::class,'destroy'])->name('tipos-proyectos.delete');
    });

     // Sectores de Proyectos
     Route::group(["prefix" => "sectores-proyectos"],function(){
        Route::get('/', [Parametrizacion\SectorProyectoController::class,'index'])->name('sectores-proyectos.index');
        Route::post('/', [Parametrizacion\SectorProyectoController::class,'store'])->name('sectores-proyectos.store');
        Route::get('/{id}', [Parametrizacion\SectorProyectoController::class,'show'])->name('sectores-proyectos.show');
        Route::put('/{id}', [Parametrizacion\SectorProyectoController::class,'update'])->name('sectores-proyectos.update');
        Route::delete('/{id}', [Parametrizacion\SectorProyectoController::class,'destroy'])->name('sectores-proyectos.delete');
    });


    // Lista de documentos
    Route::group(["prefix" => "listas-documentos"],function(){
        Route::get('/', [Parametrizacion\ListaDocumentoController::class,'index'])->name('listas-documentos.index');
        Route::post('/', [Parametrizacion\ListaDocumentoController::class,'store'])->name('listas-documentos.store');
        Route::get('/{id}', [Parametrizacion\ListaDocumentoController::class,'show'])->name('listas-documentos.show');
        Route::put('/{id}', [Parametrizacion\ListaDocumentoController::class,'update'])->name('listas-documentos.update');
        Route::delete('/{id}', [Parametrizacion\ListaDocumentoController::class,'destroy'])->name('listas-documentos.delete');
    });
    
    // Compañias
    Route::group(["prefix" => "companias"],function(){
        Route::get('/', [Parametrizacion\CompaniaController::class,'index'])->name('companias.index');
        Route::post('/', [Parametrizacion\CompaniaController::class,'store'])->name('companias.store');
        Route::get('//{id}', [Parametrizacion\CompaniaController::class,'show'])->name('companias.show');
        Route::put('/{id}', [Parametrizacion\CompaniaController::class,'update'])->name('companias.update');
        Route::delete('/{id}', [Parametrizacion\CompaniaController::class,'destroy'])->name('companias.delete');
    });

    // Compañias Documentos
    Route::group(["prefix" => "companias-documentos"],function(){
        Route::get('/', [Parametrizacion\CompaniaDocumentoController::class,'index'])->name('companias-documentos.index');
        Route::post('/', [Parametrizacion\CompaniaDocumentoController::class,'store'])->name('companias-documentos.store');
        Route::get('/{id}', [Parametrizacion\CompaniaDocumentoController::class,'show'])->name('companias-documentos.show');
        Route::put('/{id}', [Parametrizacion\CompaniaDocumentoController::class,'update'])->name('companias-documentos.update');
        Route::delete('/{id}/{compania_id}', [Parametrizacion\CompaniaDocumentoController::class,'destroy'])->name('companias-documentos.delete');
    });

    // Gestor
    Route::group(["prefix" => "gestores"],function(){
        Route::get('/', [Parametrizacion\GestorController::class,'index'])->name('gestores.index');
        Route::post('/', [Parametrizacion\GestorController::class,'store'])->name('gestores.store');
        Route::get('/{id}', [Parametrizacion\GestorController::class,'show'])->name('gestores.show');
        Route::put('/{id}', [Parametrizacion\GestorController::class,'update'])->name('gestores.update');
        Route::delete('/{id}', [Parametrizacion\GestorController::class,'destroy'])->name('gestores.delete');
    });

    // Gestor Documentos
    Route::group(["prefix" => "gestores-documentos"],function(){
        Route::get('/', [Parametrizacion\GestorDocumentoController::class,'index'])->name('gestores-documentos.index');
        Route::post('/', [Parametrizacion\GestorDocumentoController::class,'store'])->name('gestores-documentos.store');
        Route::get('/{id}', [Parametrizacion\GestorDocumentoController::class,'show'])->name('gestores-documentos.show');
        Route::put('/{id}', [Parametrizacion\GestorDocumentoController::class,'update'])->name('gestores-documentos.update');
        Route::delete('/{id}/{gestor_id}', [Parametrizacion\GestorDocumentoController::class,'destroy'])->name('gestores-documentos.delete');
    });

    // Categorias de Inversión
    Route::group(["prefix" => "tipos-inversiones"],function(){
        Route::get('/', [Parametrizacion\TipoInversionController::class,'index'])->name('tipos-inversiones.index');
        Route::post('/', [Parametrizacion\TipoInversionController::class,'store'])->name('tipos-inversiones.store');
        Route::get('/{id}', [Parametrizacion\TipoInversionController::class,'show'])->name('tipos-inversiones.show');
        Route::put('/{id}', [Parametrizacion\TipoInversionController::class,'update'])->name('tipos-inversiones.update');
        Route::delete('/{id}', [Parametrizacion\TipoInversionController::class,'destroy'])->name('tipos-inversiones.delete');
    });

    // Proyectos
    Route::group(["prefix" => "proyectos"],function(){
        Route::get('/', [Parametrizacion\ProyectoController::class,'index'])->name('proyectos.index');
        Route::get('/avances', [Parametrizacion\ProyectoController::class, 'avanceProyectos'])->name('proyectos.avanceProyectos');
        Route::post('/', [Parametrizacion\ProyectoController::class,'store'])->name('proyectos.store');
        Route::get('/{id}', [Parametrizacion\ProyectoController::class,'show'])->name('proyectos.show');
        Route::put('/{id}', [Parametrizacion\ProyectoController::class,'update'])->name('proyectos.update');
        Route::delete('/{id}', [Parametrizacion\ProyectoController::class,'destroy'])->name('proyectos.delete');
    });

    // Proyectos Documentos
    Route::group(["prefix" => "proyectos-documentos"],function(){
        Route::get('/', [Parametrizacion\ProyectoDocumentoController::class,'index'])->name('proyectos-documentos.index');
        Route::post('/', [Parametrizacion\ProyectoDocumentoController::class,'store'])->name('proyectos-documentos.store');
        Route::get('/{id}', [Parametrizacion\ProyectoDocumentoController::class,'show'])->name('proyectos-documentos.show');
        Route::put('/{id}', [Parametrizacion\ProyectoDocumentoController::class,'update'])->name('proyectos-documentos.update');
        Route::delete('/{id}/{proyecto_id}', [Parametrizacion\ProyectoDocumentoController::class,'destroy'])->name('proyectos-documentos.delete');
    });

    // Inversionistas
    Route::group(["prefix" => "inversionistas"],function(){
        Route::get('/', [Parametrizacion\InversionistaController::class,'index'])->name('inversionistas.index');
        Route::post('/', [Parametrizacion\InversionistaController::class,'store'])->name('inversionistas.store');
        Route::get('/{id}', [Parametrizacion\InversionistaController::class,'show'])->name('inversionistas.show');
        Route::put('/{id}', [Parametrizacion\InversionistaController::class,'update'])->name('inversionistas.update');
        Route::delete('/{id}', [Parametrizacion\InversionistaController::class,'destroy'])->name('inversionistas.delete');
    });

    // Inversionistas contactos
    Route::group(["prefix" => "inversionistas-contactos"],function(){
        Route::get('/{inversionista_id}', [Parametrizacion\InversionistaContactoController::class,'index'])->name('inversionistas-contactos.index');
        Route::post('/', [Parametrizacion\InversionistaContactoController::class,'store'])->name('inversionistas-contactos.store');
        Route::get('/{inversionista_id}/{id}', [Parametrizacion\InversionistaContactoController::class,'show'])->name('inversionistas-contactos.show');
        Route::put('/{id}', [Parametrizacion\InversionistaContactoController::class,'update'])->name('inversionistas-contactos.update');
        Route::delete('/{id}', [Parametrizacion\InversionistaContactoController::class,'destroy'])->name('inversionistas-contactos.delete');
    });

    // Inversionistas contactos legal
    Route::group(["prefix" => "inversionistas-contactos-legales"],function(){
        Route::get('/contacto', [Parametrizacion\InversionistaContactoLegalController::class,'indexDoc'])->name('inversionistas-contactos-legales.indexDoc');
        Route::get('/{inversionista_id}', [Parametrizacion\InversionistaContactoLegalController::class,'index'])->name('inversionistas-contactos-legales.index');
        Route::post('/', [Parametrizacion\InversionistaContactoLegalController::class,'store'])->name('inversionistas-contactos-legales.store');
        Route::get('/{inversionista_id}/{id}', [Parametrizacion\InversionistaContactoLegalController::class,'show'])->name('inversionistas-contactos-legales.show');
        Route::put('/{id}', [Parametrizacion\InversionistaContactoLegalController::class,'update'])->name('inversionistas-contactos-legales.update');
        Route::delete('/{id}', [Parametrizacion\InversionistaContactoLegalController::class,'destroy'])->name('inversionistas-contactos-legales.delete');
    });
    
    // Inversionistas Documentos
    Route::group(["prefix" => "inversionistas-documentos"],function(){
        Route::get('/', [Parametrizacion\InversionistaDocumentoController::class,'index'])->name('inversionistas-documentos.index');
        Route::post('/', [Parametrizacion\InversionistaDocumentoController::class,'store'])->name('inversionistas-documentos.store');
        Route::get('/{id}', [Parametrizacion\InversionistaDocumentoController::class,'show'])->name('inversionistas-documentos.show');
        Route::put('/', [Parametrizacion\InversionistaDocumentoController::class,'update'])->name('inversionistas-documentos.update');
        Route::delete('/{id}/{proyecto_id}', [Parametrizacion\InversionistaDocumentoController::class,'destroy'])->name('inversionistas-documentos.delete');
    });

    // Vehiculos Inversión
    Route::group(["prefix" => "vehiculos-inversion"],function(){
        Route::get('/', [Parametrizacion\VehiculoInversionController::class,'index'])->name('vehiculos-inversion.index');
        Route::post('/', [Parametrizacion\VehiculoInversionController::class,'store'])->name('vehiculos-inversion.store');
        Route::get('/{id}', [Parametrizacion\VehiculoInversionController::class,'show'])->name('vehiculos-inversion.show');
        Route::put('/{id}', [Parametrizacion\VehiculoInversionController::class,'update'])->name('vehiculos-inversion.update');
        Route::delete('/{id}', [Parametrizacion\VehiculoInversionController::class,'destroy'])->name('vehiculos-inversion.delete');
    });

    // Etapas Proyectos
    Route::group(["prefix" => "etapas-proyectos"],function(){
        Route::get('/', [Parametrizacion\EtapaProyectoController::class,'index'])->name('etapas-proyectos.index');
        Route::post('/', [Parametrizacion\EtapaProyectoController::class,'store'])->name('etapas-proyectos.store');
        Route::get('/{id}', [Parametrizacion\EtapaProyectoController::class,'show'])->name('etapas-proyectos.show');
        Route::put('/{id}', [Parametrizacion\EtapaProyectoController::class,'update'])->name('etapas-proyectos.update');
        Route::delete('/{id}', [Parametrizacion\EtapaProyectoController::class,'destroy'])->name('etapas-proyectos.delete');
    });

    // Actividades Proyectos
    Route::group(["prefix" => "actividades-proyectos"],function(){
        Route::get('/', [Parametrizacion\ActividadProyectoController::class,'index'])->name('actividades-proyectos.index');
        Route::post('/', [Parametrizacion\ActividadProyectoController::class,'store'])->name('actividades-proyectos.store');
        Route::get('/{id}', [Parametrizacion\ActividadProyectoController::class,'show'])->name('actividades-proyectos.show');
        Route::put('/{id}', [Parametrizacion\ActividadProyectoController::class,'update'])->name('actividades-proyectos.update');
        Route::delete('/{id}', [Parametrizacion\ActividadProyectoController::class,'destroy'])->name('actividades-proyectos.delete');
    });

    // Desembolsos Proyectos
    Route::group(["prefix" => "desembolsos-proyectos"],function(){
        Route::get('/', [Parametrizacion\DesembolsoProyectoController::class,'index'])->name('desembolsos-proyectos.index');
        Route::post('/', [Parametrizacion\DesembolsoProyectoController::class,'store'])->name('desembolsos-proyectos.store');
        Route::get('/{id}', [Parametrizacion\DesembolsoProyectoController::class,'show'])->name('desembolsos-proyectos.show');
        Route::put('/{id}', [Parametrizacion\DesembolsoProyectoController::class,'update'])->name('desembolsos-proyectos.update');
        Route::delete('/{id}', [Parametrizacion\DesembolsoProyectoController::class,'destroy'])->name('desembolsos-proyectos.delete');
    });

    // Condiciones plazo
    Route::group(["prefix" => "condiciones-plazo"],function(){
        Route::get('/', [Parametrizacion\CondicionPlazoController::class,'index'])->name('condiciones-plazo.index');
        Route::post('/', [Parametrizacion\CondicionPlazoController::class,'store'])->name('condiciones-plazo.store');
        Route::get('/{id}', [Parametrizacion\CondicionPlazoController::class,'show'])->name('condiciones-plazo.show');
        Route::put('/{id}', [Parametrizacion\CondicionPlazoController::class,'update'])->name('condiciones-plazo.update');
        Route::delete('/{id}', [Parametrizacion\CondicionPlazoController::class,'destroy'])->name('condiciones-plazo.delete');
    });

    // Conceptos Proyectos
    Route::group(["prefix" => "conceptos-proyectos"],function(){
        Route::get('/', [Parametrizacion\ConceptoProyectoController::class,'index'])->name('conceptos-proyectos.index');
        Route::post('/', [Parametrizacion\ConceptoProyectoController::class,'store'])->name('conceptos-proyectos.store');
        Route::get('/{id}', [Parametrizacion\ConceptoProyectoController::class,'show'])->name('conceptos-proyectos.show');
        Route::put('/{id}', [Parametrizacion\ConceptoProyectoController::class,'update'])->name('conceptos-proyectos.update');
        Route::delete('/{id}', [Parametrizacion\ConceptoProyectoController::class,'destroy'])->name('conceptos-proyectos.delete');
    });
    
    // Parametros Mensuales
    Route::group(["prefix" => "parametros-mensuales"],function(){
        Route::get('/', [Parametrizacion\ParametroMensualController::class,'index'])->name('parametros-mensuales.index');
        Route::post('/', [Parametrizacion\ParametroMensualController::class,'store'])->name('parametros-mensuales.store');
        Route::get('/{id}', [Parametrizacion\ParametroMensualController::class,'show'])->name('parametros-mensuales.show');
        Route::put('/{id}', [Parametrizacion\ParametroMensualController::class,'update'])->name('parametros-mensuales.update');
        Route::delete('/{id}', [Parametrizacion\ParametroMensualController::class,'destroy'])->name('parametros-mensuales.delete');
    });

    // Actividades Por Proyectos
    Route::group(["prefix" => "actividades-por-proyectos"],function(){
        Route::get('/{proyecto_id}', [Parametrizacion\ActividadPorProyectoController::class,'index'])->name('actividades-por-proyectos.index');
        Route::post('/', [Parametrizacion\ActividadPorProyectoController::class,'store'])->name('actividades-por-proyectos.store');
        Route::get('/show/{id}', [Parametrizacion\ActividadPorProyectoController::class,'show'])->name('actividades-por-proyectos.show');
        Route::put('/{id}', [Parametrizacion\ActividadPorProyectoController::class,'update'])->name('actividades-por-proyectos.update');
        Route::delete('/{id}', [Parametrizacion\ActividadPorProyectoController::class,'destroy'])->name('actividades-por-proyectos.delete');
    });

    // Simulación Por Proyectos
    Route::group(["prefix" => "simulaciones-por-proyectos"],function(){
        Route::get('/{proyecto_id}', [Parametrizacion\ProyectoSimulacionController::class,'index'])->name('simulaciones-por-proyectos.index');
        Route::post('/', [Parametrizacion\ProyectoSimulacionController::class,'store'])->name('simulaciones-por-proyectos.store');
        Route::post('/guardar', [Parametrizacion\ProyectoSimulacionController::class,'guardar'])->name('simulaciones-por-proyectos.guardar');
        Route::post('/inicializar-edicion', [Parametrizacion\ProyectoSimulacionController::class, 'initUpdateSimulacion'])->name('simulaciones-por-proyectos.initUpdateSimulacion');
        Route::post('/inicializar-inversionista', [Parametrizacion\ProyectoSimulacionController::class, 'initSimulacionInversionista'])->name('simulaciones-por-proyectos.initSimulacionInversionista');
        Route::post('/guardar-inversionista', [Parametrizacion\ProyectoSimulacionController::class, 'guardarSimulacionInversionista'])->name('simulaciones-por-proyectos.guardarSimulacionInversionista');
        Route::get('/show/{id}', [Parametrizacion\ProyectoSimulacionController::class,'show'])->name('simulaciones-por-proyectos.show');
        Route::put('/{id}', [Parametrizacion\ProyectoSimulacionController::class,'update'])->name('simulaciones-por-proyectos.update');
        Route::delete('/{id}', [Parametrizacion\ProyectoSimulacionController::class,'destroy'])->name('simulaciones-por-proyectos.delete');
    });

    // Conceptos Por Proyectos
    Route::group(["prefix" => "conceptos-por-proyectos"],function(){
        Route::get('/', [Parametrizacion\ConceptoPorProyectoController::class,'index'])->name('conceptos-por-proyectos.index');
        Route::post('/', [Parametrizacion\ConceptoPorProyectoController::class,'store'])->name('conceptos-por-proyectos.store');
        Route::post('/copiar', [Parametrizacion\ConceptoPorProyectoController::class, 'copiar'])->name('conceptos-por-proyectos.copiar');
        Route::post('/calcular', [Parametrizacion\ConceptoPorProyectoController::class, 'calcular'])->name('conceptos-por-proyectos.calcular');
        Route::post('/guardar', [Parametrizacion\ConceptoPorProyectoController::class, 'guardar'])->name('conceptos-por-proyectos.guardar');
        Route::get('/show/{id}', [Parametrizacion\ConceptoPorProyectoController::class,'show'])->name('conceptos-por-proyectos.show');
        Route::get('/conceptos-simulacion', [Parametrizacion\ConceptoPorProyectoController::class, 'conceptosSimulacion'])->name('conceptos-por-proyectos.simulaciones');
        Route::post('/calcular-simulacion', [Parametrizacion\ConceptoPorProyectoController::class, 'calcularSimulacion'])->name('conceptos-por-proyectos.calcularSimulacion');       
        Route::put('/{id}', [Parametrizacion\ConceptoPorProyectoController::class,'update'])->name('conceptos-por-proyectos.update');
        Route::delete('/{id}', [Parametrizacion\ConceptoPorProyectoController::class,'destroy'])->name('conceptos-por-proyectos.delete');
    });

    // Comunidades Energeticas
    Route::group(["prefix" => "comunidades-energeticas"],function(){
        Route::get('/', [Parametrizacion\ComunidadEnergeticaController::class,'index'])->name('comunidades-energeticas.index');
        Route::post('/', [Parametrizacion\ComunidadEnergeticaController::class,'store'])->name('comunidades-energeticas.store');
        Route::get('/{id}', [Parametrizacion\ComunidadEnergeticaController::class,'show'])->name('comunidades-energeticas.show');
        Route::put('/{id}', [Parametrizacion\ComunidadEnergeticaController::class,'update'])->name('comunidades-energeticas.update');
        Route::delete('/{id}', [Parametrizacion\ComunidadEnergeticaController::class,'destroy'])->name('comunidades-energeticas.delete');
    });

    // Fotos Por Proyectos
    Route::group(["prefix" => "proyectos-fotos"],function(){
        Route::get('/', [Parametrizacion\ProyectoFotoController::class,'index'])->name('proyectos-fotos.index');
        Route::post('/', [Parametrizacion\ProyectoFotoController::class,'store'])->name('proyectos-fotos.store');
        Route::get('/{id}', [Parametrizacion\ProyectoFotoController::class,'show'])->name('proyectos-fotos.show');
        Route::put('/{id}', [Parametrizacion\ProyectoFotoController::class,'update'])->name('proyectos-fotos.update');
        Route::delete('/{id}', [Parametrizacion\ProyectoFotoController::class,'destroy'])->name('proyectos-fotos.delete');
    });

    // Bancos
    Route::group(["prefix" => "bancos"],function(){
        Route::get('/', [Parametrizacion\BancoController::class,'index'])->name('bancos.index');
        Route::post('/', [Parametrizacion\BancoController::class,'store'])->name('bancos.store');
        Route::get('//{id}', [Parametrizacion\BancoController::class,'show'])->name('bancos.show');
        Route::put('/{id}', [Parametrizacion\BancoController::class,'update'])->name('bancos.update');
        Route::delete('/{id}', [Parametrizacion\BancoController::class,'destroy'])->name('bancos.delete');
    });

    // Proveedores
    Route::group(["prefix" => "proveedores"],function(){
        Route::get('/', [Parametrizacion\ProveedorController::class,'index'])->name('proveedores.index');
        Route::post('/', [Parametrizacion\ProveedorController::class,'store'])->name('proveedores.store');
        Route::get('//{id}', [Parametrizacion\ProveedorController::class,'show'])->name('proveedores.show');
        Route::put('/{id}', [Parametrizacion\ProveedorController::class,'update'])->name('proveedores.update');
        Route::delete('/{id}', [Parametrizacion\ProveedorController::class,'destroy'])->name('proveedores.delete');
    });

    // Proveedores Documentos
    Route::group(["prefix" => "proveedores-documentos"],function(){
        Route::get('/', [Parametrizacion\ProveedorDocumentoController::class,'index'])->name('proveedores-documentos.index');
        Route::post('/', [Parametrizacion\ProveedorDocumentoController::class,'store'])->name('proveedores-documentos.store');
        Route::get('/{id}', [Parametrizacion\ProveedorDocumentoController::class,'show'])->name('proveedores-documentos.show');
        Route::put('/{id}', [Parametrizacion\ProveedorDocumentoController::class,'update'])->name('proveedores-documentos.update');
        Route::delete('/{id}/{proveedor_id}', [Parametrizacion\ProveedorDocumentoController::class,'destroy'])->name('proveedores-documentos.delete');
    });

    // Precios Proyectados
    Route::group(["prefix" => "precios-proyectados"],function(){
        Route::get('/', [Parametrizacion\PrecioProyectadoController::class,'index'])->name('precios-proyectados.index');
        Route::post('/', [Parametrizacion\PrecioProyectadoController::class,'store'])->name('precios-proyectados.store');
        Route::get('//{id}', [Parametrizacion\PrecioProyectadoController::class,'show'])->name('precios-proyectados.show');
        Route::put('/{id}', [Parametrizacion\PrecioProyectadoController::class,'update'])->name('precios-proyectados.update');
        Route::delete('/{id}', [Parametrizacion\PrecioProyectadoController::class,'destroy'])->name('precios-proyectados.delete');
    });

    // Nieveles Consumos
    Route::group(["prefix" => "niveles-consumos"],function(){
        Route::get('/', [Parametrizacion\NivelCosumoController::class,'index'])->name('niveles-consumos.index');
        Route::post('/', [Parametrizacion\NivelCosumoController::class,'store'])->name('niveles-consumos.store');
        Route::get('//{id}', [Parametrizacion\NivelCosumoController::class,'show'])->name('niveles-consumos.show');
        Route::put('/{id}', [Parametrizacion\NivelCosumoController::class,'update'])->name('niveles-consumos.update');
        Route::delete('/{id}', [Parametrizacion\NivelCosumoController::class,'destroy'])->name('niveles-consumos.delete');
    });


    // Descuentos Tarifas
    Route::group(["prefix" => "descuentos-tarifas"],function(){
        Route::get('/matriz/data', [Parametrizacion\DescuentoTarifaController::class, 'obtenerMatriz'])->name('descuentos-tarifas.obtenerMatriz');
        Route::post('/matriz/guardar', [Parametrizacion\DescuentoTarifaController::class, 'guardarMatriz'])->name('descuentos-tarifas.guardarMatriz');

    });
    
    // ---------------------- Inversiones -------------------------- //

    // Inversiones 
    Route::group(["prefix" => "inversiones"],function(){
        Route::get('/', [Inversiones\InversionesController::class,'index'])->name('inversiones.index');
        Route::post('/', [Inversiones\InversionesController::class,'store'])->name('inversiones.store');
        Route::get('/{id}', [Inversiones\InversionesController::class,'show'])->name('inversiones.show');
        Route::put('/anular/{id}', [Inversiones\InversionesController::class,'destroy'])->name('inversiones.delete');
        Route::put('/{id}', [Inversiones\InversionesController::class,'update'])->name('inversiones.update');
    });

    // Proyecto Plan Inversión 
    Route::group(["prefix" => "proyectos-plan-inversiones"],function(){
        Route::get('/', [Inversiones\ProyectoPlanInversionController::class,'index'])->name('proyectos-plan-inversiones.index');
        Route::post('/', [Inversiones\ProyectoPlanInversionController::class,'store'])->name('proyectos-plan-inversiones.store');
        Route::get('/{id_inversionista}/{id_proyecto}', [Inversiones\ProyectoPlanInversionController::class,'show'])->name('proyectos-plan-inversiones.show');
        Route::put('/{id_inversionista}/{id_proyecto}', [Inversiones\ProyectoPlanInversionController::class,'update'])->name('proyectos-plan-inversiones.update');
        Route::delete('/{id_inversionista}/{id_proyecto}', [Inversiones\ProyectoPlanInversionController::class,'destroy'])->name('proyectos-plan-inversiones.delete');
    });

    // Plan Detallado
    Route::group(["prefix" => "plan-detallado"],function(){
        Route::get('/', [Inversiones\PlanDetalladoController::class,'index'])->name('plan-detallado.index');
        Route::post('/', [Inversiones\PlanDetalladoController::class,'store'])->name('plan-detallado.store');
        Route::get('/{id}', [Inversiones\PlanDetalladoController::class,'show'])->name('plan-detallado.show');
        Route::put('/{id}', [Inversiones\PlanDetalladoController::class,'update'])->name('plan-detallado.update');
        Route::delete('/{id}/{inversion_id}', [Inversiones\PlanDetalladoController::class,'destroy'])->name('plan-detallado.delete');
    });

    // Inversión Proyecto
    Route::group(["prefix" => "inversiones-proyectos"],function(){
        Route::get('/', [Inversiones\InversionProyectoController::class,'index'])->name('inversiones-proyectos.index');
        Route::post('/', [Inversiones\InversionProyectoController::class,'store'])->name('inversiones-proyectos.store');
        Route::get('/{id}', [Inversiones\InversionProyectoController::class,'show'])->name('inversiones-proyectos.show');
        Route::put('/{id}', [Inversiones\InversionProyectoController::class,'update'])->name('inversiones-proyectos.update');
        Route::delete('/{id}/{inversion_id}', [Inversiones\InversionProyectoController::class,'destroy'])->name('inversiones-proyectos.delete');
    });


    Route::group(["prefix" => "programaciones"], function () {
        Route::get('/lista', [Inversiones\PlanDetalladoController::class, 'ProgramacionInv'])->name('programaciones.lista');
        Route::get('/programadas', [Inversiones\PlanDetalladoController::class,'listarPagosProgramados'])->name('programaciones.programadas');
        Route::get('/resumenSemanal', [Inversiones\PlanDetalladoController::class,'listarResumenSemanalaDetallado'])->name('programaciones.resumenSemanal');
        Route::put('/guardar', [Inversiones\PlanDetalladoController::class,'ModificarProgramacionInv'])->name('programaciones.guardar');
        Route::put('/pagos', [Inversiones\PlanDetalladoController::class,'confirmarPagoIndividual'])->name('programaciones.pagos');
        Route::get('/historico-pagos', [Inversiones\PlanDetalladoController::class,'listarHcaPagosDetallado'])->name('programaciones.HcaPagos');
    });

    Route::post('/backup', [BackupController::class, 'createBackup']);
    Route::post('/extractos', [ExtractoController::class, 'enviarExtractos']);
    Route::get('/exportar-portafolio', [PortafolioController::class, 'generarPDF']);

    
});