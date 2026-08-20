<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

enum RolContacto: string
{
    case INTERESADO = 'INTERESADO';
    case CONTADOR = 'CONTADOR';
    case ESCRIBANO = 'ESCRIBANO';
    case ABOGADO = 'ABOGADO';
    case OTRO = 'OTRO';

    public function etiqueta(): string
    {
        return match ($this) {
            self::INTERESADO => 'Interesado/a',
            self::CONTADOR => 'Contador/a',
            self::ESCRIBANO => 'Escribano/a',
            self::ABOGADO => 'Abogado/a',
            self::OTRO => 'Otro',
        };
    }
}
