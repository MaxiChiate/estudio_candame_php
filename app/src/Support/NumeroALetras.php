<?php

declare(strict_types=1);

namespace EstudioCandame\Support;

/**
 * Los montos en instrumentos legales van en numeros y en letras (ej. "$ 1.129.800
 * (pesos un millon ciento veintinueve mil ochocientos)"), asi que el estatuto y la
 * planilla necesitan poder convertir cualquier importe en pesos a su forma escrita.
 */
final class NumeroALetras
{
    /** @var string[] */
    private const UNIDADES = [
        '', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
        'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete',
        'dieciocho', 'diecinueve', 'veinte', 'veintiuno', 'veintidós', 'veintitrés',
        'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve',
    ];

    /** @var string[] */
    private const DECENAS = [
        '', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa',
    ];

    /** @var string[] */
    private const CENTENAS = [
        '', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos',
        'seiscientos', 'setecientos', 'ochocientos', 'novecientos',
    ];

    public static function convertirEntero(int $valor): string
    {
        if ($valor === 0) {
            return 'cero';
        }
        if ($valor < 1 || $valor > 999_999_999) {
            throw new \InvalidArgumentException('Solo se admiten valores entre 1 y 999.999.999');
        }

        $millones = intdiv($valor, 1_000_000);
        $miles = intdiv($valor % 1_000_000, 1000);
        $resto = $valor % 1000;

        $partes = [];
        if ($millones > 0) {
            $partes[] = $millones === 1 ? 'un millón' : self::convertirGrupo($millones) . ' millones';
        }
        if ($miles > 0) {
            $partes[] = $miles === 1 ? 'mil' : self::convertirGrupo($miles) . ' mil';
        }
        if ($resto > 0) {
            $partes[] = self::convertirGrupo($resto);
        }

        return implode(' ', $partes);
    }

    private static function convertirGrupo(int $valor): string
    {
        if ($valor < 0 || $valor > 999) {
            throw new \InvalidArgumentException('convertirGrupo espera un valor entre 0 y 999');
        }
        if ($valor === 100) {
            return 'cien';
        }

        $centena = intdiv($valor, 100);
        $restoDecenas = $valor % 100;

        $partes = [];
        if ($centena > 0) {
            $partes[] = self::CENTENAS[$centena];
        }
        if ($restoDecenas >= 1 && $restoDecenas <= 29) {
            // 0-29 (incluye "veintiuno".."veintinueve" como una sola palabra, la forma
            // estandar en español; solo de 31 en adelante se usa "treinta y uno").
            $partes[] = self::UNIDADES[$restoDecenas];
        } elseif ($restoDecenas > 29) {
            $decena = intdiv($restoDecenas, 10);
            $unidad = $restoDecenas % 10;
            $partes[] = $unidad === 0 ? self::DECENAS[$decena] : self::DECENAS[$decena] . ' y ' . self::UNIDADES[$unidad];
        }

        return implode(' ', $partes);
    }

    /**
     * "$ 1.129.800 (pesos un millón ciento veintinueve mil ochocientos)", agregando
     * "con NN/100" solo cuando el monto tiene centavos.
     */
    public static function formatMonedaConLetras(float $valor): string
    {
        $redondeado = round($valor, 2, PHP_ROUND_HALF_UP);
        $parteEntera = (int) floor($redondeado);
        $centavos = (int) round(($redondeado - $parteEntera) * 100);

        $numeroFormateado = Ars::formatEntero($parteEntera);
        $letras = self::convertirEntero($parteEntera);
        $centavosTexto = $centavos > 0 ? " con $centavos/100" : '';

        return "\$ $numeroFormateado (pesos $letras$centavosTexto)";
    }
}
