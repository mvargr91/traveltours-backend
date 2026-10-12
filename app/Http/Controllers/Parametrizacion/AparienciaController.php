<?php

namespace App\Http\Controllers\Parametrizacion;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Services\ArchivosService;
use Illuminate\Support\Facades\Validator;
use App\Models\Parametrizacion\Apariencia;

/**
 * Personalización visual del sitio (colores, menú, iconos, logos e imágenes).
 * La lectura es pública (Publico\PortalController::apariencia): el portal la necesita sin sesión.
 */
class AparienciaController extends Controller
{
    private const CARPETA = 'apariencia';

    public function show()
    {
        try {
            return response(Apariencia::cargar(), Response::HTTP_OK);
        } catch (Exception $e) {
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Guarda la configuración completa. Llega como multipart cuando trae imágenes, y entonces
     * "configuracion" viene como texto JSON.
     */
    public function update(Request $request)
    {
        DB::beginTransaction();
        try {
            $datos = $request->all();
            if (is_string($datos['configuracion'] ?? null)) {
                $datos['configuracion'] = json_decode($datos['configuracion'], true);
            }
            $datos['configuracion'] = $datos['configuracion'] ?? [];

            $reglas = Apariencia::reglas() + [
                'quitar' => 'array',
                'quitar.*' => 'string|in:' . implode(',', Apariencia::IMAGENES),
            ];
            foreach (Apariencia::IMAGENES as $columna) {
                $reglas[$columna] = ArchivosService::REGLA_IMAGEN . '|nullable';
            }

            $validator = Validator::make($datos, $reglas);
            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }
            $validados = $validator->validated();

            $anteriores = [];
            foreach (Apariencia::IMAGENES as $columna) {
                $anteriores[$columna] = ArchivosService::rutaActual('apariencia', Apariencia::ID, $columna);
            }

            Apariencia::guardar($validados['configuracion'] ?? [], $validados['quitar'] ?? []);

            // Sube las imágenes nuevas y programa el borrado de las reemplazadas o quitadas.
            foreach (Apariencia::IMAGENES as $columna) {
                ArchivosService::adjuntar($request, 'apariencia', Apariencia::ID, $columna,
                    self::CARPETA . '/' . $columna, $anteriores[$columna], $columna);
            }

            DB::commit();
            ArchivosService::confirmar();
            return response(
                get_response_body(['La apariencia ha sido modificada.', 1], Apariencia::cargar()),
                Response::HTTP_OK
            );
        } catch (Exception $e) {
            DB::rollback();
            ArchivosService::revertir();
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** Vuelve a la apariencia predeterminada y borra las imágenes subidas. */
    public function restablecer()
    {
        DB::beginTransaction();
        try {
            foreach (Apariencia::IMAGENES as $columna) {
                ArchivosService::programarEliminacion(
                    ArchivosService::rutaActual('apariencia', Apariencia::ID, $columna)
                );
            }
            $apariencia = Apariencia::restablecer();

            DB::commit();
            ArchivosService::confirmar();
            return response(
                get_response_body(['La apariencia ha sido restablecida.', 1], $apariencia),
                Response::HTTP_OK
            );
        } catch (Exception $e) {
            DB::rollback();
            ArchivosService::revertir();
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
