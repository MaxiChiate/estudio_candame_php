<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

use DateTimeImmutable;

/**
 * Un mensaje al cliente publicado SIN cambiar de etapa. Version del panel, con id para
 * editarla o borrarla desde el historial. Para la vista publica esta NovedadPublica.
 *
 * $etapa es la vigente al publicarla: una referencia para la doctora, no una transicion.
 * El tramite no paso por ningun lado al publicarla.
 */
final class Novedad
{
    public function __construct(
        public readonly int $id,
        public readonly int $tramiteId,
        public readonly Etapa $etapa,
        public readonly DateTimeImmutable $publicadoEl,
        public readonly string $texto,
    ) {
    }
}
