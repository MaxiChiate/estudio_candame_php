#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/vendor/autoload.php';

use EstudioCandame\Pruebas\CasoPruebaLoader;
use EstudioCandame\Service\FichaConstitucionXlsxBuilder;

/**
 * Genera un .xlsx real a partir de un caso de prueba, para revisar visualmente la
 * ficha sin pasar por el navegador ni el mail. Con --update-golden regenera el golden
 * de tests/Fixtures/golden/ correspondiente al caso, en vez de un .xlsx.
 *
 * Uso:
 *   php bin/ficha-preview.php app/config/casos_prueba/sas-dos-socios.json out.xlsx
 *   php bin/ficha-preview.php app/config/casos_prueba/sas-dos-socios.json --update-golden
 */

$rutaCasoArg = $argv[1] ?? null;
$destinoArg = $argv[2] ?? null;

if ($rutaCasoArg === null || $destinoArg === null) {
    fwrite(STDERR, "Uso: php bin/ficha-preview.php <caso.json> <out.xlsx|--update-golden>\n");
    exit(1);
}

if (!is_file($rutaCasoArg)) {
    fwrite(STDERR, "No existe el archivo de caso: $rutaCasoArg\n");
    exit(1);
}

$caso = basename($rutaCasoArg, '.json');

$fixture = CasoPruebaLoader::cargar($caso);
$form = $fixture['form'];
$capitalMinimo = $fixture['capitalMinimo'];
$enviadoEn = $fixture['enviadoEn'];

$builder = new FichaConstitucionXlsxBuilder();

if ($destinoArg === '--update-golden') {
    $filas = $builder->buildRows($form, $capitalMinimo, $enviadoEn);
    $lineas = array_map(
        static fn (array $fila): string => $fila[0] . ': ' . $fila[1],
        $filas,
    );

    $rutaGolden = dirname(__DIR__) . '/tests/Fixtures/golden/' . $caso . '.txt';
    file_put_contents($rutaGolden, implode("\n", $lineas) . "\n");
    fwrite(STDOUT, "Golden actualizado: $rutaGolden\n");
    exit(0);
}

$bytes = $builder->build($form, $capitalMinimo, $enviadoEn);
file_put_contents($destinoArg, $bytes);
fwrite(STDOUT, "Generado: $destinoArg\n");
