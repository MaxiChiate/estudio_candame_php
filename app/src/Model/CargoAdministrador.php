<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

enum CargoAdministrador: string
{
    case TITULAR = 'TITULAR';
    case SUPLENTE = 'SUPLENTE';

    public function etiqueta(TipoSocietario $tipo): string
    {
        return match ($this) {
            self::TITULAR => $tipo->etiquetaTitular(),
            self::SUPLENTE => $tipo->etiquetaSuplente(),
        };
    }
}
