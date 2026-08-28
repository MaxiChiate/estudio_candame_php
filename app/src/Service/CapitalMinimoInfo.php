<?php

declare(strict_types=1);

namespace EstudioCandame\Service;

use EstudioCandame\Model\TipoSocietario;
use EstudioCandame\Support\Ars;

final class CapitalMinimoInfo
{
    public function __construct(
        public readonly TipoSocietario $tipo,
        public readonly ?float $piso,
        public readonly bool $bloqueante,
        public readonly string $detalle,
        public readonly string $fechaReferencia,
    ) {
    }

    /** Aviso no bloqueante para mostrar si el capital cargado esta por debajo del piso. */
    public function aviso(float $capitalSocial): ?string
    {
        if ($this->bloqueante || $this->piso === null || $capitalSocial >= $this->piso) {
            return null;
        }

        return sprintf('El capital social está por debajo del mínimo sugerido (%s). %s', Ars::format($this->piso), $this->detalle);
    }

    /** @return array{tipo: string, piso: ?float, bloqueante: bool, detalle: string, fechaReferencia: string} */
    public function toArray(): array
    {
        return [
            'tipo' => $this->tipo->value,
            'piso' => $this->piso,
            'bloqueante' => $this->bloqueante,
            'detalle' => $this->detalle,
            'fechaReferencia' => $this->fechaReferencia,
        ];
    }
}
