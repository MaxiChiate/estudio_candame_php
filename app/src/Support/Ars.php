<?php

declare(strict_types=1);

namespace EstudioCandame\Support;

use NumberFormatter;

/**
 * Unico punto de formato de moneda en pesos argentinos. Todo importe que se
 * escriba en la planilla, el estatuto o cualquier otro documento generado
 * tiene que pasar por aca (bug conocido en la version Kotlin: mezclar celdas
 * de texto ya formateado con celdas numericas crudas hacia que algunos
 * totales salieran sin separador de miles).
 */
final class Ars
{
    private static function formatter(int $decimales): NumberFormatter
    {
        $formatter = new NumberFormatter('es_AR', NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimales);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimales);
        $formatter->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_HALFUP);

        return $formatter;
    }

    /** "1.129.800,00" */
    public static function format(float|string $valor, int $decimales = 2): string
    {
        return self::formatter($decimales)->format((float) $valor);
    }

    /** "1.129.800" (sin centavos, para textos legales que van seguidos de "(pesos ...)") */
    public static function formatEntero(float|string $valor): string
    {
        return self::format($valor, 0);
    }
}
