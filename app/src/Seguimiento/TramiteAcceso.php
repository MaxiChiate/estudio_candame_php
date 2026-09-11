<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

use DateTimeImmutable;

/**
 * Un link de acceso emitido para un tramite. Solo queda el sha256 del token: el valor
 * en claro se muestra una unica vez, al emitirlo, y despues no se puede recuperar.
 */
final class TramiteAcceso
{
    public function __construct(
        public readonly int $id,
        public readonly int $tramiteId,
        public readonly string $etiqueta,
        public readonly DateTimeImmutable $creadoEl,
        public readonly ?DateTimeImmutable $revocadoEl,
        public readonly ?DateTimeImmutable $ultimoAccesoEl,
        public readonly int $accesos,
    ) {
    }

    public function estaRevocado(): bool
    {
        return $this->revocadoEl !== null;
    }
}
