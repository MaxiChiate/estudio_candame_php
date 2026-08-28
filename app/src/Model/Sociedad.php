<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

use EstudioCandame\Service\CapitalMinimoInfo;
use EstudioCandame\Support\Ars;
use EstudioCandame\Validation\DenominacionValidator;

final class Sociedad
{
    public function __construct(
        public string $nombreOpcion1 = '',
        public string $nombreOpcion2 = '',
        public string $nombreOpcion3 = '',
        public string $objetoSocial = '',
        public int $capitalSocial = 0,
        public int $duracionAnios = 0,
        public int $cierreEjercicioDia = 0,
        public int $cierreEjercicioMes = 0,
        public Domicilio $sede = new Domicilio(),
        public string $sedeJurisdiccion = '',
        public string $emailSociedad = '',
        public string $telefonoSociedad = '',
        public bool $urgente = false,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            nombreOpcion1: trim((string) ($data['nombreOpcion1'] ?? '')),
            nombreOpcion2: trim((string) ($data['nombreOpcion2'] ?? '')),
            nombreOpcion3: trim((string) ($data['nombreOpcion3'] ?? '')),
            objetoSocial: trim((string) ($data['objetoSocial'] ?? '')),
            capitalSocial: (int) ($data['capitalSocial'] ?? 0),
            duracionAnios: (int) ($data['duracionAnios'] ?? 0),
            cierreEjercicioDia: (int) ($data['cierreEjercicioDia'] ?? 0),
            cierreEjercicioMes: (int) ($data['cierreEjercicioMes'] ?? 0),
            sede: Domicilio::fromArray((array) ($data['sede'] ?? [])),
            sedeJurisdiccion: trim((string) ($data['sedeJurisdiccion'] ?? '')),
            emailSociedad: trim((string) ($data['emailSociedad'] ?? '')),
            telefonoSociedad: trim((string) ($data['telefonoSociedad'] ?? '')),
            urgente: (bool) ($data['urgente'] ?? false),
        );
    }

    /** @return ValidationError[] */
    public function validate(string $prefix, TipoSocietario $tipo, CapitalMinimoInfo $capitalMinimo): array
    {
        $errors = [];

        foreach (['nombreOpcion1', 'nombreOpcion2', 'nombreOpcion3'] as $campo) {
            if ($this->$campo === '') {
                $errors[] = new ValidationError("$prefix.$campo", 'CAMPO_REQUERIDO', 'Ingrese el nombre');
            } elseif (DenominacionValidator::terminaEnTipoSocietario($this->$campo, $tipo)) {
                $errors[] = new ValidationError("$prefix.$campo", 'DENOMINACION_CON_TIPO_SOCIAL', 'No incluya el tipo societario en el nombre');
            }
        }

        if ($this->objetoSocial === '') {
            $errors[] = new ValidationError("$prefix.objetoSocial", 'CAMPO_REQUERIDO', 'Describa el objeto social');
        }

        if ($this->capitalSocial <= 0) {
            $errors[] = new ValidationError("$prefix.capitalSocial", 'CAMPO_REQUERIDO', 'Ingrese el capital social');
        } elseif ($capitalMinimo->bloqueante && $capitalMinimo->piso !== null && $this->capitalSocial < $capitalMinimo->piso) {
            $errors[] = new ValidationError(
                "$prefix.capitalSocial",
                'CAPITAL_INSUFICIENTE',
                sprintf('El capital debe ser de al menos %s (%s)', Ars::format($capitalMinimo->piso), $capitalMinimo->detalle),
            );
        }

        if ($this->duracionAnios < 1) {
            $errors[] = new ValidationError("$prefix.duracionAnios", 'CAMPO_REQUERIDO', 'La duración debe ser de al menos 1 año');
        }

        if (!self::fechaCierreValida($this->cierreEjercicioDia, $this->cierreEjercicioMes)) {
            $errors[] = new ValidationError("$prefix.cierreEjercicio", 'FECHA_CIERRE_INVALIDA', 'Ingrese una fecha de cierre de ejercicio válida');
        }

        $errors = [...$errors, ...$this->sede->validate("$prefix.sede", ['calle', 'numero'])];

        if ($this->sedeJurisdiccion !== 'CABA') {
            $errors[] = new ValidationError("$prefix.sedeJurisdiccion", 'SEDE_FUERA_DE_CABA', 'La sede debe estar en la Ciudad Autónoma de Buenos Aires');
        }

        if ($this->emailSociedad !== '' && filter_var($this->emailSociedad, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = new ValidationError("$prefix.emailSociedad", 'EMAIL_INVALIDO', 'Ingrese un email válido');
        }

        return $errors;
    }

    /**
     * 29/02 no es una fecha de cierre valida (no existe 3 de cada 4 anios). Se usa un
     * anio no bisiesto fijo para el chequeo de calendario: rechaza 29/02 siempre, sin
     * afectar la validez de ninguna otra fecha.
     */
    private static function fechaCierreValida(int $dia, int $mes): bool
    {
        if ($mes < 1 || $mes > 12 || $dia < 1) {
            return false;
        }

        return checkdate($mes, $dia, 2025);
    }
}
