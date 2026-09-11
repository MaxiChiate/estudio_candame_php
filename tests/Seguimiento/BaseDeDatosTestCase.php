<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use EstudioCandame\Seguimiento\AccesoRepository;
use EstudioCandame\Seguimiento\Conexion;
use EstudioCandame\Seguimiento\TokenGenerator;
use EstudioCandame\Seguimiento\TramiteRepository;
use EstudioCandame\Support\RelojSistema;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Base de los tests que necesitan MySQL/MariaDB.
 *
 * Corren contra una base APARTE (candame_test por default, o TEST_DB_NAME), nunca
 * contra la de desarrollo: cada test arranca truncando las tablas.
 *
 * Si no hay base disponible, los tests se marcan como skipped en vez de fallar, para
 * que la suite siga corriendo en una maquina sin motor levantado. El skip dice
 * explicitamente que falto, asi no se confunde con "paso".
 */
abstract class BaseDeDatosTestCase extends TestCase
{
    protected Conexion $conexion;
    protected TramiteRepository $tramites;
    protected AccesoRepository $accesos;

    protected function setUp(): void
    {
        self::definirAppPath();

        $config = self::configuracion();
        if ($config === null) {
            self::markTestSkipped(
                'Sin base de datos de test. Configurar TEST_DB_NAME/TEST_DB_USER/TEST_DB_PASS '
                . '(o DB_* en app/.env) y aplicar database/migrations/001_seguimiento.sql.'
            );
        }

        $this->conexion = new Conexion(
            $config['host'],
            $config['name'],
            $config['user'],
            $config['pass'],
            $config['charset'],
        );

        try {
            $pdo = $this->conexion->pdo();
            // Las FK son ON DELETE CASCADE, asi que basta con vaciar tramite; se
            // desactiva el chequeo igual por si el orden cambia en el futuro.
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $pdo->exec('TRUNCATE TABLE tramite_evento');
            $pdo->exec('TRUNCATE TABLE tramite_acceso');
            $pdo->exec('TRUNCATE TABLE tramite');
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable $e) {
            self::markTestSkipped('No se pudo preparar la base de test: ' . $e->getMessage());
        }

        $reloj = new RelojSistema();
        $this->tramites = new TramiteRepository($this->conexion, $reloj);
        $this->accesos = new AccesoRepository($this->conexion, $reloj, new TokenGenerator());
    }

    /**
     * Credenciales de la base de test. Prioriza TEST_DB_*; si no estan, cae al .env del
     * proyecto pero SIEMPRE cambiando el nombre de la base a candame_test, para que un
     * descuido no trunque las tablas de desarrollo.
     *
     * OJO: lee el .env a un array propio, NO $_ENV. Otros tests de la suite
     * (AdminAccesoTest) pisan $_ENV['DB_*'] con un host invalido a proposito para
     * demostrar que ciertas rutas no tocan la base; si leyeramos de ahi, estos tests se
     * saltearian segun el orden en que corran.
     *
     * @return array{host: string, name: string, user: string, pass: string, charset: string}|null
     */
    protected static function configuracion(): ?array
    {
        $env = self::valoresDelEnv();

        $usuario = (string) (getenv('TEST_DB_USER') ?: ($env['DB_USER'] ?? ''));
        if ($usuario === '') {
            return null;
        }

        return [
            'host' => (string) (getenv('TEST_DB_HOST') ?: ($env['DB_HOST'] ?? 'localhost')),
            'name' => (string) (getenv('TEST_DB_NAME') ?: 'candame_test'),
            'user' => $usuario,
            'pass' => (string) (getenv('TEST_DB_PASS') ?: ($env['DB_PASS'] ?? '')),
            'charset' => (string) (getenv('TEST_DB_CHARSET') ?: ($env['DB_CHARSET'] ?? 'utf8mb4')),
        ];
    }

    /**
     * createArrayBacked parsea el archivo y devuelve los valores sin tocar $_ENV ni
     * getenv(): eso es justamente lo que necesitamos para no depender del estado global.
     *
     * @return array<string, string>
     */
    protected static function valoresDelEnv(): array
    {
        static $valores = null;
        if ($valores !== null) {
            return $valores;
        }

        $ruta = dirname(__DIR__, 2) . '/app';
        if (!is_file($ruta . '/.env')) {
            return $valores = [];
        }

        return $valores = \Dotenv\Dotenv::createArrayBacked($ruta)->safeLoad();
    }

    protected static function definirAppPath(): void
    {
        if (!defined('APP_PATH')) {
            define('APP_PATH', dirname(__DIR__, 2) . '/app');
        }
    }
}
