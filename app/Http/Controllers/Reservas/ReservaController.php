<?php

namespace App\Http\Controllers\Reservas;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AlcancePorRol;
use Illuminate\Support\Facades\Validator;
use App\Models\Reservas\Reserva;
use App\Models\Turismo\Cupon;
use App\Services\NotificadorReservas;
use App\Services\PagoService;

class ReservaController extends Controller
{
    use AlcancePorRol;

    public function index(Request $request)
    {
        try {
            $datos = $this->aplicarAlcance($request->all());
            if (!$request->ligera) {
                $validator = Validator::make($datos, [
                    'limite' => 'integer|between:1,500'
                ]);

                if ($validator->fails()) {
                    return response(
                        get_response_body(format_messages_validator($validator)),
                        Response::HTTP_BAD_REQUEST
                    );
                }
            }

            if ($request->ligera) {
                $reservas = Reserva::obtenerColeccionLigera($datos);
            } else {
                if (isset($datos['ordenar_por'])) {
                    $datos['ordenar_por'] = format_order_by_attributes($datos);
                }
                $reservas = Reserva::obtenerColeccion($datos);
            }
            return response($reservas, Response::HTTP_OK);
        } catch (Exception $e) {
            return response($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $datos = $this->aplicarAlcance($request->all(), null, 'usuario_id');
            $validator = Validator::make($datos, [
                'usuario_id' => 'integer|required|exists:usuarios,id',
                'experiencia_id' => 'integer|required|exists:experiencias,id',
                'proveedor_id' => 'integer|required|exists:proveedores_turisticos,id',
                'disponibilidad_id' => 'integer|nullable|exists:experiencia_disponibilidad,id',
                'fecha' => 'date|required',
                'residencia' => 'string|nullable|max:20',
                'nombre' => 'string|required|max:150',
                'correo' => 'email|required|max:150',
                'telefono' => 'string|required|max:30',
                'cantidad_personas' => 'integer|required|min:1',
                'cantidad_chicos' => 'integer|nullable',
                'idioma' => 'string|nullable|max:50',
                'cupon_id' => 'integer|nullable|exists:cupones,id',
                'codigo_cupon' => 'string|nullable|max:40',
                'valor_total' => 'numeric|required',
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            // El proveedor de la reserva siempre es el de la experiencia (no el que envíe el cliente HTTP).
            $datos['proveedor_id'] = DB::table('experiencias')->where('id', $datos['experiencia_id'])->value('proveedor_id');
            if (!$this->enAlcance($datos['proveedor_id'], $datos['usuario_id'])) {
                return $this->respuestaSinAcceso();
            }

            $datos['estado'] = Reserva::estadoInicial($datos['experiencia_id']);

            // Cupón: el panel lo elige por id y el viajero con sesión lo escribe (código). Se valida aquí,
            // con el cupón bloqueado, para que no se pase de la cantidad disponible.
            $cupon = null;
            $criterio = !empty($datos['cupon_id']) ? ['id' => $datos['cupon_id']] : (!empty($datos['codigo_cupon']) ? ['codigo' => $datos['codigo_cupon']] : null);
            if ($criterio) {
                [$cupon, $errorCupon] = Cupon::validarParaReserva($criterio, $datos['experiencia_id'], true);
                if ($errorCupon) {
                    DB::rollback();
                    return response(get_response_body([$errorCupon]), Response::HTTP_CONFLICT);
                }
            }
            unset($datos['codigo_cupon']);
            $valores = Reserva::calcularValores($datos['experiencia_id'], $datos['cantidad_personas'], $cupon);
            $datos['cupon_id'] = $cupon->id ?? null;
            $datos['valor_descuento_cupon'] = $valores['descuento_cupon'];
            // El cliente no fija el precio (con pago en línea pagaría lo que envíe el navegador).
            if ($this->esCliente()) {
                $datos['valor_total'] = $valores['total'];
            }
            $reserva = Reserva::modificarOCrear($datos);

            if ($reserva) {
                DB::commit();
                NotificadorReservas::reservaCreada($reserva['id']);
                return response(
                    get_response_body(['La reserva ha sido creada.', 2], $reserva),
                    Response::HTTP_CREATED
                );
            } else {
                DB::rollback();
                return response(get_response_body(['Ocurrió un error al intentar crear la reserva.']), Response::HTTP_CONFLICT);
            }
        } catch (Exception $e) {
            DB::rollback();
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function show($id)
    {
        try {
            $datos['id'] = $id;
            $validator = Validator::make($datos, [
                'id' => 'integer|required|exists:reservas,id'
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if (!$this->reservaEnAlcance($id)) {
                return $this->respuestaSinAcceso();
            }

            return response(Reserva::cargar($id), Response::HTTP_OK);
        } catch (Exception $e) {
            return response(null, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $datos = $request->all();
            $datos['id'] = $id;
            $validator = Validator::make($datos, [
                'id' => 'integer|required|exists:reservas,id',
                'fecha' => 'date|required',
                'nombre' => 'string|required|max:150',
                'correo' => 'email|required|max:150',
                'telefono' => 'string|required|max:30',
                'cantidad_personas' => 'integer|required|min:1',
                'cantidad_chicos' => 'integer|nullable',
                'valor_total' => 'numeric|required',
                'observaciones' => 'string|nullable',
                'notas' => 'string|nullable',
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if (!$this->reservaEnAlcance($id)) {
                return $this->respuestaSinAcceso();
            }

            $reserva = Reserva::modificarOCrear($datos);
            if ($reserva) {
                DB::commit();
                return response(
                    get_response_body(['La reserva ha sido modificada.', 1], $reserva),
                    Response::HTTP_OK
                );
            } else {
                DB::rollback();
                return response(get_response_body(['Ocurrió un error al intentar modificar la reserva.']), Response::HTTP_CONFLICT);
            }
        } catch (Exception $e) {
            DB::rollback();
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function cambiarEstado(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $datos = $request->all();
            $datos['id'] = $id;
            $validator = Validator::make($datos, [
                'id' => 'integer|required|exists:reservas,id',
                'estado' => 'string|required|in:pendiente,pendiente_pago,aceptada,rechazada,cancelada,finalizada,no_asistio',
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if (!$this->reservaEnAlcance($id)) {
                return $this->respuestaSinAcceso();
            }
            // El cliente solo puede cancelar su reserva.
            if ($this->esCliente() && $datos['estado'] !== 'cancelada') {
                return $this->respuestaSinAcceso();
            }

            $estadoAnterior = DB::table('reservas')->where('id', $id)->value('estado');
            $reserva = Reserva::cambiarEstado($id, $datos['estado']);
            DB::commit();
            NotificadorReservas::estadoCambiado($id, $estadoAnterior);
            return response(
                get_response_body(['El estado de la reserva ha sido actualizado.', 1], $reserva),
                Response::HTTP_OK
            );
        } catch (Exception $e) {
            DB::rollback();
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // Pago recibido por fuera del portal (efectivo, transferencia, datáfono): lo registra el proveedor o el administrador.
    public function registrarPago(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $datos = $request->all();
            $datos['id'] = $id;
            $validator = Validator::make($datos, [
                'id' => 'integer|required|exists:reservas,id',
                'valor' => 'numeric|required|gt:0',
                'fecha_pago' => 'date_format:Y-m-d|required|before_or_equal:today',
                'metodo_pago' => 'string|required|in:' . implode(',', PagoService::MEDIOS_MANUALES),
                'nota' => 'string|nullable|max:255',
            ], [
                'valor.gt' => 'El valor del pago debe ser mayor que cero.',
                'fecha_pago.before_or_equal' => 'La fecha del pago no puede ser futura.',
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            // El viajero no registra sus propios pagos.
            if ($this->esCliente() || !$this->reservaEnAlcance($id)) {
                return $this->respuestaSinAcceso();
            }

            $estadoAnterior = PagoService::registrarPagoManual($id, $datos, $this->responsablePago());
            $reserva = Reserva::cargar($id);
            DB::commit();

            // Comprobante al viajero; si con el pago quedó confirmada, va el correo de reserva confirmada (con lo pagado y el saldo).
            $reserva['estado'] !== $estadoAnterior
                ? NotificadorReservas::estadoCambiado($id, $estadoAnterior)
                : NotificadorReservas::pagoRecibido($id);

            return response(
                get_response_body(['El pago ha sido registrado y se notificó al viajero.', 2], $reserva),
                Response::HTTP_CREATED
            );
        } catch (Exception $e) {
            DB::rollback();
            return response(get_response_body([$e->getMessage()]), Response::HTTP_CONFLICT);
        }
    }

    // Reembolso manual: el administrador ya devolvió el dinero y lo registra. Ruta con permiso ReembolsarReserva.
    public function registrarReembolso(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $datos = $request->all();
            $datos['id'] = $id;
            $validator = Validator::make($datos, [
                'id' => 'integer|required|exists:reservas,id',
                'valor' => 'numeric|required|gt:0',
                'fecha_pago' => 'date_format:Y-m-d|required|before_or_equal:today',
                'metodo_pago' => 'string|required|in:' . implode(',', PagoService::MEDIOS_MANUALES),
                'nota' => 'string|nullable|max:255',
            ], [
                'valor.gt' => 'El valor del reembolso debe ser mayor que cero.',
                'fecha_pago.before_or_equal' => 'La fecha del reembolso no puede ser futura.',
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if ($this->esCliente() || !$this->reservaEnAlcance($id)) {
                return $this->respuestaSinAcceso();
            }

            PagoService::registrarReembolso($id, $datos, $this->responsablePago());
            $reserva = Reserva::cargar($id);
            DB::commit();
            NotificadorReservas::reembolsoRegistrado($id);

            return response(
                get_response_body(['El reembolso ha sido registrado y se notificó al viajero.', 2], $reserva),
                Response::HTTP_CREATED
            );
        } catch (Exception $e) {
            DB::rollback();
            return response(get_response_body([$e->getMessage()]), Response::HTTP_CONFLICT);
        }
    }

    public function anularPago($id, $pagoId)
    {
        DB::beginTransaction();
        try {
            $validator = Validator::make(['id' => $id, 'pago_id' => $pagoId], [
                'id' => 'integer|required|exists:reservas,id',
                'pago_id' => 'integer|required',
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if ($this->esCliente() || !$this->reservaEnAlcance($id)) {
                return $this->respuestaSinAcceso();
            }
            // Un reembolso solo lo anula quien puede registrarlos.
            if (PagoService::esReembolso($id, $pagoId) && !auth()->user()->checkPermissionTo('ReembolsarReserva', 'api')) {
                return $this->respuestaSinAcceso();
            }

            PagoService::anularPagoManual($id, $pagoId, $this->responsablePago());
            $reserva = Reserva::cargar($id);
            DB::commit();

            return response(
                get_response_body(['El pago ha sido anulado.', 1], $reserva),
                Response::HTTP_OK
            );
        } catch (Exception $e) {
            DB::rollback();
            return response(get_response_body([$e->getMessage()]), Response::HTTP_CONFLICT);
        }
    }

    private function responsablePago()
    {
        return auth()->user()?->usuario()?->nombre ?? 'Sistema';
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $datos['id'] = $id;
            $validator = Validator::make($datos, [
                'id' => 'integer|required|exists:reservas,id'
            ]);

            if ($validator->fails()) {
                return response(
                    get_response_body(format_messages_validator($validator)),
                    Response::HTTP_BAD_REQUEST
                );
            }

            if (!$this->reservaEnAlcance($id)) {
                return $this->respuestaSinAcceso();
            }

            $eliminado = Reserva::eliminar($id);
            if ($eliminado) {
                DB::commit();
                return response(
                    get_response_body(['La reserva ha sido eliminada.', 3]),
                    Response::HTTP_OK
                );
            } else {
                DB::rollback();
                return response(get_response_body(['Ocurrió un error al intentar eliminar la reserva.']), Response::HTTP_CONFLICT);
            }
        } catch (Exception $e) {
            DB::rollback();
            return response(null, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
