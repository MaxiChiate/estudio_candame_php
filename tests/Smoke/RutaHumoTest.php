<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Smoke;

use EstudioCandame\Controller\HumoController;
use EstudioCandame\Pruebas\EnvioPruebaService;
use EstudioCandame\Service\ConsultaConstitucionMailer;
use EstudioCandame\Service\FichaConstitucionXlsxBuilder;
use EstudioCandame\Support\AntiAbuso\RateLimiter;
use EstudioCandame\Support\RelojSistema;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * 404 en los cuatro motivos del spec (token ausente/incorrecto, SMOKE_TEST_TOKEN vacio,
 * SMOKE_TEST_TO vacio -- CONFIGURADOR_ENABLED apagado ya lo cubre
 * ConsultaConstitucionSmokeTest). Los casos de 404 pasan por routes.php de verdad (mismo
 * harness que ConsultaConstitucionSmokeTest::dispatch()) porque el chequeo de token
 * corta antes de tocar el mailer -- no hay riesgo de red. El caso 200 arma
 * HumoController directo con un mailer doblado, para no depender de SMTP real, y
 * confirma que ?to= en la query no cambia el destinatario.
 */
final class RutaHumoTest extends TestCase
{
    public function test404SinToken(): void
    {
        $response = $this->dispatchViaRoutes('token-correcto', 'prueba@example.com', null);

        self::assertSame(404, $response->getStatusCode());
    }

    public function test404ConTokenIncorrecto(): void
    {
        $response = $this->dispatchViaRoutes('token-correcto', 'prueba@example.com', 'token-incorrecto');

        self::assertSame(404, $response->getStatusCode());
    }

    public function test404ConSmokeTestTokenVacio(): void
    {
        $response = $this->dispatchViaRoutes('', 'prueba@example.com', 'cualquier-token');

        self::assertSame(404, $response->getStatusCode());
    }

    public function test404ConSmokeTestToVacio(): void
    {
        $response = $this->dispatchViaRoutes('token-correcto', '', 'token-correcto');

        self::assertSame(404, $response->getStatusCode());
    }

    public function test200ConTokenCorrectoYElDestinatarioNoSeAlteraPorQuery(): void
    {
        $capturados = [];
        $mailer = new ConsultaConstitucionMailer(
            'smtp.test',
            587,
            'web@estudiocandame.com.ar',
            'password',
            true,
            true,
            'info@estudiocandame.com.ar',
            function (PHPMailer $mailer) use (&$capturados): void {
                $capturados[] = $mailer;
            },
            'prueba@example.com',
        );
        $envioPrueba = new EnvioPruebaService(new FichaConstitucionXlsxBuilder(), $mailer, new RelojSistema());
        $rateLimiter = new RateLimiter(
            sys_get_temp_dir() . '/rate-limit-humo-test-' . uniqid() . '.json',
            60,
            1,
        );

        $humoController = new HumoController(
            $envioPrueba,
            $rateLimiter,
            'token-correcto',
            'smtp.test',
            587,
            'web@estudiocandame.com.ar',
            'prueba@example.com',
        );

        $app = AppFactory::create();
        $app->get('/tramites/constitucion/_humo', [$humoController, 'humo']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/tramites/constitucion/_humo?token=token-correcto&to=attacker@evil.com');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty($capturados);
        foreach ($capturados as $mailerCapturado) {
            self::assertSame(['prueba@example.com'], array_column($mailerCapturado->getToAddresses(), 0));
            self::assertNotContains('attacker@evil.com', array_column($mailerCapturado->getToAddresses(), 0));
        }
    }

    private function dispatchViaRoutes(string $smokeTestToken, string $smokeTestTo, ?string $tokenQuery): ResponseInterface
    {
        if (!defined('APP_PATH')) {
            define('APP_PATH', dirname(__DIR__, 2) . '/app');
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $_ENV['CONFIGURADOR_ENABLED'] = 'true';
        $_ENV['SAS_SMVM_API_URL'] = '';
        $_ENV['SMVM_FALLBACK_VALOR'] = '376600';
        $_ENV['SMVM_FALLBACK_FECHA'] = '2026-08-01';
        $_ENV['SAS_CAPITAL_MULTIPLO_SMVM'] = '2';
        $_ENV['MAIL_HOST'] = 'smtp.test';
        $_ENV['MAIL_USERNAME'] = 'web@test.com';
        $_ENV['MAIL_PASSWORD'] = 'x';
        $_ENV['SMOKE_TEST_TOKEN'] = $smokeTestToken;
        $_ENV['SMOKE_TEST_TO'] = $smokeTestTo;

        $app = AppFactory::create();
        $twig = Twig::create(APP_PATH . '/templates', ['cache' => false, 'charset' => 'utf-8']);
        $twig->getEnvironment()->addGlobal('basePath', '');
        $twig->getEnvironment()->addGlobal('configuradorEnabled', true);
        $app->add(TwigMiddleware::create($app, $twig));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);

        (require APP_PATH . '/config/routes.php')($app, $twig);

        $uri = '/tramites/constitucion/_humo' . ($tokenQuery !== null ? '?token=' . urlencode($tokenQuery) : '');
        $request = (new ServerRequestFactory())->createServerRequest('GET', $uri);

        return $app->handle($request);
    }
}
