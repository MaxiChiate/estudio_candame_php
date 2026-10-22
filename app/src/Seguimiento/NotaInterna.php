<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

use DateTimeImmutable;

/**
 * Nota de la doctora sobre el tramite, sin cambio de etapa. SOLO para el panel.
 *
 * Vive en su propia tabla (tramite_nota_interna) y no hay ninguna consulta del portal
 * publico que la lea: no existe un "NotaInternaPublica" ni hace falta.
 */
final class NotaInterna
{
    public function __construct(
        public readonly int $id,
        public readonly int $tramiteId,
        public readonly Etapa $etapa,
        public readonly DateTimeImmutable $escritaEl,
        public readonly string $texto,
    ) {
    }
}
