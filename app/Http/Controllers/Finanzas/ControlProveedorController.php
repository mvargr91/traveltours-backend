<?php

namespace App\Http\Controllers\Finanzas;

use Exception;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use App\Services\ControlProveedores;

/**
 * Finanzas > Control de proveedores (solo administración): una fila por proveedor con lo que hay que vigilar
 * (reservas sin finalizar, cancelaciones, deuda, mora, pasarela y modelo de cobro) y sus alertas.
 */
class ControlProveedorController extends Controller
{
    public function index()
    {
        try {
            return response(ControlProveedores::datos(), Response::HTTP_OK);
        } catch (Exception $e) {
            return response(get_response_body([$e->getMessage()]), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
