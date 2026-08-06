<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

final class SasAccionista
{
    public function __construct(
        public SasPersona $persona = new SasPersona(),
        public string $porcentajeParticipacion = '',
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            persona: SasPersona::fromArray((array) ($data['persona'] ?? [])),
            porcentajeParticipacion: trim((string) ($data['porcentajeParticipacion'] ?? '')),
        );
    }

    /** @return array<string, string> path => mensaje */
    public function validate(string $prefix): array
    {
        $errors = $this->persona->validate("$prefix.persona");

        if ($this->porcentajeParticipacion === '') {
            $errors["$prefix.porcentajeParticipacion"] = 'Ingrese el porcentaje de participacion';
        } elseif (preg_match('/^\d{1,3}([.,]\d{1,2})?$/', $this->porcentajeParticipacion) !== 1) {
            $errors["$prefix.porcentajeParticipacion"] = 'Ingrese un porcentaje valido, ej: 50 o 33,33';
        }

        return $errors;
    }
}
