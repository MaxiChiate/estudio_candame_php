<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use EstudioCandame\Seguimiento\Etapa;
use EstudioCandame\Support\AntiAbuso\CsrfToken;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

/**
 * POST /admin/tramites/{id}/avanzar, pasando por routes.php de verdad.
 *
 * Se puede ir a CUALQUIER etapa del enum -- anteriores incluidas, porque la vista es un
 * loop, y salteando las que no aplican al tramite. Lo unico que se valida es que el
 * valor exista en el enum.
 */
final class AdminAvanzarTest extends BaseDeDatosTestCase
{
    public function testSinEtapaDestinoVaALaSiguienteDelEnum(): void
    {
        $id = $this->tramites->crear('PRES-2026-0500', 'Siguiente SAS');

        $response = $this->avanzar($id, []);
        self::assertSame(302, $response->getStatusCode());

        $tramite = $this->tramites->porId($id);
        self::assertSame(Etapa::PROCESANDO_DOCUMENTACION, $tramite?->etapaActual);
    }

    public function testConEtapaDestinoVaAEsaEtapaAunqueSeaAnterior(): void
    {
        $id = $this->tramites->crear('PRES-2026-0501', 'Vuelta SAS');
        $this->tramites->avanzar($id, Etapa::VISTA_CONTESTADA, null, null);

        $this->avanzar($id, ['etapa' => Etapa::VISTA->value]);

        $tramite = $this->tramites->porId($id);
        self::assertSame(Etapa::VISTA, $tramite?->etapaActual, 'No se pudo volver a una etapa anterior.');
        self::assertCount(3, $this->tramites->eventos($id));
    }

    public function testUnaEtapaQueNoExisteEnElEnumEsRechazada(): void
    {
        $id = $this->tramites->crear('PRES-2026-0502', 'Invalida SAS');

        $this->avanzar($id, ['etapa' => 'LIBROS']);

        $tramite = $this->tramites->porId($id);
        // LIBROS es una de las etapas viejas: ya no existe y no puede entrar a la base.
        self::assertSame(Etapa::REUNIENDO_DOCUMENTACION, $tramite?->etapaActual);
        self::assertCount(1, $this->tramites->eventos($id), 'Una etapa invalida no puede crear un evento.');
    }

    public function testSinTokenCsrfNoAvanza(): void
    {
        $id = $this->tramites->crear('PRES-2026-0503', 'Csrf SAS');

        $this->avanzar($id, ['etapa' => Etapa::TERMINADO->value], csrf: false);

        $tramite = $this->tramites->porId($id);
        self::assertSame(Etapa::REUNIENDO_DOCUMENTACION, $tramite?->etapaActual);
    }

    /**
     * El detalle ofrece el proximo paso de un click. En VISTA_CONTESTADA son dos, porque
     * el inspector puede despachar otra vista en vez de dar por terminado el tramite.
     */
    public function testElDetalleOfreceLosProximosPasosYElEscape(): void
    {
        $id = $this->tramites->crear('PRES-2026-0504', 'Detalle SAS');
        $this->tramites->avanzar($id, Etapa::VISTA_CONTESTADA, null, null);

        $response = $this->pedir('GET', '/admin/tramites/' . $id);
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Siguiente: Trámite con vista', $html);
        self::assertStringContainsString('Siguiente: Trámite terminado', $html);

        // El escape para saltear o volver: el select con las 12 etapas.
        foreach (Etapa::cases() as $etapa) {
            self::assertStringContainsString('value="' . $etapa->value . '"', $html);
        }

        // La observacion es para lo de fuera de IGJ; la vista tiene etapas propias.
        self::assertStringContainsString('Vista contestada', $html);
    }

    /**
     * Desde Trámite iniciado el trámite puede terminar sin ninguna vista: ofrecer sólo
     * "Trámite con vista" daría por hecho que el inspector va a correr una.
     */
    public function testEnTramiteIniciadoOfreceVistaOTerminado(): void
    {
        $id = $this->tramites->crear('PRES-2026-0505', 'Sin vista SAS');
        $this->tramites->avanzar($id, Etapa::TRAMITE_INICIADO, null, null);

        $html = (string) $this->pedir('GET', '/admin/tramites/' . $id)->getBody();

        self::assertStringContainsString('Siguiente: Trámite con vista', $html);
        self::assertStringContainsString('Siguiente: Trámite terminado', $html);
    }

    /** @param array<string, string> $cuerpo */
    private function avanzar(int $id, array $cuerpo, bool $csrf = true): ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        if ($csrf) {
            $cuerpo['_csrf'] = CsrfToken::generar(CsrfToken::CLAVE_ADMIN_SEGUIMIENTO);
        }

        return $this->pedir('POST', '/admin/tramites/' . $id . '/avanzar', $cuerpo);
    }

    /** @param array<string, string> $cuerpo */
    private function pedir(string $metodo, string $ruta, array $cuerpo = []): ResponseInterface
    {
        self::definirAppPath();

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

        $request = (new ServerRequestFactory())
            ->createServerRequest($metodo, $ruta, [
                'PHP_AUTH_USER' => 'candame',
                'PHP_AUTH_PW' => 'la-correcta',
            ])
            ->withParsedBody($cuerpo);

        return $app->handle($request);
    }
}
