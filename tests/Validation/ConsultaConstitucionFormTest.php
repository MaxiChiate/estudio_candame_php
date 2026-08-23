<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Validation;

use DateTimeImmutable;
use EstudioCandame\Model\ConsultaConstitucionForm;
use EstudioCandame\Model\TipoSocietario;
use EstudioCandame\Service\CapitalMinimoInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Table-driven: [payload, codigos de error esperados]. Un caso por regla del spec, mas
 * un caso feliz. Solo se asserta sobre codigo, nunca sobre mensaje -- corregir una
 * tilde en un mensaje no puede romper esta suite.
 */
final class ConsultaConstitucionFormTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>, 1: string[]}> */
    public static function casos(): array
    {
        return [
            'campo requerido (contacto.nombre)' => [
                self::conCambios(['contacto.nombre' => '']),
                ['CAMPO_REQUERIDO'],
            ],
            'tipo societario faltante (corta el resto de sociedad)' => [
                self::conCambios(['tipoSocietario' => '']),
                ['CAMPO_REQUERIDO'],
            ],
            'email de contacto invalido' => [
                self::conCambios(['contacto.email' => 'no-es-un-email']),
                ['EMAIL_INVALIDO'],
            ],
            'tipo de documento faltante' => [
                self::conCambios(['socios.0.tipoDocumento' => '']),
                ['CAMPO_REQUERIDO'],
            ],
            'numero de documento faltante' => [
                self::conCambios(['socios.0.numeroDocumento' => '']),
                ['CAMPO_REQUERIDO'],
            ],
            'CUIT con formato invalido (no son 11 digitos)' => [
                self::conCambios(['socios.0.identificacionFiscalNumero' => '1234567890']),
                ['FORMATO_INVALIDO'],
            ],
            'CUIT con digito verificador invalido' => [
                self::conCambios(['socios.0.identificacionFiscalNumero' => '20100000018']),
                ['DIGITO_VERIFICADOR_INVALIDO'],
            ],
            'CUIT de persona juridica no permitido' => [
                self::conCambios(['socios.0.identificacionFiscalNumero' => '30100000004']),
                ['PERSONA_JURIDICA_NO_PERMITIDA'],
            ],
            'fecha de nacimiento futura' => [
                self::conCambios(['socios.0.fechaNacimiento' => '2030-01-01']),
                ['FECHA_FUTURA'],
            ],
            'menor de 18 anios' => [
                self::conCambios(['socios.0.fechaNacimiento' => '2020-01-01']),
                ['MENOR_DE_EDAD'],
            ],
            'casado sin conyuge' => [
                self::conCambios(['socios.0.estadoCivil' => 'CASADO', 'socios.0.conyuge' => '']),
                ['CAMPO_REQUERIDO'],
            ],
            'porcentajes de participacion no suman 100' => [
                self::conCambios(['socios.0.porcentajeParticipacion' => '50']),
                ['PORCENTAJES_NO_SUMAN_100'],
            ],
            'cierre de ejercicio 29/02 invalido' => [
                self::conCambios(['sociedad.cierreEjercicioDia' => 29, 'sociedad.cierreEjercicioMes' => 2]),
                ['FECHA_CIERRE_INVALIDA'],
            ],
            'cierre de ejercicio con mes fuera de rango' => [
                self::conCambios(['sociedad.cierreEjercicioMes' => 13]),
                ['FECHA_CIERRE_INVALIDA'],
            ],
            'denominacion termina en el tipo societario' => [
                self::conCambios(['sociedad.nombreOpcion1' => 'Acme SAS']),
                ['DENOMINACION_CON_TIPO_SOCIAL'],
            ],
            'denominacion NO se rechaza por sufijo de letras' => [
                self::conCambios(['sociedad.nombreOpcion1' => 'Rosas']),
                [],
            ],
            'sin administrador titular' => [
                self::conCambios(['administradores.0.cargo' => 'SUPLENTE']),
                ['FALTA_TITULAR'],
            ],
            'sede fuera de CABA' => [
                self::conCambios(['sociedad.sedeJurisdiccion' => 'Buenos Aires']),
                ['SEDE_FUERA_DE_CABA'],
            ],
            'capital por debajo del minimo bloqueante de la SAS' => [
                self::conCambios(['sociedad.capitalSocial' => 100000]),
                ['CAPITAL_INSUFICIENTE'],
            ],
            'mas de 20 socios' => [
                self::conCambios(['socios' => self::veintiunSocios()]),
                ['MAXIMO_SOCIOS_EXCEDIDO'],
            ],
            'caso feliz: sin errores' => [
                self::payloadValido(),
                [],
            ],
        ];
    }

    #[DataProvider('casos')]
    public function testCodigosDeError(array $payload, array $codigosEsperados): void
    {
        $form = ConsultaConstitucionForm::fromArray($payload);
        $errores = $form->validate(self::ahora(), self::capitalMinimoSas());

        $codigosObtenidos = array_map(static fn ($error) => $error->codigo, $errores);

        self::assertEqualsCanonicalizing($codigosEsperados, $codigosObtenidos);
    }

    private static function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-10');
    }

    private static function capitalMinimoSas(): CapitalMinimoInfo
    {
        return new CapitalMinimoInfo(
            TipoSocietario::SAS,
            753200.0,
            true,
            'Mínimo legal: 2 veces el SMVM vigente (art. 40, Ley 27.349).',
            '2026-08-01',
        );
    }

    /** @return array<string, mixed> */
    private static function payloadValido(): array
    {
        return [
            'tipoSocietario' => 'SAS',
            'contacto' => [
                'nombre' => 'Juan Pérez',
                'email' => 'juan@example.com',
                'telefono' => '+54 9 11 1234-5678',
                'rol' => 'CONTADOR',
            ],
            'sociedad' => [
                'nombreOpcion1' => 'Acme',
                'nombreOpcion2' => 'Beta',
                'nombreOpcion3' => 'Gamma',
                'objetoSocial' => 'Desarrollo de software',
                'capitalSocial' => 800000,
                'duracionAnios' => 99,
                'cierreEjercicioDia' => 31,
                'cierreEjercicioMes' => 12,
                'sede' => ['calle' => 'Av. Corrientes', 'numero' => '1234'],
                'sedeJurisdiccion' => 'CABA',
                'emailSociedad' => '',
                'telefonoSociedad' => '',
                'urgente' => false,
            ],
            'socios' => [self::personaValida('20100000017', porcentaje: '100')],
            'administradores' => [self::personaValida('20100000017', cargo: 'TITULAR')],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function veintiunSocios(): array
    {
        $socios = [];
        for ($i = 0; $i < 20; $i++) {
            $socios[] = self::personaValida('20100000017', porcentaje: '5');
        }
        $socios[] = self::personaValida('20100000017', porcentaje: '0');

        return $socios;
    }

    /** @return array<string, mixed> */
    private static function personaValida(string $cuit, ?string $porcentaje = null, ?string $cargo = null): array
    {
        $persona = [
            'apellidoYNombre' => 'Pérez, Juan',
            'nacionalidad' => 'Argentina',
            'fechaNacimiento' => '1990-01-01',
            'tipoDocumento' => 'DNI',
            'numeroDocumento' => '32123456',
            'identificacionFiscalTipo' => 'CUIT',
            'identificacionFiscalNumero' => $cuit,
            'estadoCivil' => 'SOLTERO',
            'conyuge' => '',
            'profesion' => 'Ingeniero',
            'domicilioReal' => [
                'calle' => 'Rivadavia',
                'numero' => '100',
                'localidad' => 'Ciudad Autónoma de Buenos Aires',
                'provincia' => 'Ciudad Autónoma de Buenos Aires',
            ],
            'email' => 'juan@example.com',
        ];

        if ($porcentaje !== null) {
            $persona['porcentajeParticipacion'] = $porcentaje;
        }
        if ($cargo !== null) {
            $persona['cargo'] = $cargo;
        }

        return $persona;
    }

    /**
     * Aplica overrides por ruta con puntos (ej. "sociedad.capitalSocial") sobre un
     * payload valido, para que cada caso de la tabla solo declare lo que rompe.
     *
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    private static function conCambios(array $cambios): array
    {
        $payload = self::payloadValido();

        foreach ($cambios as $ruta => $valor) {
            $partes = explode('.', $ruta);
            $ref = &$payload;
            foreach ($partes as $indice => $parte) {
                if ($indice === count($partes) - 1) {
                    $ref[$parte] = $valor;

                    continue;
                }
                if (!isset($ref[$parte]) || !is_array($ref[$parte])) {
                    $ref[$parte] = [];
                }
                $ref = &$ref[$parte];
            }
            unset($ref);
        }

        return $payload;
    }
}
