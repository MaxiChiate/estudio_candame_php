<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

use EstudioCandame\Validation\CuitCuilCdiValidator;

final class IdentificacionFiscal
{
    public function __construct(
        public ?IdentificacionFiscalTipo $tipo = null,
        public string $numero = '',
    ) {
    }

    /** @return ValidationError[] */
    public function validate(string $prefix): array
    {
        $errors = [];

        if ($this->tipo === null) {
            $errors[] = new ValidationError("$prefix.identificacionFiscalTipo", 'CAMPO_REQUERIDO', 'Seleccione CUIT, CUIL o CDI');
        }

        if ($this->numero === '') {
            $errors[] = new ValidationError("$prefix.identificacionFiscalNumero", 'CAMPO_REQUERIDO', 'Ingrese el numero de CUIT/CUIL/CDI');

            return $errors;
        }

        $codigo = CuitCuilCdiValidator::validar($this->numero);
        if ($codigo !== null) {
            $errors[] = new ValidationError("$prefix.identificacionFiscalNumero", $codigo, self::mensaje($codigo));
        }

        return $errors;
    }

    private static function mensaje(string $codigo): string
    {
        return match ($codigo) {
            'FORMATO_INVALIDO' => 'El CUIT/CUIL/CDI debe tener 11 digitos numericos',
            'DIGITO_VERIFICADOR_INVALIDO' => 'El CUIT/CUIL/CDI ingresado no es valido',
            'PERSONA_JURIDICA_NO_PERMITIDA' => 'Ingrese un CUIT/CUIL/CDI de persona humana, no de persona juridica',
            default => 'El CUIT/CUIL/CDI ingresado no es valido',
        };
    }
}
