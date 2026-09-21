<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use EstudioCandame\Seguimiento\Etapa;
use Psr\Http\Message\ResponseInterface;

/**
 * POST /admin/tramites/{id}/avanzar, pasando por routes.php de verdad.
 *
 * Se puede ir a CUALQUIER etapa del enum -- anteriores incluidas, porque la vista es un
 * loop, y salteando las que no aplican al tramite. Lo unico que se valida es que el
 * valor exista en el enum.
 */
final class AdminAvanzarTest extends BaseDeDatosTestCase
{
    public function testSinEtapaDestinoVaALaSiguienteDelFlujo(): void
    {
        $id = $this->crearTramite('Siguiente SAS');

        $response = $this->avanzar($id, []);
        self::assertSame(302, $response->getStatusCode());

        $tramite = $this->tramites->porId($id);
        self::assertSame(Etapa::PROCESANDO_DOCUMENTACION, $tramite?->etapaActual);
    }

    public function testConEtapaDestinoVaAEsaEtapaAunqueSeaAnterior(): void
    {
        $id = $this->crearTramite('Vuelta SAS');
        $this->tramites->avanzar($id, Etapa::VISTA_CONTESTADA, null, null);

        $this->avanzar($id, ['etapa' => Etapa::VISTA->value]);

        $tramite = $this->tramites->porId($id);
        self::assertSame(Etapa::VISTA, $tramite?->etapaActual, 'No se pudo volver a una etapa anterior.');
        self::assertCount(3, $this->tramites->eventos($id));
    }

    public function testUnaEtapaQueNoExisteEnElEnumEsRechazada(): void
    {
        $id = $this->crearTramite('Invalida SAS');

        $this->avanzar($id, ['etapa' => 'LIBROS']);

        $tramite = $this->tramites->porId($id);
        // LIBROS es una de las etapas viejas: ya no existe y no puede entrar a la base.
        self::assertSame(Etapa::REUNIENDO_DOCUMENTACION, $tramite?->etapaActual);
        self::assertCount(1, $this->tramites->eventos($id), 'Una etapa invalida no puede crear un evento.');
    }

    public function testSinTokenCsrfNoAvanza(): void
    {
        $id = $this->crearTramite('Csrf SAS');

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
        $id = $this->crearTramite('Detalle SAS');
        $this->tramites->avanzar($id, Etapa::VISTA_CONTESTADA, null, null);

        $response = $this->pedirAlPanel('GET', '/admin/tramites/' . $id);
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
        $id = $this->crearTramite('Sin vista SAS');
        $this->tramites->avanzar($id, Etapa::TRAMITE_INICIADO, null, null);

        $html = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id)->getBody();

        self::assertStringContainsString('Siguiente: Trámite con vista', $html);
        self::assertStringContainsString('Siguiente: Trámite terminado', $html);
    }

    /** @param array<string, string> $cuerpo */
    private function avanzar(int $id, array $cuerpo, bool $csrf = true): ResponseInterface
    {
        return $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/avanzar', $cuerpo, $csrf);
    }

}
