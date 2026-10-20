<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

use DateTimeImmutable;

/**
 * Una novedad tal como la ve el CLIENTE: fecha y texto, nada mas.
 *
 * Sin etapa a proposito: al cliente no se le dice "durante tal etapa" porque en la
 * lista de novedades eso se lee como un cambio de etapa, que es justo lo que una
 * novedad no es.
 */
final class NovedadPublica
{
    public function __construct(
        public readonly DateTimeImmutable $publicadoEl,
        public readonly string $texto,
    ) {
    }
}
