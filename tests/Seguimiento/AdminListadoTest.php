<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use EstudioCandame\Seguimiento\Etapa;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

/**
 * Listado del panel: cada fila tiene que llevar al detalle con links reales, y el form
 * de "avanzar" no puede quedar anidado dentro de ninguno.
 */
final class AdminListadoTest extends BaseDeDatosTestCase
{
    public function testLasCeldasDeDatosLinkeanAlDetalleYElFormDeAvanzarQuedaAfuera(): void
    {
        $id = $this->tramites->crear('PRES-2026-0400', 'Listado SAS', 'MODELO');
        $this->tramites->avanzar($id, Etapa::FIRMA, null, null);

        $response = $this->listado();
        self::assertSame(200, $response->getStatusCode());

        $dom = new \DOMDocument();
        @$dom->loadHTML((string) $response->getBody());
        $xpath = new \DOMXPath($dom);

        $fila = $xpath->query('//tbody/tr')->item(0);
        self::assertNotNull($fila);

        $celdas = $xpath->query('td', $fila);
        $destino = '/admin/tramites/' . $id;
        // Todas menos la ultima (Avanzar) son un link al detalle.
        for ($i = 0; $i < $celdas->length - 1; $i++) {
            $link = $xpath->query('a', $celdas->item($i))->item(0);
            self::assertInstanceOf(\DOMElement::class, $link, sprintf('La celda %d no tiene link.', $i));
            self::assertSame($destino, $link->getAttribute('href'));
        }

        self::assertSame(0, $xpath->query('.//a//form | .//a//button', $fila)->length, 'Hay un form/boton dentro de un link.');
        self::assertSame(1, $xpath->query('td[last()]//form', $fila)->length, 'El form de avanzar no esta en la celda de accion.');
        self::assertSame(0, $xpath->query('td[last()]//a', $fila)->length, 'La celda de accion no puede navegar.');
    }

    private function listado(): ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $config = self::configuracion();
        self::assertNotNull($config);

        $_ENV['SEGUIMIENTO_ENABLED'] = 'true';
        $_ENV['CONFIGURADOR_ENABLED'] = 'false';
        $_ENV['ADMIN_USER'] = 'candame';
        $_ENV['ADMIN_PASS_HASH'] = password_hash('la-correcta', PASSWORD_DEFAULT);
        $_ENV['DB_HOST'] = $config['host'];
        $_ENV['DB_NAME'] = $config['name'];
        $_ENV['DB_USER'] = $config['user'];
        $_ENV['DB_PASS'] = $config['pass'];
        $_ENV['DB_CHARSET'] = $config['charset'];

        $app = AppFactory::create();
        $twig = Twig::create(APP_PATH . '/templates', ['cache' => false, 'charset' => 'utf-8']);
        $twig->getEnvironment()->addGlobal('basePath', '');
        $twig->getEnvironment()->addGlobal('configuradorEnabled', false);
        $app->add(TwigMiddleware::create($app, $twig));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);

        (require APP_PATH . '/config/routes.php')($app, $twig);

        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/admin/tramites', [
            'PHP_AUTH_USER' => 'candame',
            'PHP_AUTH_PW' => 'la-correcta',
        ]));
    }
}
