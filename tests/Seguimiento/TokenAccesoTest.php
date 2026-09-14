<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use EstudioCandame\Seguimiento\TokenGenerator;

/**
 * El token en claro no puede quedar guardado en ningun lado.
 */
final class TokenAccesoTest extends BaseDeDatosTestCase
{
    public function testElTokenNoQuedaEnClaroEnNingunaColumna(): void
    {
        $id = $this->tramites->crear('PRES-2026-0300', 'Token SAS');
        $token = $this->accesos->emitir($id, 'cliente de prueba');

        // Se barren TODAS las columnas de texto de las tres tablas, no solo token_hash:
        // el token no tiene que haberse colado en una etiqueta ni en una nota.
        $pdo = $this->conexion->pdo();
        foreach (['tramite', 'tramite_evento', 'tramite_acceso'] as $tabla) {
            $filas = $pdo->query('SELECT * FROM ' . $tabla)->fetchAll();
            foreach ($filas as $fila) {
                foreach ($fila as $columna => $valor) {
                    if (!is_string($valor)) {
                        continue;
                    }
                    self::assertStringNotContainsString(
                        $token,
                        $valor,
                        sprintf('El token aparece en claro en %s.%s', $tabla, (string) $columna),
                    );
                }
            }
        }
    }

    public function testEnBaseQuedaElSha256DelToken(): void
    {
        $id = $this->tramites->crear('PRES-2026-0301', 'Hash SAS');
        $token = $this->accesos->emitir($id, 'cliente');

        $guardado = $this->conexion->pdo()->query('SELECT token_hash FROM tramite_acceso')->fetchColumn();

        self::assertSame(hash('sha256', $token), $guardado);
        self::assertSame(64, strlen((string) $guardado));
    }

    public function testElTokenGeneradoTieneElFormatoEsperado(): void
    {
        $generador = new TokenGenerator();

        $vistos = [];
        for ($i = 0; $i < 50; $i++) {
            $token = $generador->generar();
            self::assertSame(32, strlen($token));
            self::assertTrue(TokenGenerator::formatoValido($token));
            $vistos[$token] = true;
        }

        self::assertCount(50, $vistos, 'El generador repitio tokens.');
    }

    public function testElFormatoSeValidaAntesDeConsultarLaBase(): void
    {
        foreach (['', 'corto', str_repeat('z', 32), bin2hex(random_bytes(20))] as $invalido) {
            self::assertFalse(TokenGenerator::formatoValido($invalido));
            self::assertNull($this->accesos->buscarPorToken($invalido));
        }
    }

    public function testRevocarInvalidaElTokenSinBorrarElRegistro(): void
    {
        $id = $this->tramites->crear('PRES-2026-0302', 'Revoca SAS');
        $token = $this->accesos->emitir($id, 'cliente');

        $acceso = $this->accesos->buscarPorToken($token);
        self::assertNotNull($acceso);

        $this->accesos->revocar($acceso->accesoId);

        self::assertNull($this->accesos->buscarPorToken($token), 'Un token revocado sigue resolviendo.');
        // El registro queda, para poder auditar a quien se le habia dado el enlace.
        $registro = $this->accesos->porId($acceso->accesoId);
        self::assertNotNull($registro);
        self::assertTrue($registro->estaRevocado());
    }

    public function testRegistrarAccesoCuentaLasVisitas(): void
    {
        $id = $this->tramites->crear('PRES-2026-0303', 'Contador SAS');
        $token = $this->accesos->emitir($id, 'cliente');
        $acceso = $this->accesos->buscarPorToken($token);
        self::assertNotNull($acceso);

        self::assertSame(0, $this->accesos->porId($acceso->accesoId)?->accesos);

        $this->accesos->registrarAcceso($acceso->accesoId);
        $this->accesos->registrarAcceso($acceso->accesoId);

        $registro = $this->accesos->porId($acceso->accesoId);
        self::assertNotNull($registro);
        self::assertSame(2, $registro->accesos);
        self::assertNotNull($registro->ultimoAccesoEl);
    }
}
