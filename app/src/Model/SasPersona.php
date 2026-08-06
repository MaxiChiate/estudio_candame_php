<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

use DateTimeImmutable;
use EstudioCandame\Validation\DominioEmailValidator;

final class SasPersona
{
    public function __construct(
        public string $nombreCompleto = '',
        public string $tratamiento = '',
        public string $nacionalidad = 'Argentina',
        public ?DateTimeImmutable $fechaNacimiento = null,
        public string $tipoDocumento = 'DNI',
        public string $numeroDocumento = '',
        public string $cuitCuil = '',
        public string $estadoCivil = '',
        public string $conyuge = '',
        public string $profesion = '',
        public SasDomicilioPersona $domicilio = new SasDomicilioPersona(),
        public string $email = '',
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $fecha = trim((string) ($data['fechaNacimiento'] ?? ''));

        return new self(
            nombreCompleto: trim((string) ($data['nombreCompleto'] ?? '')),
            tratamiento: trim((string) ($data['tratamiento'] ?? '')),
            nacionalidad: trim((string) ($data['nacionalidad'] ?? '')) ?: 'Argentina',
            fechaNacimiento: $fecha !== '' ? (DateTimeImmutable::createFromFormat('Y-m-d', $fecha) ?: null) : null,
            tipoDocumento: trim((string) ($data['tipoDocumento'] ?? '')) ?: 'DNI',
            numeroDocumento: trim((string) ($data['numeroDocumento'] ?? '')),
            cuitCuil: trim((string) ($data['cuitCuil'] ?? '')),
            estadoCivil: trim((string) ($data['estadoCivil'] ?? '')),
            conyuge: trim((string) ($data['conyuge'] ?? '')),
            profesion: trim((string) ($data['profesion'] ?? '')),
            domicilio: SasDomicilioPersona::fromArray((array) ($data['domicilio'] ?? [])),
            email: trim((string) ($data['email'] ?? '')),
        );
    }

    /** @return array<string, string> path => mensaje */
    public function validate(string $prefix): array
    {
        $errors = [];

        if ($this->nombreCompleto === '') {
            $errors["$prefix.nombreCompleto"] = 'Ingrese apellido y nombre';
        }
        if ($this->tratamiento === '') {
            $errors["$prefix.tratamiento"] = 'Seleccione el tratamiento (Señor/Señora)';
        }
        if ($this->nacionalidad === '') {
            $errors["$prefix.nacionalidad"] = 'Ingrese la nacionalidad';
        }
        if ($this->tipoDocumento === '') {
            $errors["$prefix.tipoDocumento"] = 'Seleccione el tipo de documento';
        }
        if ($this->numeroDocumento === '') {
            $errors["$prefix.numeroDocumento"] = 'Ingrese el numero de documento';
        }
        if ($this->cuitCuil === '') {
            $errors["$prefix.cuitCuil"] = 'Ingrese el CUIT/CUIL';
        }
        if ($this->estadoCivil === '') {
            $errors["$prefix.estadoCivil"] = 'Ingrese el estado civil';
        }

        // Bug conocido de la version Kotlin: el email de cada socio/administrador
        // era opcional. Aca es obligatorio.
        if ($this->email === '') {
            $errors["$prefix.email"] = 'Ingrese el email';
        } elseif (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            $errors["$prefix.email"] = 'Ingrese un email valido';
        } elseif (!DominioEmailValidator::esValido($this->email)) {
            $errors["$prefix.email"] = 'El dominio del email no existe o no puede recibir correo';
        }

        return array_merge($errors, $this->domicilio->validate("$prefix.domicilio"));
    }
}
