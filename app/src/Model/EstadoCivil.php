<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

enum EstadoCivil: string
{
    case SOLTERO = 'SOLTERO';
    case CASADO = 'CASADO';
    case DIVORCIADO = 'DIVORCIADO';
    case VIUDO = 'VIUDO';

    public function etiqueta(): string
    {
        return match ($this) {
            self::SOLTERO => 'Soltero/a',
            self::CASADO => 'Casado/a',
            self::DIVORCIADO => 'Divorciado/a',
            self::VIUDO => 'Viudo/a',
        };
    }
}
