<?php

namespace App\Services;

use App\Models\Parametrizacion\ParametroConstante;

/**
 * Monedas en que una experiencia publica sus precios (experiencias.moneda).
 *
 * Todos los precios de una experiencia van en su moneda y cada reserva guarda la moneda con la que se creó
 * (reservas.moneda), para que cambiarla después no altere las reservas existentes.
 *
 * Las pasarelas cobran solo en pesos: una experiencia en dólares se ofrece siempre como reserva
 * (ver CuentaPasarela::sqlModalidadEfectiva). Las comisiones y liquidaciones son en pesos: lo vendido en dólares
 * se convierte con la TRM del parámetro TRM_USD (ver CobroProveedores::sincronizarComision), igual que el filtro
 * y el orden por precio del portal.
 */
class Monedas
{
    public const COP = 'COP';
    public const USD = 'USD';
    public const MONEDAS = [self::COP, self::USD];

    // codigo => [valor por defecto, descripción] (Parametrización > Parámetros constantes).
    public const PARAMETROS = [
        'TRM_USD' => ['4000', 'Pesos colombianos por 1 dólar (TRM). Convierte a pesos las comisiones de las experiencias en dólares y su precio en los filtros del portal.'],
    ];

    /** Pesos por dólar; si el parámetro no es un número válido se usa el valor por defecto. */
    public static function trm(): float
    {
        $valor = ParametroConstante::cargarParametros()['TRM_USD'] ?? null;
        $valor = is_numeric($valor) ? (float) $valor : 0;
        return $valor > 0 ? $valor : (float) self::PARAMETROS['TRM_USD'][0];
    }

    /** Valor en pesos de un monto en la moneda indicada. */
    public static function aPesos($valor, ?string $moneda): float
    {
        return $moneda === self::USD ? round((float) $valor * self::trm(), 2) : (float) $valor;
    }

    /** "$ 150.000" en pesos, "US$ 45" (o "US$ 45,50") en dólares. */
    public static function formato($valor, ?string $moneda = self::COP): string
    {
        $valor = (float) $valor;
        if ($moneda === self::USD) {
            $decimales = floor($valor) == $valor ? 0 : 2;
            return 'US$ ' . number_format($valor, $decimales, ',', '.');
        }
        return '$ ' . number_format($valor, 0, ',', '.');
    }

    /**
     * Factor SQL para pasar a pesos un valor según la columna de moneda indicada (1 en pesos, la TRM en dólares).
     * Lo usan los tableros y reportes, que suman reservas y pagos de experiencias en distintas monedas.
     */
    public static function sqlFactorPesos(string $columnaMoneda): string
    {
        $trm = self::trm();
        return "(CASE WHEN {$columnaMoneda} = '" . self::USD . "' THEN {$trm} ELSE 1 END)";
    }

    /** Expresión SQL con el precio_desde de la experiencia en pesos (filtros y orden del portal). */
    public static function sqlPrecioDesdeEnPesos(): string
    {
        $trm = self::trm();
        return "(experiencias.precio_desde * CASE WHEN experiencias.moneda = '" . self::USD . "' THEN {$trm} ELSE 1 END)";
    }
}
