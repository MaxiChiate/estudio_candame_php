<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

final class ValidationError
{
    public function __construct(
        public readonly string $campo,
        public readonly string $codigo,
        public readonly string $mensaje,
    ) {
    }

    /** @return array{campo: string, codigo: string, mensaje: string} */
    public function toArray(): array
    {
        return [
            'campo' => $this->campo,
            'codigo' => $this->codigo,
            'mensaje' => $this->mensaje,
        ];
    }
}
