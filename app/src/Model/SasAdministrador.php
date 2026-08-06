<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

final class SasAdministrador
{
    public function __construct(
        public SasPersona $persona = new SasPersona(),
        public string $cargo = 'Titular',
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            persona: SasPersona::fromArray((array) ($data['persona'] ?? [])),
            cargo: trim((string) ($data['cargo'] ?? '')) ?: 'Titular',
        );
    }

    /** @return array<string, string> path => mensaje */
    public function validate(string $prefix): array
    {
        return $this->persona->validate("$prefix.persona");
    }
}
