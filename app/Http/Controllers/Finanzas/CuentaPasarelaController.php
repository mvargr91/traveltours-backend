<?php

namespace App\Http\Controllers\Finanzas;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AlcancePorRol;
use Illuminate\Support\Facades\Validator;
use App\Models\Finanzas\CuentaPasarela;

/**
 * Finanzas > Pasarelas de pago: la cuenta de Wompi o Mercado Pago con la que cada proveedor cobra en línea.
 *  - Administración: ve y edita todas, incluida la de la plataforma (tours propios y cobro de liquidaciones).
 *  - Proveedor: solo la suya (el proveedor se toma del token).
 * Protecciones: guardar llaves pide la contraseña del usuario; la cuenta de la plataforma solo la toca el
 * SuperUsuario; cada cambio se avisa por correo (CuentaPasarela -> AvisosPasarela).
 */
class CuentaPasarelaController extends Controller
{
    use AlcancePorRol;

    private const ROL_SUPERUSUARIO_ID = 1;

    // La cuenta de la plataforma cobra los tours propios y las liquidaciones: solo el SuperUsuario la cambia.
    private function sinAccesoAPlataforma($proveedorId)
    {
        $esPlataforma = (bool) DB::table('proveedores_turisticos')->where('id', $proveedorId)->value('es_plataforma');
        if (!$esPlataforma || (int) (Auth::user()?->rol()->id ?? 0) === self::ROL_SUPERUSUARIO_ID) {
            return null;
        }
        return response(get_response_body(['Solo el SuperUsuario puede cambiar la pasarela de la plataforma.']), Response::HTTP_FORBIDDEN);
    }

    // Busca la cuenta y valida alcance y acceso a la de la plataforma: [cuenta, respuesta de error].
    private function cuentaPermitida($id)
    {
        $cuenta = CuentaPasarela::find($id);
        if (!$cuenta) {
            return [null, response(get_response_body(['La pasarela no existe.']), Response::HTTP_NOT_FOUND)];
        }
        if (!$this->enAlcance($cuenta->proveedor_id)) {
            return [null, $this->respuestaSinAcceso()];
        }
        return [$cuenta, $this->sinAccesoAPlataforma($cuenta->proveedor_id)];
    }

    // Lo que necesita el formulario: pasarelas, sus campos, ambientes y estados.
    private function catalogos(): array
    {
        return [
            'pasarelas' => collect(CuentaPasarela::PASARELAS)->map(fn ($clase, $id) => [
                'id' => $id,
                'nombre' => CuentaPasarela::NOMBRES_PASARELA[$id],
                'campos' => collect($clase::campos())->map(fn ($c, $nombre) => [
                    'nombre' => $nombre,
                    'etiqueta' => $c[0],
                    'requerido' => $c[1],
                    'secreto' => $c[2],
                    'ayuda' => $c[3],
                ])->values(),
            ])->values(),
            'ambientes' => collect(CuentaPasarela::AMBIENTES)->map(fn ($n, $id) => ['id' => $id, 'nombre' => $n])->values(),
            'estados' => collect(CuentaPasarela::ESTADOS)->map(fn ($n, $id) => ['id' => $id, 'nombre' => $n])->values(),
        ];
    }

    public function index(Request $request)
    {
        try {
            $datos = $this->aplicarAlcance($request->all(), 'proveedor_id', null);
            return response(CuentaPasarela::obtenerColeccion($datos) + $this->catalogos(), Response::HTTP_OK);
        } catch (Exception $e) {
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function catalogo()
    {
        return response($this->catalogos(), Response::HTTP_OK);
    }

    public function show($id)
    {
        $cuenta = CuentaPasarela::find($id);
        if (!$cuenta) {
            return response(get_response_body(['La pasarela no existe.']), Response::HTTP_NOT_FOUND);
        }
        if (!$this->enAlcance($cuenta->proveedor_id)) {
            return $this->respuestaSinAcceso();
        }
        return response(CuentaPasarela::cargar($id), Response::HTTP_OK);
    }

    private function reglas(array $datos, $id = null): array
    {
        $reglas = [
            'proveedor_id' => 'integer|required|exists:proveedores_turisticos,id|unique:cuentas_pasarela,proveedor_id' . ($id ? ",{$id}" : ''),
            'pasarela' => 'string|required|in:' . implode(',', array_keys(CuentaPasarela::PASARELAS)),
            'ambiente' => 'string|required|in:' . implode(',', array_keys(CuentaPasarela::AMBIENTES)),
            'credenciales' => 'array|nullable',
        ];
        $clase = CuentaPasarela::PASARELAS[$datos['pasarela'] ?? ''] ?? null;
        foreach ($clase ? $clase::campos() : [] as $campo => [$etiqueta, $requerido]) {
            // Al modificar, un secreto vacío conserva el guardado: solo se exige al crear.
            $reglas["credenciales.{$campo}"] = ($requerido && !$id ? 'required' : 'nullable') . '|string|max:255';
        }
        return $reglas;
    }

    private function mensajes($datos): array
    {
        $mensajes = [
            'proveedor_id.unique' => 'Este proveedor ya tiene una pasarela de pago: modifícala en lugar de crear otra.',
            'clave_confirmacion.required' => 'Escribe tu contraseña para guardar las llaves.',
            'pasarela.in' => 'La pasarela debe ser Wompi o Mercado Pago.',
        ];
        $clase = CuentaPasarela::PASARELAS[$datos['pasarela'] ?? ''] ?? null;
        foreach ($clase ? $clase::campos() : [] as $campo => [$etiqueta]) {
            $mensajes["credenciales.{$campo}.required"] = "Escribe el campo {$etiqueta}.";
        }
        return $mensajes;
    }

    private function guardar(Request $request, $id = null)
    {
        DB::beginTransaction();
        try {
            $datos = $this->aplicarAlcance($request->all(), 'proveedor_id', null);
            if ($id) {
                [$cuenta, $error] = $this->cuentaPermitida($id);
                if ($error) {
                    return $error;
                }
                $datos['id'] = $id;
                $datos['proveedor_id'] = $cuenta->proveedor_id; // la cuenta no cambia de dueño
            }
            $validator = Validator::make($datos, $this->reglas($datos, $id) + ['clave_confirmacion' => 'string|required'], $this->mensajes($datos));
            if ($validator->fails()) {
                return response(get_response_body(format_messages_validator($validator)), Response::HTTP_BAD_REQUEST);
            }
            if (!$id && ($error = $this->sinAccesoAPlataforma($datos['proveedor_id']))) {
                return $error;
            }
            // Quien cambia las llaves cambia a dónde llega el dinero: una sesión abierta no basta.
            if (!Hash::check((string) $datos['clave_confirmacion'], (string) Auth::user()?->password)) {
                return response(get_response_body(['La contraseña no es correcta.']), Response::HTTP_BAD_REQUEST);
            }
            unset($datos['clave_confirmacion']);

            $cuenta = CuentaPasarela::modificarOCrear($datos);
            DB::commit();

            // Se prueba de una vez: si las llaves sirven, la cuenta queda activa.
            $prueba = $cuenta->estado === 'pendiente' ? CuentaPasarela::probar($cuenta->id) : ['ok' => true, 'error' => null];
            $mensaje = $prueba['ok']
                ? ['La pasarela de pago ha sido guardada y está activa.', $id ? 1 : 2]
                : ['La pasarela se guardó, pero la prueba de conexión falló: ' . $prueba['error'], 4];
            return response(get_response_body($mensaje, CuentaPasarela::cargar($cuenta->id)), $id ? Response::HTTP_OK : Response::HTTP_CREATED);
        } catch (Exception $e) {
            DB::rollback();
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(Request $request)
    {
        return $this->guardar($request);
    }

    public function update(Request $request, $id)
    {
        return $this->guardar($request, $id);
    }

    public function probar($id)
    {
        [$cuenta, $error] = $this->cuentaPermitida($id);
        if ($error) {
            return $error;
        }
        try {
            // Una cuenta apagada a mano sigue apagada aunque la prueba pase.
            $resultado = CuentaPasarela::probar($id, $cuenta->estado !== 'inactiva');
            $mensaje = $resultado['ok']
                ? ['Conexión correcta' . ($resultado['comercio'] ? " con el comercio {$resultado['comercio']}" : '') . '.', 1]
                : ['La prueba de conexión falló: ' . $resultado['error'], 4];
            return response(get_response_body($mensaje, CuentaPasarela::cargar($id)), Response::HTTP_OK);
        } catch (Exception $e) {
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function cambiarEstado(Request $request, $id)
    {
        $validator = Validator::make($request->all(), ['estado' => 'required|in:activa,inactiva']);
        if ($validator->fails()) {
            return response(get_response_body(format_messages_validator($validator)), Response::HTTP_BAD_REQUEST);
        }
        [$cuenta, $error] = $this->cuentaPermitida($id);
        if ($error) {
            return $error;
        }
        try {
            CuentaPasarela::cambiarEstado($id, $request->estado);
            $texto = $request->estado === 'activa'
                ? 'La pasarela está activa: las experiencias con pago en línea ya se pueden pagar en el portal.'
                : 'La pasarela quedó inactiva: las experiencias del proveedor se reservan sin pago en línea.';
            return response(get_response_body([$texto, 1], CuentaPasarela::cargar($id)), Response::HTTP_OK);
        } catch (Exception $e) {
            return response(get_response_body([$e->getMessage()]), Response::HTTP_CONFLICT);
        }
    }

    public function destroy($id)
    {
        [$cuenta, $error] = $this->cuentaPermitida($id);
        if ($error) {
            return $error;
        }
        try {
            CuentaPasarela::eliminar($id);
            return response(get_response_body(['La pasarela de pago ha sido eliminada.', 3]), Response::HTTP_OK);
        } catch (Exception $e) {
            return response(get_response_body([$e->getMessage()]), Response::HTTP_CONFLICT);
        }
    }
}
