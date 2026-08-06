<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

/**
 * La sede social de una SAS constituida por Estudio Candame siempre queda en CABA
 * (unica jurisdiccion en la que opera el estudio), por eso no lleva localidad/provincia:
 * ver .claude/rules/configurador.md del proyecto original.
 */
final class SasDomicilioSociedad
{
    public function __construct(
        public string $calle = '',
        public string $altura = '',
        public string $piso = '',
        public string $departamento = '',
        public string $cuerpo = '',
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            calle: trim((string) ($data['calle'] ?? '')),
            altura: trim((string) ($data['altura'] ?? '')),
            piso: trim((string) ($data['piso'] ?? '')),
            departamento: trim((string) ($data['departamento'] ?? '')),
            cuerpo: trim((string) ($data['cuerpo'] ?? '')),
        );
    }

    /** @return array<string, string> path => mensaje */
    public function validate(string $prefix): array
    {
        $errors = [];
        if ($this->calle === '') {
            $errors["$prefix.calle"] = 'Ingrese la calle de la sede social';
        }
        if ($this->altura === '') {
            $errors["$prefix.altura"] = 'Ingrese la altura de la sede social';
        }

        return $errors;
    }

    public function direccionCompleta(): string
    {
        $partes = [trim("$this->calle $this->altura")];
        if ($this->piso !== '') {
            $partes[] = "Piso $this->piso";
        }
        if ($this->departamento !== '') {
            $partes[] = "Depto. $this->departamento";
        }
        if ($this->cuerpo !== '') {
            $partes[] = "Cuerpo $this->cuerpo";
        }

        return implode(', ', $partes) . ', Ciudad Autónoma de Buenos Aires';
    }
}
