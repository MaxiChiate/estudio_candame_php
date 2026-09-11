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
 * Vista publica del portal, pasando por routes.php de verdad (mismo harness que
 * ConsultaConstitucionSmokeTest::dispatch()).
 */
final class PortalPublicoTest extends BaseDeDatosTestCase
{
    public function testTokenValidoDevuelve200YLaEtapaCorrecta(): void
    {
        $id = $this->tramites->crear('PRES-2026-0100', 'Cerro Alto SAS', 'MODELO');
        $this->tramites->avanzar($id, Etapa::PRESENTACION, null, null);
        $token = $this->accesos->emitir($id, 'cliente de prueba');

        $response = $this->get('/seguimiento/' . $token);
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('PRES-2026-0100', $html);

        // La etapa actual tiene que ser la que quedo, no otra de la linea.
        self::assertMatchesRegularExpression(
            '/is-actual.*?Presentaci&oacute;n|is-actual.*?Presentación/s',
            $html,
            'La etapa marcada como actual no es Presentación.',
        );
    }

    public function testTokenValidoTraeLasCabecerasDePrivacidad(): void
    {
        $id = $this->tramites->crear('PRES-2026-0101', 'Meridiano SAS', 'MODELO');
        $token = $this->accesos->emitir($id, 'cliente');

        $response = $this->get('/seguimiento/' . $token);

        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('ec_seg=' . $token, $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('HttpOnly', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('SameSite=Lax', $response->getHeaderLine('Set-Cookie'));
    }

    /**
     * El requisito central de privacidad de la ruta: inexistente, revocado y malformado
     * tienen que ser INDISTINGUIBLES. Se compara el cuerpo entero, no solo el status.
     */
    public function testInexistenteRevocadoYMalformadoDevuelvenLaMismaRespuesta(): void
    {
        $id = $this->tramites->crear('PRES-2026-0102', 'Cauce SAS', 'MODELO');
        $tokenRevocado = $this->accesos->emitir($id, 'cliente');
        $acceso = $this->accesos->resolver($tokenRevocado);
        self::assertNotNull($acceso);
        $this->accesos->revocar($acceso->accesoId);

        $respuestas = [
            'inexistente' => $this->get('/seguimiento/' . bin2hex(random_bytes(16))),
            'revocado' => $this->get('/seguimiento/' . $tokenRevocado),
            'malformado' => $this->get('/seguimiento/no-es-un-token-valido'),
        ];

        foreach ($respuestas as $caso => $response) {
            self::assertSame(404, $response->getStatusCode(), sprintf('El caso "%s" no dio 404.', $caso));
        }

        $cuerpos = array_map(static fn (ResponseInterface $r): string => (string) $r->getBody(), $respuestas);
        self::assertSame(
            $cuerpos['inexistente'],
            $cuerpos['revocado'],
            'Un token revocado se distingue de uno inexistente: la ruta filtra que el token existio.',
        );
        self::assertSame(
            $cuerpos['inexistente'],
            $cuerpos['malformado'],
            'Un token malformado se distingue de uno inexistente.',
        );
    }

    public function testLaNotaInternaNoLlegaALaVistaPublica(): void
    {
        $id = $this->tramites->crear('PRES-2026-0103', 'Rosas del Sur SAS', 'MODELO');
        $this->tramites->avanzar(
            $id,
            Etapa::FIRMA,
            'Se firmó el instrumento constitutivo.',
            'INTERNO: el socio 2 todavía no mandó el DNI, reclamar por teléfono.',
        );
        $token = $this->accesos->emitir($id, 'cliente');

        $html = (string) $this->get('/seguimiento/' . $token)->getBody();

        self::assertStringNotContainsString('INTERNO', $html);
        self::assertStringNotContainsString('reclamar por teléfono', $html);
        // La nota publica del mismo evento SI tiene que estar: si no, el test pasaria
        // aunque la vista no mostrara ninguna nota.
        self::assertStringContainsString('Se firmó el instrumento constitutivo.', $html);
    }

    public function testNingunDatoPersonalLlegaALaVistaPublica(): void
    {
        $id = $this->tramites->crear('PRES-2026-0104', 'Litoral Norte SAS', 'MODELO');
        $this->tramites->avanzar(
            $id,
            Etapa::INSCRIPCION,
            'La IGJ inscribió la sociedad.',
            'CUIT 30-71234567-8, DNI del presidente 28.456.789, domicilio Av. Siempreviva 742.',
        );
        $token = $this->accesos->emitir($id, 'contador Juan Pérez');

        $html = (string) $this->get('/seguimiento/' . $token)->getBody();

        foreach (['30-71234567-8', '28.456.789', 'Siempreviva 742'] as $dato) {
            self::assertStringNotContainsString($dato, $html, sprintf('Se filtró "%s" a la vista pública.', $dato));
        }

        // La etiqueta del acceso nombra a una persona y tampoco tiene que aparecer.
        self::assertStringNotContainsString('contador Juan Pérez', $html);

        // Mas general que buscar los valores del fixture: que no haya NADA con forma de
        // CUIT ni de DNI en la pagina, venga de donde venga.
        //
        // Ojo: no se puede buscar la palabra "CUIT" a secas, porque es el nombre de una
        // de las etapas y aparece legitimamente en la linea de avance. Lo que no puede
        // aparecer son numeros con esa forma.
        self::assertDoesNotMatchRegularExpression('/\b\d{2}-\d{8}-\d\b/', $html, 'Hay algo con forma de CUIT.');
        self::assertDoesNotMatchRegularExpression('/\b\d{1,2}\.\d{3}\.\d{3}\b/', $html, 'Hay algo con forma de DNI.');
    }

    public function testLaPaginaInformativaNoTieneCampoParaIngresarCodigo(): void
    {
        $response = $this->get('/seguimiento');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        // Un buscador publico haria enumerables los tramites: no debe haber ningun form
        // ni input en esta pagina.
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('<input', $html);
    }

    private function get(string $ruta): ResponseInterface
    {
        self::definirAppPath();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $config = self::configuracion();
        self::assertNotNull($config);

        $_ENV['SEGUIMIENTO_ENABLED'] = 'true';
        $_ENV['CONFIGURADOR_ENABLED'] = 'false';
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

        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', $ruta));
    }
}
