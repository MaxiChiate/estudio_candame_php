<?php

declare(strict_types=1);

namespace EstudioCandame\Validation;

use EstudioCandame\Model\TipoSocietario;

/**
 * El chequeo de que una denominacion no termine en el tipo societario tiene que ser
 * por token completo, no por sufijo de letras: "Rosas" no se rechaza por terminar en
 * "sas". Se tokeniza por cualquier separador no alfanumerico y se compara solo la
 * ultima palabra.
 */
final class DenominacionValidator
{
    public static function terminaEnTipoSocietario(string $nombre, TipoSocietario $tipo): bool
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtoupper($nombre, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === [] || $tokens === false) {
            return false;
        }

        $ultimoToken = end($tokens);

        return in_array($ultimoToken, $tipo->tokensDenominacion(), true);
    }
}
