<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Ficha;

use EstudioCandame\Service\FichaConstitucionXlsxBuilder;
use EstudioCandame\Tests\Fixtures\FichaFixtures;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

/**
 * Un solo caso real: genera el .xlsx de verdad, lo reabre con PhpSpreadsheet y verifica
 * que las celdas coinciden con el mismo golden que usa GoldenTest -- prueba que el
 * writer no se desvia de lo que devuelve buildRows().
 */
final class XlsxGeneracionTest extends TestCase
{
    public function testCeldasDelXlsxCoincidenConElGolden(): void
    {
        $fixture = FichaFixtures::cargar('sas-dos-socios');

        $builder = new FichaConstitucionXlsxBuilder();
        $bytes = $builder->build($fixture['form'], $fixture['capitalMinimo'], $fixture['enviadoEn']);

        $archivoTemporal = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        self::assertNotFalse($archivoTemporal);
        file_put_contents($archivoTemporal, $bytes);

        $sheet = IOFactory::load($archivoTemporal)->getActiveSheet();
        unlink($archivoTemporal);

        $rutaGolden = __DIR__ . '/../Fixtures/golden/sas-dos-socios.txt';
        $lineasEsperadas = explode("\n", rtrim((string) file_get_contents($rutaGolden), "\n"));

        // Las filas 1 y 2 del xlsx son el titulo de dos lineas (ESTUDIO JURIDICO
        // CANDAME / FICHA DE DATOS...), que no forma parte de buildRows() -- los datos
        // empiezan en la fila 3.
        foreach ($lineasEsperadas as $indice => $lineaEsperada) {
            $fila = $indice + 3;
            $etiqueta = (string) $sheet->getCell("A$fila")->getValue();
            $valor = (string) $sheet->getCell("B$fila")->getValue();
            $lineaObtenida = $valor !== '' ? "$etiqueta: $valor" : "$etiqueta: ";

            self::assertSame($lineaEsperada, $lineaObtenida, "Fila $fila no coincide con el golden");
        }
    }
}
