<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Fixtures;

use DateTimeImmutable;
use EstudioCandame\Model\ConsultaConstitucionForm;
use EstudioCandame\Model\TipoSocietario;
use EstudioCandame\Service\CapitalMinimoInfo;

/**
 * Carga los casos de tests/Fixtures/casos/*.json y los arma como los objetos de
 * dominio que consumen GoldenTest, XlsxGeneracionTest y bin/ficha-preview.php. Un solo
 * lugar para no repetir el parseo de capitalMinimo/enviadoEn en cada test.
 */
final class FichaFixtures
{
    /** @return array{form: ConsultaConstitucionForm, capitalMinimo: CapitalMinimoInfo, enviadoEn: DateTimeImmutable} */
    public static function cargar(string $caso): array
    {
        $ruta = __DIR__ . '/casos/' . $caso . '.json';
        $contenido = file_get_contents($ruta);
        if ($contenido === false) {
            throw new \RuntimeException("No se pudo leer el caso de prueba: $ruta");
        }

        $data = json_decode($contenido, true);
        if (!is_array($data)) {
            throw new \RuntimeException("El caso de prueba no es JSON valido: $ruta");
        }

        $capitalMinimoData = (array) $data['capitalMinimo'];
        $capitalMinimo = new CapitalMinimoInfo(
            TipoSocietario::from((string) $capitalMinimoData['tipo']),
            $capitalMinimoData['piso'] !== null ? (float) $capitalMinimoData['piso'] : null,
            (bool) $capitalMinimoData['bloqueante'],
            (string) $capitalMinimoData['detalle'],
            (string) $capitalMinimoData['fechaReferencia'],
        );

        return [
            'form' => ConsultaConstitucionForm::fromArray((array) $data['form']),
            'capitalMinimo' => $capitalMinimo,
            'enviadoEn' => new DateTimeImmutable((string) $data['enviadoEn']),
        ];
    }

    /** @return string[] */
    public static function listarCasos(): array
    {
        $archivos = glob(__DIR__ . '/casos/*.json') ?: [];

        return array_map(static fn (string $ruta): string => basename($ruta, '.json'), $archivos);
    }
}
