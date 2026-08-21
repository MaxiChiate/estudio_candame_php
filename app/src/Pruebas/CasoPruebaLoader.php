<?php

declare(strict_types=1);

namespace EstudioCandame\Pruebas;

use DateTimeImmutable;
use EstudioCandame\Model\ConsultaConstitucionForm;
use EstudioCandame\Model\TipoSocietario;
use EstudioCandame\Service\CapitalMinimoInfo;
use RuntimeException;

/**
 * Carga los casos de app/config/casos_prueba/*.json y los arma como los objetos de
 * dominio que consumen los golden tests, bin/ficha-preview.php, bin/consulta-test.php y
 * la ruta de humo. Vive bajo app/src/ (no en tests/) porque produccion tambien lo
 * necesita -- composer install --no-dev no incluye el autoload-dev de tests/.
 */
final class CasoPruebaLoader
{
    private const DIRECTORIO = __DIR__ . '/../../config/casos_prueba';

    /** @return array{form: ConsultaConstitucionForm, capitalMinimo: CapitalMinimoInfo, enviadoEn: DateTimeImmutable} */
    public static function cargar(string $caso): array
    {
        $ruta = self::DIRECTORIO . '/' . $caso . '.json';
        $contenido = file_get_contents($ruta);
        if ($contenido === false) {
            throw new RuntimeException("No se pudo leer el caso de prueba: $ruta");
        }

        $data = json_decode($contenido, true);
        if (!is_array($data)) {
            throw new RuntimeException("El caso de prueba no es JSON valido: $ruta");
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
        $archivos = glob(self::DIRECTORIO . '/*.json') ?: [];

        return array_map(static fn (string $ruta): string => basename($ruta, '.json'), $archivos);
    }
}
