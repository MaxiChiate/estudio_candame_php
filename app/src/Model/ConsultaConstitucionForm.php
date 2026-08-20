<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

use DateTimeImmutable;
use EstudioCandame\Service\CapitalMinimoInfo;

final class ConsultaConstitucionForm
{
    /**
     * @param Socio[] $socios
     * @param Administrador[] $administradores
     */
    public function __construct(
        public ?TipoSocietario $tipoSocietario = null,
        public Contacto $contacto = new Contacto(),
        public Sociedad $sociedad = new Sociedad(),
        public array $socios = [],
        public array $administradores = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $socios = array_map(
            static fn (mixed $item): Socio => Socio::fromArray((array) $item),
            (array) ($data['socios'] ?? []),
        );
        $administradores = array_map(
            static fn (mixed $item): Administrador => Administrador::fromArray((array) $item),
            (array) ($data['administradores'] ?? []),
        );

        return new self(
            tipoSocietario: TipoSocietario::tryFrom((string) ($data['tipoSocietario'] ?? '')),
            contacto: Contacto::fromArray((array) ($data['contacto'] ?? [])),
            sociedad: Sociedad::fromArray((array) ($data['sociedad'] ?? [])),
            socios: array_values($socios),
            administradores: array_values($administradores),
        );
    }

    /** @return ValidationError[] */
    public function validate(DateTimeImmutable $ahora, CapitalMinimoInfo $capitalMinimo): array
    {
        $errors = [];

        if ($this->tipoSocietario === null) {
            $errors[] = new ValidationError('tipoSocietario', 'CAMPO_REQUERIDO', 'Seleccione el tipo societario');
        }

        $errors = [...$errors, ...$this->contacto->validate()];

        if ($this->tipoSocietario !== null) {
            $errors = [...$errors, ...$this->sociedad->validate('sociedad', $this->tipoSocietario, $capitalMinimo)];
        }

        if ($this->socios === []) {
            $errors[] = new ValidationError('socios', 'CAMPO_REQUERIDO', 'Ingrese al menos un socio');
        } elseif (count($this->socios) > 20) {
            $errors[] = new ValidationError('socios', 'MAXIMO_SOCIOS_EXCEDIDO', 'No puede haber más de 20 socios');
        }
        foreach ($this->socios as $index => $socio) {
            $errors = [...$errors, ...$socio->validate("socios[$index]", $ahora)];
        }

        if ($this->administradores === []) {
            $errors[] = new ValidationError('administradores', 'CAMPO_REQUERIDO', 'Ingrese al menos un administrador');
        }
        foreach ($this->administradores as $index => $administrador) {
            $errors = [...$errors, ...$administrador->validate("administradores[$index]", $ahora)];
        }

        if ($this->administradores !== [] && !$this->hayAdministradorTitular()) {
            $errors[] = new ValidationError('administradores', 'FALTA_TITULAR', 'Debe haber al menos un administrador titular');
        }

        if (!$this->porcentajesSuman100()) {
            $errors[] = new ValidationError('socios', 'PORCENTAJES_NO_SUMAN_100', 'Los porcentajes de participación deben sumar 100%');
        }

        return $errors;
    }

    private function hayAdministradorTitular(): bool
    {
        foreach ($this->administradores as $administrador) {
            if ($administrador->cargo === CargoAdministrador::TITULAR) {
                return true;
            }
        }

        return false;
    }

    private function porcentajesSuman100(): bool
    {
        if ($this->socios === []) {
            return true;
        }

        $total = 0.0;
        foreach ($this->socios as $socio) {
            $valor = str_replace(',', '.', $socio->porcentajeParticipacion);
            if (!is_numeric($valor)) {
                return false;
            }
            $total += (float) $valor;
        }

        return abs($total - 100.0) <= 0.01;
    }
}
