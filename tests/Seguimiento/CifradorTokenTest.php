<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use EstudioCandame\Seguimiento\CifradorToken;
use EstudioCandame\Seguimiento\TokenGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Cifrado reversible del token. No toca la base: corre siempre.
 */
final class CifradorTokenTest extends TestCase
{
    public function testDescifraLoQueCifro(): void
    {
        $cifrador = new CifradorToken(self::clave());
        $token = (new TokenGenerator())->generar();

        $cifrado = $cifrador->cifrar($token);

        self::assertNotNull($cifrado);
        self::assertStringNotContainsString($token, $cifrado);
        self::assertSame($token, $cifrador->descifrar($cifrado));
    }

    public function testElMismoTokenCifraDistintoCadaVez(): void
    {
        // Nonce aleatorio: dos enlaces nunca dejan el mismo valor en la base.
        $cifrador = new CifradorToken(self::clave());
        $token = (new TokenGenerator())->generar();

        self::assertNotSame($cifrador->cifrar($token), $cifrador->cifrar($token));
    }

    public function testElCifradoEntraEnLaColumna(): void
    {
        $cifrado = (new CifradorToken(self::clave()))->cifrar((new TokenGenerator())->generar());

        self::assertNotNull($cifrado);
        self::assertLessThanOrEqual(255, strlen($cifrado));
    }

    public function testConOtraClaveNoDescifra(): void
    {
        $cifrado = (new CifradorToken(self::clave()))->cifrar((new TokenGenerator())->generar());
        self::assertNotNull($cifrado);

        self::assertNull((new CifradorToken(self::clave()))->descifrar($cifrado));
    }

    public function testUnValorAlteradoNoDescifra(): void
    {
        $cifrador = new CifradorToken(self::clave());
        $cifrado = $cifrador->cifrar((new TokenGenerator())->generar());
        self::assertNotNull($cifrado);

        $crudo = base64_decode($cifrado, true);
        $crudo[strlen($crudo) - 1] = $crudo[strlen($crudo) - 1] === 'a' ? 'b' : 'a';

        self::assertNull($cifrador->descifrar(base64_encode($crudo)));
        self::assertNull($cifrador->descifrar('no-es-base64-%%%'));
        self::assertNull($cifrador->descifrar(''));
    }

    public function testSinClaveQuedaDeshabilitadoSinRomper(): void
    {
        foreach (['', 'corta', base64_encode(random_bytes(16))] as $claveInvalida) {
            $cifrador = new CifradorToken($claveInvalida);

            self::assertFalse($cifrador->habilitado());
            self::assertNull($cifrador->cifrar((new TokenGenerator())->generar()));
        }
    }

    public function testSinClaveNoDescifraLoDeOtraClave(): void
    {
        $cifrado = (new CifradorToken(self::clave()))->cifrar((new TokenGenerator())->generar());
        self::assertNotNull($cifrado);

        self::assertNull((new CifradorToken(''))->descifrar($cifrado));
    }

    private static function clave(): string
    {
        return base64_encode(random_bytes(32));
    }
}
