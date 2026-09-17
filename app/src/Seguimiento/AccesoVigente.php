<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

/**
 * Resultado de resolver un token: que acceso lo emitio y sobre que tramite. Se separa
 * del Tramite porque el controller necesita el id del acceso para registrar la visita
 * (contador + ultimo_acceso_el) antes de renderizar.
 */
final class AccesoVigente
{
    public function __construct(
        public readonly int $accesoId,
        public readonly int $tramiteId,
    ) {
    }
}
