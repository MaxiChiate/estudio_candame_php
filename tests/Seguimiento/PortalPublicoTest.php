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
        $id = $this->tramites->crear('PRES-2026-0100', 'Cerro Alto SAS');
        $this->tramites->avanzar($id, Etapa::TRAMITE_INICIADO, null, null);
        $token = $this->accesos->emitir($id, 'cliente de prueba');

        $response = $this->get('/seguimiento/' . $token);
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('PRES-2026-0100', $html);

        // La etapa actual tiene que ser la que quedo, no otra de la linea.
        self::assertMatchesRegularExpression(
            '/is-actual.*?Trámite iniciado/s',
            $html,
            'La etapa marcada como actual no es Trámite iniciado.',
        );
    }

    public function testTokenValidoTraeLasCabecerasDePrivacidad(): void
    {
        $id = $this->tramites->crear('PRES-2026-0101', 'Meridiano SAS');
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
        $id = $this->tramites->crear('PRES-2026-0102', 'Cauce SAS');
        $tokenRevocado = $this->accesos->emitir($id, 'cliente');
        $acceso = $this->accesos->buscarPorToken($tokenRevocado);
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
        $id = $this->tramites->crear('PRES-2026-0103', 'Rosas del Sur SAS');
        $this->tramites->avanzar(
            $id,
            Etapa::HABILITADO_ESCRIBANIA,
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
        $id = $this->tramites->crear('PRES-2026-0104', 'Litoral Norte SAS');
        $this->tramites->avanzar(
            $id,
            Etapa::TRAMITE_INICIADO,
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
        // CUIT ni de DNI en la pagina, venga de donde venga. Se buscan las formas y no
        // la palabra "CUIT": lo que no puede filtrarse son los numeros.
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

    /**
     * El contador de accesos solo sube con un GET real a /seguimiento/{token}. La home
     * resuelve la misma cookie para la barra y no tiene que sumar nada.
     */
    public function testLaHomeConCookieValidaNoMueveElContador(): void
    {
        $id = $this->tramites->crear('PRES-2026-0105', 'Contador Home SAS');
        $token = $this->accesos->emitir($id, 'cliente');
        $accesoId = $this->accesos->buscarPorToken($token)?->accesoId;
        self::assertNotNull($accesoId);

        $home1 = $this->get('/', cookies: ['ec_seg' => $token]);
        $home2 = $this->get('/', cookies: ['ec_seg' => $token]);

        // Que la barra efectivamente se haya resuelto: si no, el test pasaria aunque la
        // home nunca tocara el token.
        self::assertStringContainsString('PRES-2026-0105', (string) $home1->getBody());
        self::assertStringContainsString('PRES-2026-0105', (string) $home2->getBody());
        self::assertSame(0, $this->accesos->porId($accesoId)?->accesos);

        $this->get('/seguimiento/' . $token);

        self::assertSame(1, $this->accesos->porId($accesoId)?->accesos);
    }

    public function testHeadYPrefetchNoCuentanComoVisita(): void
    {
        $id = $this->tramites->crear('PRES-2026-0106', 'Prefetch SAS');
        $token = $this->accesos->emitir($id, 'cliente');
        $accesoId = $this->accesos->buscarPorToken($token)?->accesoId;
        self::assertNotNull($accesoId);

        $head = $this->get('/seguimiento/' . $token, 'HEAD');
        // HEAD llega a verEstado (FastRoute lo despacha a la ruta GET): tiene que
        // responder, pero sin sumar.
        self::assertSame(200, $head->getStatusCode());

        $this->get('/seguimiento/' . $token, headers: ['Sec-Purpose' => 'prefetch']);
        $this->get('/seguimiento/' . $token, headers: ['Sec-Purpose' => 'prefetch;prerender']);
        $this->get('/seguimiento/' . $token, headers: ['Purpose' => 'prefetch']);

        self::assertSame(0, $this->accesos->porId($accesoId)?->accesos);
    }

    /**
     * El portal va con no-store, asi que recargar o ir atras/adelante es un GET real
     * cada vez. Varios seguidos son la misma visita.
     */
    public function testRecargarElPortalNoSumaDeNuevo(): void
    {
        $id = $this->tramites->crear('PRES-2026-0107', 'Recarga SAS');
        $token = $this->accesos->emitir($id, 'cliente');
        $accesoId = $this->accesos->buscarPorToken($token)?->accesoId;
        self::assertNotNull($accesoId);

        for ($i = 0; $i < 3; $i++) {
            self::assertSame(200, $this->get('/seguimiento/' . $token)->getStatusCode());
        }

        $registro = $this->accesos->porId($accesoId);
        self::assertSame(1, $registro?->accesos);
        self::assertNotNull($registro->ultimoAccesoEl);
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     */
    private function get(string $ruta, string $metodo = 'GET', array $cookies = [], array $headers = []): ResponseInterface
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

        $request = (new ServerRequestFactory())->createServerRequest($metodo, $ruta)->withCookieParams($cookies);
        foreach ($headers as $nombre => $valor) {
            $request = $request->withHeader($nombre, $valor);
        }

        return $app->handle($request);
    }
}
