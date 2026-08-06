<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

use EstudioCandame\Validation\DominioEmailValidator;

final class ConstitucionSasForm
{
    /**
     * @param SasAccionista[] $accionistas
     * @param SasAdministrador[] $administradores
     */
    public function __construct(
        public array $accionistas = [],
        public string $nombreOpcion1 = '',
        public string $nombreOpcion2 = '',
        public string $nombreOpcion3 = '',
        // Uno de los nombres de ObjetoSocialCategoria, o "ESPECIFICO" para redactarlo a mano.
        public string $tipoObjeto = '',
        public string $objetoSocial = '',
        public string $cierreEjercicioMes = '',
        public SasDomicilioSociedad $domicilio = new SasDomicilioSociedad(),
        public int $duracionSociedad = 99,
        public string $emailSociedad = '',
        public string $telefonoSociedad = '',
        public array $administradores = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $accionistas = array_map(
            static fn (mixed $item): SasAccionista => SasAccionista::fromArray((array) $item),
            (array) ($data['accionistas'] ?? []),
        );
        $administradores = array_map(
            static fn (mixed $item): SasAdministrador => SasAdministrador::fromArray((array) $item),
            (array) ($data['administradores'] ?? []),
        );

        return new self(
            accionistas: array_values($accionistas),
            nombreOpcion1: trim((string) ($data['nombreOpcion1'] ?? '')),
            nombreOpcion2: trim((string) ($data['nombreOpcion2'] ?? '')),
            nombreOpcion3: trim((string) ($data['nombreOpcion3'] ?? '')),
            tipoObjeto: trim((string) ($data['tipoObjeto'] ?? '')),
            objetoSocial: trim((string) ($data['objetoSocial'] ?? '')),
            cierreEjercicioMes: trim((string) ($data['cierreEjercicioMes'] ?? '')),
            domicilio: SasDomicilioSociedad::fromArray((array) ($data['domicilio'] ?? [])),
            duracionSociedad: (int) ($data['duracionSociedad'] ?? 0),
            emailSociedad: trim((string) ($data['emailSociedad'] ?? '')),
            telefonoSociedad: trim((string) ($data['telefonoSociedad'] ?? '')),
            administradores: array_values($administradores),
        );
    }

    /** @return array<string, string> path => mensaje, vacio si el formulario es valido */
    public function validate(): array
    {
        $errors = [];

        if ($this->accionistas === []) {
            $errors['accionistas'] = 'Ingrese al menos un accionista';
        }
        foreach ($this->accionistas as $index => $accionista) {
            $errors = array_merge($errors, $accionista->validate("accionistas[$index]"));
        }

        if ($this->nombreOpcion1 === '') {
            $errors['nombreOpcion1'] = 'Ingrese el nombre opcion 1 para la sociedad';
        }
        if ($this->nombreOpcion2 === '') {
            $errors['nombreOpcion2'] = 'Ingrese el nombre opcion 2 para la sociedad';
        }
        if ($this->nombreOpcion3 === '') {
            $errors['nombreOpcion3'] = 'Ingrese el nombre opcion 3 para la sociedad';
        }

        if (!in_array($this->cierreEjercicioMes, ['DICIEMBRE', 'JUNIO'], true)) {
            $errors['cierreEjercicioMes'] = 'Seleccione el cierre de ejercicio';
        }

        $errors = array_merge($errors, $this->domicilio->validate('domicilio'));

        if ($this->duracionSociedad < 1) {
            $errors['duracionSociedad'] = 'La duracion debe ser de al menos 1 anio';
        } elseif ($this->duracionSociedad > 99) {
            $errors['duracionSociedad'] = 'La duracion no puede superar los 99 anios';
        }

        if ($this->emailSociedad !== '') {
            if (filter_var($this->emailSociedad, FILTER_VALIDATE_EMAIL) === false) {
                $errors['emailSociedad'] = 'Ingrese un email valido';
            } elseif (!DominioEmailValidator::esValido($this->emailSociedad)) {
                $errors['emailSociedad'] = 'El dominio del email no existe o no puede recibir correo';
            }
        }

        if ($this->administradores === []) {
            $errors['administradores'] = 'Ingrese al menos un administrador';
        }
        foreach ($this->administradores as $index => $administrador) {
            $errors = array_merge($errors, $administrador->validate("administradores[$index]"));
        }

        if (!$this->porcentajesSuman100()) {
            $errors['porcentajesSuman100'] = 'Los porcentajes de participacion de los accionistas deben sumar 100%';
        }

        if (!$this->tipoObjetoValido()) {
            $errors['tipoObjeto'] = 'Seleccione el objeto social';
        }

        if (!$this->objetoValido()) {
            $errors['objetoSocial'] = 'Describa el objeto social';
        }

        return $errors;
    }

    private function porcentajesSuman100(): bool
    {
        if ($this->accionistas === []) {
            return true;
        }

        $total = 0.0;
        foreach ($this->accionistas as $accionista) {
            $valor = str_replace(',', '.', $accionista->porcentajeParticipacion);
            if (!is_numeric($valor)) {
                return false;
            }
            $total += (float) $valor;
        }

        return abs($total - 100.0) <= 0.01;
    }

    private function tipoObjetoValido(): bool
    {
        return $this->tipoObjeto === 'ESPECIFICO' || ObjetoSocialCategoria::fromName($this->tipoObjeto) !== null;
    }

    private function objetoValido(): bool
    {
        return $this->tipoObjeto !== 'ESPECIFICO' || $this->objetoSocial !== '';
    }
}
