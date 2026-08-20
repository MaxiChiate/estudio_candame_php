<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

final class Contacto
{
    public function __construct(
        public string $nombre = '',
        public string $email = '',
        public string $telefono = '',
        public ?RolContacto $rol = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            nombre: trim((string) ($data['nombre'] ?? '')),
            email: trim((string) ($data['email'] ?? '')),
            telefono: trim((string) ($data['telefono'] ?? '')),
            rol: RolContacto::tryFrom((string) ($data['rol'] ?? '')),
        );
    }

    /** @return ValidationError[] */
    public function validate(): array
    {
        $errors = [];

        if ($this->nombre === '') {
            $errors[] = new ValidationError('contacto.nombre', 'CAMPO_REQUERIDO', 'Ingrese su nombre');
        }

        if ($this->email === '') {
            $errors[] = new ValidationError('contacto.email', 'CAMPO_REQUERIDO', 'Ingrese su email');
        } elseif (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = new ValidationError('contacto.email', 'EMAIL_INVALIDO', 'Ingrese un email valido');
        }

        if ($this->telefono === '') {
            $errors[] = new ValidationError('contacto.telefono', 'CAMPO_REQUERIDO', 'Ingrese su telefono');
        }

        if ($this->rol === null) {
            $errors[] = new ValidationError('contacto.rol', 'CAMPO_REQUERIDO', 'Seleccione su rol');
        }

        return $errors;
    }
}
