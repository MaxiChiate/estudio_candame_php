<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

use DateTimeImmutable;

/**
 * Un evento completo, con la nota interna. SOLO para el panel de la doctora.
 * Nunca pasar esta clase a un template publico -- para eso esta EventoPublico.
 */
final class TramiteEvento
{
    public function __construct(
        public readonly int $id,
        public readonly int $tramiteId,
        public readonly Etapa $etapa,
        public readonly DateTimeImmutable $ocurridoEl,
        public readonly ?string $notaPublica,
        public readonly ?string $notaInterna,
    ) {
    }
}
