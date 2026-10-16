<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use EstudioCandame\Seguimiento\Etapa;

/**
 * "Publicar novedad" y "Nota interna" del detalle, pasando por routes.php de verdad.
 * Ninguno de los dos cambia la etapa.
 */
final class AdminNovedadesTest extends BaseDeDatosTestCase
{
    public function testPublicarNovedadNoCambiaLaEtapa(): void
    {
        $id = $this->crearTramite('Publicar SRL');
        $this->tramites->avanzar($id, Etapa::VISTA, null, null);

        $response = $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/novedades', ['texto' => 'Ya contestamos.'], csrf: true);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(Etapa::VISTA, $this->tramites->porId($id)?->etapaActual);
        self::assertCount(2, $this->tramites->eventos($id), 'Publicar no puede crear un evento de etapa.');
        self::assertSame('Ya contestamos.', $this->tramites->novedades($id)[0]->texto);
        self::assertSame(Etapa::VISTA, $this->tramites->novedades($id)[0]->etapa, 'Queda la etapa vigente como referencia.');

        $referencia = $this->tramites->porId($id)?->referencia;
        self::assertSame(['mensaje' => 'Novedad publicada en ' . $referencia . '.', 'tipo' => 'ok'], $_SESSION['seguimientoFlash'] ?? null);
    }

    public function testUnaNovedadVaciaOLargaSeRechaza(): void
    {
        $id = $this->crearTramite('Vacia SRL');

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/novedades', ['texto' => '   '], csrf: true);
        self::assertSame('error', $_SESSION['seguimientoFlash']['tipo'] ?? null);

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/novedades', ['texto' => str_repeat('ñ', 5001)], csrf: true);
        self::assertSame('error', $_SESSION['seguimientoFlash']['tipo'] ?? null);

        self::assertSame([], $this->tramites->novedades($id));
    }

    public function testSinCsrfNoSePublica(): void
    {
        $id = $this->crearTramite('Csrf SRL');

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/novedades', ['texto' => 'x']);
        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/notas-internas', ['texto' => 'x']);

        self::assertSame([], $this->tramites->novedades($id));
        self::assertSame([], $this->tramites->notasInternas($id));
    }

    /**
     * Con el flag apagado el checkbox no se dibuja, y si el campo llega igual en el POST
     * (armado a mano) se ignora: el flash es exactamente el de una novedad sin aviso.
     */
    public function testConElFlagApagadoSeIgnoraElAvisoPorMail(): void
    {
        $id = $this->crearTramite('Flag off SRL');

        $html = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id)->getBody();
        self::assertStringNotContainsString('avisar_cliente', $html);

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/novedades', ['texto' => 'Hola', 'avisar_cliente' => '1'], csrf: true);

        $referencia = $this->tramites->porId($id)?->referencia;
        self::assertSame('Novedad publicada en ' . $referencia . '.', $_SESSION['seguimientoFlash']['mensaje'] ?? null);
        self::assertCount(1, $this->tramites->novedades($id), 'La novedad se publica igual.');
    }

    public function testConElFlagPrendidoSeOfreceElAvisoQueTodaviaEsUnStub(): void
    {
        $id = $this->crearTramite('Flag on SRL');

        $html = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id, mailCliente: true)->getBody();
        self::assertStringContainsString('name="avisar_cliente"', $html);
        self::assertStringNotContainsString('name="avisar_cliente" value="1" checked', $html, 'Arranca destildado.');

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/novedades', ['texto' => 'Hola', 'avisar_cliente' => '1'], csrf: true, mailCliente: true);

        self::assertStringContainsString('no se envió ningún mail', $_SESSION['seguimientoFlash']['mensaje'] ?? '');
        self::assertCount(1, $this->tramites->novedades($id));
    }

    public function testLaNotaInternaSeGuardaSinCambiarEtapaNiPublicar(): void
    {
        $id = $this->crearTramite('Nota SRL');

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/notas-internas', ['texto' => 'Llamar a la escribana.'], csrf: true);

        self::assertSame(Etapa::REUNIENDO_DOCUMENTACION, $this->tramites->porId($id)?->etapaActual);
        self::assertCount(1, $this->tramites->eventos($id));
        self::assertSame([], $this->tramites->novedades($id));
        self::assertSame('Llamar a la escribana.', $this->tramites->notasInternas($id)[0]->texto);
        self::assertSame('ok', $_SESSION['seguimientoFlash']['tipo'] ?? null);
    }

    public function testElDetalleMuestraTodoEnElHistorialYLaInternaMarcada(): void
    {
        $id = $this->crearTramite('Historial SRL');
        $this->tramites->publicarNovedad($id, Etapa::REUNIENDO_DOCUMENTACION, "Línea 1\n<b>Línea 2</b>");
        $this->tramites->agregarNotaInterna($id, Etapa::REUNIENDO_DOCUMENTACION, 'Solo para el estudio');

        $html = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id)->getBody();

        self::assertStringContainsString('Novedad para el cliente', $html);
        self::assertStringContainsString('class="is-interna"', $html);
        self::assertStringContainsString('Solo para el estudio', $html);
        // Escapa y despues nl2br: el HTML del texto no se interpreta, el salto si.
        self::assertStringContainsString('Línea 1<br />' . "\n" . '&lt;b&gt;Línea 2&lt;/b&gt;', $html);
    }

    public function testVerComoClienteUsaUnEnlaceVigente(): void
    {
        $id = $this->crearTramite('Ver SRL');

        $sinEnlace = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id)->getBody();
        self::assertStringNotContainsString('Ver como cliente', $sinEnlace);
        self::assertStringContainsString('emit&iacute; un enlace', $sinEnlace);

        $revocado = $this->accesos->emitir($id, 'viejo');
        $this->accesos->revocar($this->accesos->porTramite($id)[0]->id);
        $vigente = $this->accesos->emitir($id, 'cliente');

        $html = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id)->getBody();
        self::assertStringContainsString('Ver como cliente', $html);
        self::assertMatchesRegularExpression('#href="[^"]*/seguimiento/' . $vigente . '"[^>]*>Ver como cliente#', $html);
        self::assertDoesNotMatchRegularExpression('#href="[^"]*/seguimiento/' . $revocado . '"[^>]*>Ver como cliente#', $html);
    }
}
