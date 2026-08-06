<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

final class SasDomicilioPersona
{
    public function __construct(
        public string $calle = '',
        public string $altura = '',
        public string $piso = '',
        public string $departamento = '',
        public string $cuerpo = '',
        public string $localidad = '',
        public string $provincia = '',
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
            localidad: trim((string) ($data['localidad'] ?? '')),
            provincia: trim((string) ($data['provincia'] ?? '')),
        );
    }

    /** @return array<string, string> path => mensaje */
    public function validate(string $prefix): array
    {
        $errors = [];
        if ($this->calle === '') {
            $errors["$prefix.calle"] = 'Ingrese la calle del domicilio real';
        }
        if ($this->altura === '') {
            $errors["$prefix.altura"] = 'Ingrese la altura del domicilio real';
        }
        if ($this->localidad === '') {
            $errors["$prefix.localidad"] = 'Ingrese la localidad del domicilio real';
        }
        if ($this->provincia === '') {
            $errors["$prefix.provincia"] = 'Seleccione la provincia del domicilio real';
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
        $partes[] = $this->localidad;
        $partes[] = $this->provincia;

        return implode(', ', $partes);
    }
}
