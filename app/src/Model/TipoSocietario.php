<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

enum TipoSocietario: string
{
    case SAS = 'SAS';
    case SRL = 'SRL';
    case SA = 'SA';

    public function etiquetaOrgano(): string
    {
        return match ($this) {
            self::SAS => 'Administración',
            self::SRL => 'Gerencia',
            self::SA => 'Directorio',
        };
    }

    public function etiquetaTitular(): string
    {
        return match ($this) {
            self::SAS => 'Administrador titular',
            self::SRL => 'Gerente titular',
            self::SA => 'Director titular',
        };
    }

    public function etiquetaSuplente(): string
    {
        return match ($this) {
            self::SAS => 'Administrador suplente',
            self::SRL => 'Gerente suplente',
            self::SA => 'Director suplente',
        };
    }

    /**
     * Tokens que, como ultima palabra de una denominacion, la hacen invalida (ej.
     * "Acme SAS" termina en el token "SAS"). El chequeo es por token completo, no por
     * sufijo de letras, para que "Rosas" no se rechace por terminar en "sas".
     *
     * @return string[]
     */
    public function tokensDenominacion(): array
    {
        return [$this->value];
    }
}
