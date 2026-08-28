<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Ficha;

use EstudioCandame\Pruebas\CasoPruebaLoader;
use EstudioCandame\Service\FichaConstitucionXlsxBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Compara buildRows() (las filas [etiqueta, valor], antes de tocar PhpSpreadsheet)
 * contra el .txt golden de cada caso. El agente no regenera golden files: si un golden
 * cambia, este test queda rojo y lo actualiza una persona despues de mirar el diff con
 * bin/ficha-preview.php --update-golden.
 */
final class GoldenTest extends TestCase
{
    /** @return array<string, string[]> */
    public static function casos(): array
    {
        $casos = [];
        foreach (CasoPruebaLoader::listarCasos() as $caso) {
            $casos[$caso] = [$caso];
        }

        return $casos;
    }

    #[DataProvider('casos')]
    public function testFilasCoincidenConElGolden(string $caso): void
    {
        $fixture = CasoPruebaLoader::cargar($caso);

        $builder = new FichaConstitucionXlsxBuilder();
        $filas = $builder->buildRows($fixture['form'], $fixture['capitalMinimo'], $fixture['enviadoEn']);
        $lineas = array_map(
            static fn (array $fila): string => $fila[0] . ': ' . $fila[1],
            $filas,
        );

        $rutaGolden = __DIR__ . '/../Fixtures/golden/' . $caso . '.txt';
        self::assertFileExists($rutaGolden, "Falta el golden de '$caso'. Generarlo con bin/ficha-preview.php --update-golden.");

        $esperado = (string) file_get_contents($rutaGolden);
        self::assertSame(rtrim($esperado, "\n"), implode("\n", $lineas));
    }
}
