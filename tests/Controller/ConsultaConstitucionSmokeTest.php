<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Controller;

use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Unico smoke test del controller: 200 con el flag prendido, 404 con el flag apagado.
 * No se testea Twig ni el controller mas alla de esto -- ahi no esta el riesgo.
 */
final class ConsultaConstitucionSmokeTest extends TestCase
{
    public function testResponde200ConElFlagPrendido(): void
    {
        $response = $this->dispatch(true);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testResponde404ConElFlagApagado(): void
    {
        $response = $this->dispatch(false);

        self::assertSame(404, $response->getStatusCode());
    }

    private function dispatch(bool $configuradorEnabled): ResponseInterface
    {
        if (!defined('APP_PATH')) {
            define('APP_PATH', dirname(__DIR__, 2) . '/app');
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $_ENV['CONFIGURADOR_ENABLED'] = $configuradorEnabled ? 'true' : 'false';
        $_ENV['SAS_SMVM_API_URL'] = '';
        $_ENV['SMVM_FALLBACK_VALOR'] = '376600';
        $_ENV['SMVM_FALLBACK_FECHA'] = '2026-08-01';
        $_ENV['SAS_CAPITAL_MULTIPLO_SMVM'] = '2';
        $_ENV['MAIL_HOST'] = 'smtp.test';
        $_ENV['MAIL_USERNAME'] = 'web@test.com';
        $_ENV['MAIL_PASSWORD'] = 'x';

        $app = AppFactory::create();
        $twig = Twig::create(APP_PATH . '/templates', ['cache' => false, 'charset' => 'utf-8']);
        $twig->getEnvironment()->addGlobal('basePath', '');
        $twig->getEnvironment()->addGlobal('configuradorEnabled', $configuradorEnabled);
        $app->add(TwigMiddleware::create($app, $twig));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);

        (require APP_PATH . '/config/routes.php')($app, $twig);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/tramites/constitucion');

        return $app->handle($request);
    }
}
