<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Fixtures;

use EstudioCandame\Pruebas\CasoPruebaLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Los casos de app/config/casos_prueba/ son los datos que usan bin/consulta-test.php y
 * la ruta de humo para mandar mails reales de prueba -- si alguno no valida, el riesgo
 * real es que alguien "arregle" el validador para que pase en vez de arreglar el
 * fixture. Este test es la red que lo impide: cada caso tiene que validar con cero
 * errores.
 */
final class FixturesValidosTest extends TestCase
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
    public function testElCasoValidaSinErrores(string $caso): void
    {
        $fixture = CasoPruebaLoader::cargar($caso);

        $errores = $fixture['form']->validate($fixture['enviadoEn'], $fixture['capitalMinimo']);

        self::assertSame([], $errores, "El caso '$caso' no valida limpio.");
    }
}
