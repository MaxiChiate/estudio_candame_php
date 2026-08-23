<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

use DateTimeImmutable;

final class Persona
{
    public function __construct(
        public string $apellidoYNombre = '',
        public string $nacionalidad = '',
        public ?DateTimeImmutable $fechaNacimiento = null,
        public ?TipoDocumento $tipoDocumento = null,
        public string $numeroDocumento = '',
        public IdentificacionFiscal $identificacionFiscal = new IdentificacionFiscal(),
        public ?EstadoCivil $estadoCivil = null,
        public string $conyuge = '',
        public string $profesion = '',
        public Domicilio $domicilioReal = new Domicilio(),
        public string $email = '',
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $fecha = trim((string) ($data['fechaNacimiento'] ?? ''));

        return new self(
            apellidoYNombre: trim((string) ($data['apellidoYNombre'] ?? '')),
            nacionalidad: trim((string) ($data['nacionalidad'] ?? '')),
            fechaNacimiento: $fecha !== '' ? (DateTimeImmutable::createFromFormat('Y-m-d', $fecha) ?: null) : null,
            tipoDocumento: TipoDocumento::tryFrom((string) ($data['tipoDocumento'] ?? '')),
            numeroDocumento: trim((string) ($data['numeroDocumento'] ?? '')),
            identificacionFiscal: new IdentificacionFiscal(
                tipo: IdentificacionFiscalTipo::tryFrom((string) ($data['identificacionFiscalTipo'] ?? '')),
                numero: trim((string) ($data['identificacionFiscalNumero'] ?? '')),
            ),
            estadoCivil: EstadoCivil::tryFrom((string) ($data['estadoCivil'] ?? '')),
            conyuge: trim((string) ($data['conyuge'] ?? '')),
            profesion: trim((string) ($data['profesion'] ?? '')),
            domicilioReal: Domicilio::fromArray((array) ($data['domicilioReal'] ?? [])),
            email: trim((string) ($data['email'] ?? '')),
        );
    }

    /** @return ValidationError[] */
    public function validate(string $prefix, DateTimeImmutable $ahora): array
    {
        $errors = [];

        if ($this->apellidoYNombre === '') {
            $errors[] = new ValidationError("$prefix.apellidoYNombre", 'CAMPO_REQUERIDO', 'Ingrese apellido y nombre');
        }

        if ($this->nacionalidad === '') {
            $errors[] = new ValidationError("$prefix.nacionalidad", 'CAMPO_REQUERIDO', 'Ingrese la nacionalidad');
        }

        if ($this->fechaNacimiento === null) {
            $errors[] = new ValidationError("$prefix.fechaNacimiento", 'CAMPO_REQUERIDO', 'Ingrese la fecha de nacimiento');
        } elseif ($this->fechaNacimiento > $ahora) {
            $errors[] = new ValidationError("$prefix.fechaNacimiento", 'FECHA_FUTURA', 'La fecha de nacimiento no puede ser futura');
        } elseif ($this->fechaNacimiento->modify('+18 years') > $ahora) {
            $errors[] = new ValidationError("$prefix.fechaNacimiento", 'MENOR_DE_EDAD', 'Debe ser mayor de 18 años');
        }

        if ($this->tipoDocumento === null) {
            $errors[] = new ValidationError("$prefix.tipoDocumento", 'CAMPO_REQUERIDO', 'Seleccione el tipo de documento');
        }

        if ($this->numeroDocumento === '') {
            $errors[] = new ValidationError("$prefix.numeroDocumento", 'CAMPO_REQUERIDO', 'Ingrese el número de documento');
        }

        $errors = [...$errors, ...$this->identificacionFiscal->validate($prefix)];

        if ($this->estadoCivil === null) {
            $errors[] = new ValidationError("$prefix.estadoCivil", 'CAMPO_REQUERIDO', 'Seleccione el estado civil');
        } elseif ($this->estadoCivil === EstadoCivil::CASADO && $this->conyuge === '') {
            $errors[] = new ValidationError("$prefix.conyuge", 'CAMPO_REQUERIDO', 'Ingrese el nombre del cónyuge');
        }

        if ($this->profesion === '') {
            $errors[] = new ValidationError("$prefix.profesion", 'CAMPO_REQUERIDO', 'Ingrese la profesión');
        }

        $errors = [...$errors, ...$this->domicilioReal->validate("$prefix.domicilioReal", ['calle', 'numero', 'localidad', 'provincia'])];

        if ($this->email === '') {
            $errors[] = new ValidationError("$prefix.email", 'CAMPO_REQUERIDO', 'Ingrese el email');
        } elseif (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = new ValidationError("$prefix.email", 'EMAIL_INVALIDO', 'Ingrese un email valido');
        }

        return $errors;
    }
}
