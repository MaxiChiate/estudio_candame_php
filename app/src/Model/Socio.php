<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

use DateTimeImmutable;

final class Socio
{
    public function __construct(
        public Persona $persona = new Persona(),
        public string $porcentajeParticipacion = '',
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            persona: Persona::fromArray($data),
            porcentajeParticipacion: trim((string) ($data['porcentajeParticipacion'] ?? '')),
        );
    }

    /** @return ValidationError[] */
    public function validate(string $prefix, DateTimeImmutable $ahora): array
    {
        $errors = $this->persona->validate($prefix, $ahora);

        if ($this->porcentajeParticipacion === '') {
            $errors[] = new ValidationError("$prefix.porcentajeParticipacion", 'CAMPO_REQUERIDO', 'Ingrese el porcentaje de participación');
        } elseif (!is_numeric(str_replace(',', '.', $this->porcentajeParticipacion))) {
            $errors[] = new ValidationError("$prefix.porcentajeParticipacion", 'PORCENTAJE_INVALIDO', 'Ingrese un porcentaje válido');
        }

        return $errors;
    }

    public function porcentajeFloat(): float
    {
        return (float) str_replace(',', '.', $this->porcentajeParticipacion);
    }
}
