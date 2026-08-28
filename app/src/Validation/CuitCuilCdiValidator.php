<?php

declare(strict_types=1);

namespace EstudioCandame\Validation;

/**
 * Valida el numero de CUIT/CUIL/CDI: formato primero (11 digitos numericos, antes que
 * cualquier otra regla fiscal), despues el digito verificador por modulo 11, y por
 * ultimo que no sea un prefijo de persona juridica (30/33/34) -- el formulario solo
 * pide datos de personas humanas.
 */
final class CuitCuilCdiValidator
{
    private const PESOS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    private const PREFIJOS_PERSONA_JURIDICA = ['30', '33', '34'];

    public static function validar(string $numero): ?string
    {
        if (!preg_match('/^\d{11}$/', $numero)) {
            return 'FORMATO_INVALIDO';
        }

        if (!self::digitoVerificadorValido($numero)) {
            return 'DIGITO_VERIFICADOR_INVALIDO';
        }

        if (in_array(substr($numero, 0, 2), self::PREFIJOS_PERSONA_JURIDICA, true)) {
            return 'PERSONA_JURIDICA_NO_PERMITIDA';
        }

        return null;
    }

    private static function digitoVerificadorValido(string $numero): bool
    {
        $digitos = array_map('intval', str_split($numero));

        $suma = 0;
        foreach (self::PESOS as $indice => $peso) {
            $suma += $digitos[$indice] * $peso;
        }

        $verificador = 11 - ($suma % 11);
        if ($verificador === 11) {
            $verificador = 0;
        } elseif ($verificador === 10) {
            // El algoritmo no produce un digito verificador valido para este caso.
            return false;
        }

        return $verificador === $digitos[10];
    }
}
