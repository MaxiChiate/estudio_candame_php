<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

/**
 * Un solo VO para la sede de la sociedad y el domicilio real de una persona: los
 * campos son los mismos, lo unico que cambia es cual de ellos es obligatorio (la sede
 * no pide localidad/provincia porque siempre es CABA). El caller decide eso pasando
 * la lista de campos requeridos a validate().
 */
final class Domicilio
{
    public function __construct(
        public string $calle = '',
        public string $numero = '',
        public string $piso = '',
        public string $depto = '',
        public string $localidad = '',
        public string $provincia = '',
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            calle: trim((string) ($data['calle'] ?? '')),
            numero: trim((string) ($data['numero'] ?? '')),
            piso: trim((string) ($data['piso'] ?? '')),
            depto: trim((string) ($data['depto'] ?? '')),
            localidad: trim((string) ($data['localidad'] ?? '')),
            provincia: trim((string) ($data['provincia'] ?? '')),
        );
    }

    /**
     * @param string[] $camposRequeridos subconjunto de calle/numero/localidad/provincia
     * @return ValidationError[]
     */
    public function validate(string $prefix, array $camposRequeridos): array
    {
        $etiquetas = [
            'calle' => 'la calle',
            'numero' => 'el numero',
            'localidad' => 'la localidad',
            'provincia' => 'la provincia',
        ];

        $errors = [];
        foreach ($camposRequeridos as $campo) {
            if ($this->$campo === '') {
                $errors[] = new ValidationError("$prefix.$campo", 'CAMPO_REQUERIDO', 'Ingrese ' . $etiquetas[$campo]);
            }
        }

        return $errors;
    }

    public function direccionCompleta(): string
    {
        $partes = [trim($this->calle . ' ' . $this->numero)];
        if ($this->piso !== '') {
            $partes[] = 'piso ' . $this->piso;
        }
        if ($this->depto !== '') {
            $partes[] = 'depto ' . $this->depto;
        }
        if ($this->localidad !== '') {
            $partes[] = $this->localidad;
        }
        if ($this->provincia !== '') {
            $partes[] = $this->provincia;
        }

        return implode(', ', array_filter($partes, static fn (string $parte): bool => $parte !== ''));
    }
}
