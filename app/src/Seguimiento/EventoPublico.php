<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

use DateTimeImmutable;

/**
 * Un evento tal como lo ve el CLIENTE.
 *
 * Deliberadamente no tiene campo notaInterna: la garantia de que la nota interna nunca
 * llega a la vista publica es estructural, no una omision del template. El repositorio
 * que alimenta el portal ni siquiera la trae en el SELECT, y aunque la trajera no
 * habria donde ponerla. Ver TramiteEvento para la version del panel.
 */
final class EventoPublico
{
    public function __construct(
        public readonly Etapa $etapa,
        public readonly DateTimeImmutable $ocurridoEl,
        public readonly ?string $notaPublica,
    ) {
    }
}
