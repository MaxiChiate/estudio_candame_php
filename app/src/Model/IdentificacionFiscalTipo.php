<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

enum IdentificacionFiscalTipo: string
{
    case CUIT = 'CUIT';
    case CUIL = 'CUIL';
    case CDI = 'CDI';
}
