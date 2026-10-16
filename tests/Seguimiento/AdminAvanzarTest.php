<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use EstudioCandame\Seguimiento\Etapa;
use EstudioCandame\Seguimiento\Flujo;
use Psr\Http\Message\ResponseInterface;

/**
 * POST /admin/tramites/{id}/avanzar, pasando por routes.php de verdad.
 *
 * Se puede ir a cualquier etapa DEL FLUJO del tramite -- anteriores incluidas, porque la
 * vista es un loop. No a la misma (eso es "Publicar novedad") ni a una de otro flujo.
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

    /**
     * "Registrar" la etapa en curso era la unica forma de publicar una nota sin avanzar,
     * y le mostraba al cliente un cambio de etapa que no existio.
     */
    public function testLaMismaEtapaActualEsRechazada(): void
    {
        $id = $this->crearTramite('Misma SRL');
        $this->tramites->avanzar($id, Etapa::VISTA, null, null);

        $this->avanzar($id, ['etapa' => Etapa::VISTA->value, 'nota_publica' => 'Hola']);

        self::assertSame(Etapa::VISTA, $this->tramites->porId($id)?->etapaActual);
        self::assertCount(2, $this->tramites->eventos($id), 'Ir a la misma etapa no puede crear un evento.');
        self::assertSame([], $this->tramites->novedades($id));
        self::assertSame('error', $_SESSION['seguimientoFlash']['tipo'] ?? null);
        self::assertStringContainsString('Publicar novedad', $_SESSION['seguimientoFlash']['mensaje'] ?? '');
    }

    public function testUnaEtapaDeOtroFlujoEsRechazada(): void
    {
        // TRAMITE_SUBIDO_TAD es de la SAS; Constitucion SRL/SA no lo recorre.
        $id = $this->crearTramite('Otro flujo SRL');
        self::assertFalse($this->catalogo->pertenece(Flujo::CONSTITUCION_SRL_SA, Etapa::TRAMITE_SUBIDO_TAD));

        $this->avanzar($id, ['etapa' => Etapa::TRAMITE_SUBIDO_TAD->value]);

        self::assertSame(Etapa::REUNIENDO_DOCUMENTACION, $this->tramites->porId($id)?->etapaActual);
        self::assertCount(1, $this->tramites->eventos($id));
        self::assertSame('error', $_SESSION['seguimientoFlash']['tipo'] ?? null);
    }

    public function testElMensajeViajaConElAvanceYLaNotaInternaNo(): void
    {
        $id = $this->crearTramite('Mensaje SRL');

        $this->avanzar($id, ['nota_publica' => 'Recibimos todo.', 'nota_interna' => 'no va acá']);

        $evento = $this->tramites->eventos($id)[0];
        self::assertSame(Etapa::PROCESANDO_DOCUMENTACION, $evento->etapa);
        self::assertSame('Recibimos todo.', $evento->notaPublica);
        self::assertNull($evento->notaInterna, 'La nota interna tiene su propio bloque, no viaja con el avance.');
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
