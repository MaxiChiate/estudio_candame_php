<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

use DateTimeImmutable;

final class Administrador
{
    public function __construct(
        public Persona $persona = new Persona(),
        public ?CargoAdministrador $cargo = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            persona: Persona::fromArray($data),
            cargo: CargoAdministrador::tryFrom((string) ($data['cargo'] ?? '')),
        );
    }

    /** @return ValidationError[] */
    public function validate(string $prefix, DateTimeImmutable $ahora): array
    {
        $errors = $this->persona->validate($prefix, $ahora);

        if ($this->cargo === null) {
            $errors[] = new ValidationError("$prefix.cargo", 'CAMPO_REQUERIDO', 'Seleccione el cargo');
        }

        return $errors;
    }
}
