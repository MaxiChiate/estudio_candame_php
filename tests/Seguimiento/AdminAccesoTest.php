<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

/**
 * Proteccion del panel y comportamiento del feature flag.
 *
 * No extiende BaseDeDatosTestCase a proposito: el 401 y el 404 ocurren ANTES de tocar
 * la base (el middleware corta primero, y con el flag apagado la ruta no existe), asi
 * que estos tests corren sin motor levantado. Que pasen sin base es, de hecho, parte de
 * lo que verifican.
 */
final class AdminAccesoTest extends TestCase
{
    private const RUTAS_ADMIN = [
        '/admin/tramites',
        '/admin/tramites/nuevo',
        '/admin/tramites/1',
    ];

    public function testAdminSinCredencialesDevuelve401(): void
    {
        foreach (self::RUTAS_ADMIN as $ruta) {
            $response = $this->dispatch($ruta, true);

            self::assertSame(401, $response->getStatusCode(), sprintf('%s no pidio credenciales.', $ruta));
            self::assertStringStartsWith('Basic', $response->getHeaderLine('WWW-Authenticate'));
        }
    }

    public function testAdminConCredencialesIncorrectasDevuelve401(): void
    {
        $response = $this->dispatch('/admin/tramites', true, [
            'PHP_AUTH_USER' => 'candame',
            'PHP_AUTH_PW' => 'la-que-no-es',
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testSinAdminPassHashElPanelQuedaCerrado(): void
    {
        // Un .env a medio completar no puede terminar en un panel abierto.
        $response = $this->dispatch('/admin/tramites', true, [
            'PHP_AUTH_USER' => 'candame',
            'PHP_AUTH_PW' => 'lo-que-sea',
        ], adminConfigurado: false);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testConElFlagApagadoNingunaRutaDelPortalExiste(): void
    {
        $rutas = array_merge(self::RUTAS_ADMIN, ['/seguimiento', '/seguimiento/' . str_repeat('a', 32)]);

        foreach ($rutas as $ruta) {
            $response = $this->dispatch($ruta, false);

            self::assertSame(404, $response->getStatusCode(), sprintf('%s sigue registrada con el flag apagado.', $ruta));
        }
    }

    /** @param array<string, string> $server */
    private function dispatch(string $ruta, bool $seguimientoEnabled, array $server = [], bool $adminConfigurado = true): ResponseInterface
    {
        if (!defined('APP_PATH')) {
            define('APP_PATH', dirname(__DIR__, 2) . '/app');
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $_ENV['SEGUIMIENTO_ENABLED'] = $seguimientoEnabled ? 'true' : 'false';
        $_ENV['CONFIGURADOR_ENABLED'] = 'false';
        $_ENV['ADMIN_USER'] = $adminConfigurado ? 'candame' : '';
        $_ENV['ADMIN_PASS_HASH'] = $adminConfigurado ? password_hash('la-correcta', PASSWORD_DEFAULT) : '';
        // Credenciales de base deliberadamente invalidas: si alguna de estas rutas
        // intentara conectarse, el test fallaria en vez de pasar por casualidad.
        $_ENV['DB_HOST'] = 'no-existe-este-host.invalid';
        $_ENV['DB_NAME'] = 'no-existe';
        $_ENV['DB_USER'] = 'no-existe';
        $_ENV['DB_PASS'] = 'no-existe';

        $app = AppFactory::create();
        $twig = Twig::create(APP_PATH . '/templates', ['cache' => false, 'charset' => 'utf-8']);
        $twig->getEnvironment()->addGlobal('basePath', '');
        $twig->getEnvironment()->addGlobal('configuradorEnabled', false);
        $app->add(TwigMiddleware::create($app, $twig));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);

        (require APP_PATH . '/config/routes.php')($app, $twig);

        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', $ruta, $server));
    }
}
