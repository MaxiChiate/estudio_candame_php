<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use DateTimeImmutable;
use EstudioCandame\Seguimiento\AccesoRepository;
use EstudioCandame\Seguimiento\TokenGenerator;
use EstudioCandame\Support\RelojFijo;

/**
 * El token en claro no puede quedar guardado en ningun lado.
 */
final class TokenAccesoTest extends BaseDeDatosTestCase
{
    public function testElTokenNoQuedaEnClaroEnNingunaColumna(): void
    {
        $id = $this->crearTramite('Token SAS');
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
        $id = $this->crearTramite('Hash SAS');
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
        $id = $this->crearTramite('Revoca SAS');
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

    /**
     * Recargas y atras/adelante dentro de la ventana son la misma visita: el contador no
     * se mueve, pero la fecha del ultimo acceso si. Pasada la ventana, vuelve a sumar.
     */
    public function testRegistrarAccesoAgrupaLasVisitasDentroDeLaVentana(): void
    {
        $id = $this->crearTramite('Contador SAS');
        $token = $this->accesos->emitir($id, 'cliente');
        $acceso = $this->accesos->buscarPorToken($token);
        self::assertNotNull($acceso);

        self::assertSame(0, $this->accesos->porId($acceso->accesoId)?->accesos);

        $inicio = new DateTimeImmutable('2026-09-14 10:00:00');
        $ventana = AccesoRepository::VENTANA_VISITA_MINUTOS;
        $registrarA = function (DateTimeImmutable $momento) use ($acceso): void {
            (new AccesoRepository($this->conexion, new RelojFijo($momento), new TokenGenerator()))
                ->registrarAcceso($acceso->accesoId);
        };

        // Primera visita: suma.
        $registrarA($inicio);
        // Recargas dentro de la ventana, contada desde el ultimo acceso: no suman.
        $registrarA($inicio->modify('+1 minute'));
        $registrarA($inicio->modify(sprintf('+%d minutes', $ventana - 1)));

        $registro = $this->accesos->porId($acceso->accesoId);
        self::assertSame(1, $registro?->accesos);
        self::assertEquals($inicio->modify(sprintf('+%d minutes', $ventana - 1)), $registro->ultimoAccesoEl);

        // Pasada la ventana desde el ultimo acceso: visita nueva.
        $registrarA($inicio->modify(sprintf('+%d minutes', 2 * $ventana)));

        self::assertSame(2, $this->accesos->porId($acceso->accesoId)?->accesos);
    }

    /**
     * La ventana se cuenta desde el ULTIMO acceso, no desde el ultimo que sumo: quien
     * vuelve cada pocos minutos sigue en la misma visita aunque, desde la primera, ya
     * haya pasado mas que la ventana.
     */
    public function testLaVentanaSeReiniciaConCadaAcceso(): void
    {
        $id = $this->crearTramite('Ventana SAS');
        $token = $this->accesos->emitir($id, 'cliente');
        $acceso = $this->accesos->buscarPorToken($token);
        self::assertNotNull($acceso);

        $inicio = new DateTimeImmutable('2026-09-14 10:00:00');
        $ventana = AccesoRepository::VENTANA_VISITA_MINUTOS;
        $registrarA = function (DateTimeImmutable $momento) use ($acceso): void {
            (new AccesoRepository($this->conexion, new RelojFijo($momento), new TokenGenerator()))
                ->registrarAcceso($acceso->accesoId);
        };

        // Cada acceso cae dentro de la ventana del anterior, pero el ultimo esta a mas de
        // una ventana del primero.
        $paso = intdiv($ventana * 2, 3);
        $registrarA($inicio);
        $registrarA($inicio->modify(sprintf('+%d minutes', $paso)));
        $registrarA($inicio->modify(sprintf('+%d minutes', 2 * $paso)));
        self::assertGreaterThan($ventana, 2 * $paso);

        self::assertSame(1, $this->accesos->porId($acceso->accesoId)?->accesos);
    }
}
