<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

enum TipoDocumento: string
{
    case DNI = 'DNI';
    case PASAPORTE = 'PASAPORTE';

    public function etiqueta(): string
    {
        return match ($this) {
            self::DNI => 'DNI',
            self::PASAPORTE => 'Pasaporte',
        };
    }
}
