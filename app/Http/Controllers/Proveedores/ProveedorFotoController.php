<?php

namespace App\Http\Controllers\Proveedores;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Services\ArchivosService;
use Illuminate\Support\Facades\Validator;
use App\Models\Proveedores\ProveedorTuristico;
use App\Http\Controllers\Concerns\AlcancePorRol;

/**
 * Logo y fotos del negocio del proveedor (se muestran en su perfil público).
 * Dos entradas a los mismos métodos:
 *  - proveedores-turisticos/{id}/...  el administrador, con permiso ModificarProveedorTuristico.
 *  - cuenta/proveedor/...             el proveedor sobre su propio negocio (sin id: sale del token).
 * Cada respuesta devuelve { logo, fotos } actualizados.
 */
class ProveedorFotoController extends Controller
{
    use AlcancePorRol;

    public function subirLogo(Request $request, $id = null)
    {
        return $this->ejecutar($id, function ($proveedor) use ($request) {
            $validator = Validator::make($request->all(), ['archivo' => ArchivosService::REGLA_IMAGEN . '|required']);
            if ($validator->fails()) {
                return response(get_response_body(format_messages_validator($validator)), Response::HTTP_BAD_REQUEST);
            }
            ArchivosService::adjuntar($request, 'proveedores_turisticos', $proveedor->id, 'logo',
                $this->carpeta($proveedor, 'logo'), $proveedor->logo);
            return ['El logo fue actualizado.', 1];
        });
    }

    public function eliminarLogo($id = null)
    {
        return $this->ejecutar($id, function ($proveedor) {
            ArchivosService::programarEliminacion($proveedor->logo);
            DB::table('proveedores_turisticos')->where('id', $proveedor->id)->update(['logo' => null, 'updated_at' => now()]);
            return ['El logo fue eliminado.', 3];
        });
    }

    public function subirFoto(Request $request, $id = null)
    {
        return $this->ejecutar($id, function ($proveedor) use ($request) {
            $validator = Validator::make($request->all(), ['archivo' => ArchivosService::REGLA_IMAGEN . '|required']);
            if ($validator->fails()) {
                return response(get_response_body(format_messages_validator($validator)), Response::HTTP_BAD_REQUEST);
            }
            $total = DB::table('proveedor_fotos')->where('proveedor_id', $proveedor->id)->count();
            if ($total >= ProveedorTuristico::MAX_FOTOS) {
                return response(
                    get_response_body(['Puedes tener máximo ' . ProveedorTuristico::MAX_FOTOS . ' fotos. Elimina alguna para subir otra.']),
                    Response::HTTP_CONFLICT
                );
            }
            DB::table('proveedor_fotos')->insert([
                'proveedor_id' => $proveedor->id,
                'ruta_archivo' => ArchivosService::guardar($request->file('archivo'), $this->carpeta($proveedor, 'fotos')),
                'posicion' => $total + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return ['La foto fue agregada.', 2];
        });
    }

    public function eliminarFoto($fotoId, $id = null)
    {
        return $this->ejecutar($id, function ($proveedor) use ($fotoId) {
            $foto = DB::table('proveedor_fotos')->where('id', $fotoId)->where('proveedor_id', $proveedor->id)->first();
            if (!$foto) {
                return response(get_response_body(['La foto no existe.']), Response::HTTP_NOT_FOUND);
            }
            ArchivosService::programarEliminacion($foto->ruta_archivo);
            DB::table('proveedor_fotos')->where('id', $foto->id)->delete();
            return ['La foto fue eliminada.', 3];
        });
    }

    // Rutas del administrador: el id va en la URL.
    public function eliminarFotoDeProveedor($id, $fotoId)
    {
        return $this->eliminarFoto($fotoId, $id);
    }

    /**
     * Resuelve el proveedor (el de la URL o el del usuario), corre la operación en una transacción y
     * responde con el logo y las fotos. La operación devuelve el mensaje [texto, tipo] o una respuesta de error.
     */
    private function ejecutar($id, callable $operacion)
    {
        $proveedorId = $id ?? DB::table('proveedores_turisticos')->where('usuario_id', $this->usuarioActualId())->value('id');
        $proveedor = $proveedorId ? DB::table('proveedores_turisticos')->where('id', $proveedorId)->first() : null;
        if (!$proveedor) {
            return response(get_response_body(['Tu cuenta no tiene un negocio vinculado.']), Response::HTTP_NOT_FOUND);
        }

        DB::beginTransaction();
        try {
            $resultado = $operacion($proveedor);
            if ($resultado instanceof Response) {
                DB::rollback();
                ArchivosService::revertir();
                return $resultado;
            }
            DB::commit();
            ArchivosService::confirmar();
            return response(get_response_body($resultado, [
                'logo' => DB::table('proveedores_turisticos')->where('id', $proveedor->id)->value('logo'),
                'fotos' => ProveedorTuristico::fotosDe($proveedor->id),
            ]), Response::HTTP_OK);
        } catch (Exception $e) {
            DB::rollback();
            ArchivosService::revertir();
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function carpeta($proveedor, $subcarpeta)
    {
        return ArchivosService::carpeta('proveedores', $proveedor->id, $proveedor->nombre_comercial, $subcarpeta);
    }
}
