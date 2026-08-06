<?php

declare(strict_types=1);

namespace EstudioCandame\Service;

final class CapitalSasInfo
{
    public function __construct(
        public readonly float $smvm,
        public readonly int $multiplo,
        public readonly float $capital,
        public readonly string $fechaSmvm,
        public readonly bool $fuenteEnVivo,
    ) {
    }

    /** @return array{smvm: float, multiplo: int, capital: float, fechaSmvm: string, fuenteEnVivo: bool} */
    public function toArray(): array
    {
        return [
            'smvm' => $this->smvm,
            'multiplo' => $this->multiplo,
            'capital' => $this->capital,
            'fechaSmvm' => $this->fechaSmvm,
            'fuenteEnVivo' => $this->fuenteEnVivo,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            smvm: (float) $data['smvm'],
            multiplo: (int) $data['multiplo'],
            capital: (float) $data['capital'],
            fechaSmvm: (string) $data['fechaSmvm'],
            fuenteEnVivo: (bool) $data['fuenteEnVivo'],
        );
    }
}
