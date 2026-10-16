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

    public function testElEditorDelHistorialMuestraNovedadesYNotas(): void
    {
        $id = $this->crearTramite('Editor SRL');
        $this->tramites->publicarNovedad($id, Etapa::REUNIENDO_DOCUMENTACION, 'NOVEDAD-EDITABLE');
        $this->tramites->agregarNotaInterna($id, Etapa::REUNIENDO_DOCUMENTACION, 'INTERNA-EDITABLE');

        $html = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id . '/historial')->getBody();

        self::assertStringContainsString('NOVEDAD-EDITABLE', $html);
        self::assertStringContainsString('INTERNA-EDITABLE', $html);
        self::assertStringContainsString('/historial/novedades/' . $this->tramites->novedades($id)[0]->id . '/eliminar', $html);
        self::assertStringContainsString('/historial/notas-internas/' . $this->tramites->notasInternas($id)[0]->id . '/eliminar', $html);
    }

    public function testEditarUnaNovedadCambiaDiaYTextoYConservaLaHora(): void
    {
        $id = $this->crearTramite('Editar SRL');
        $this->tramites->publicarNovedad($id, Etapa::REUNIENDO_DOCUMENTACION, 'Original');
        $novedad = $this->tramites->novedades($id)[0];

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/novedades/' . $novedad->id, [
            'fecha' => '2025-05-02',
            'texto' => 'Corregida',
        ], csrf: true);

        $editada = $this->tramites->novedad($id, $novedad->id);
        self::assertSame('Corregida', $editada?->texto);
        self::assertSame('2025-05-02 ' . $novedad->publicadoEl->format('H:i:s'), $editada?->publicadoEl->format('Y-m-d H:i:s'));
        self::assertCount(1, $this->tramites->eventos($id), 'Editar una novedad no toca los eventos.');
    }

    public function testUnaNovedadNoSePuedeDejarVacia(): void
    {
        $id = $this->crearTramite('Vaciar SRL');
        $this->tramites->publicarNovedad($id, Etapa::REUNIENDO_DOCUMENTACION, 'Original');
        $novedad = $this->tramites->novedades($id)[0];

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/novedades/' . $novedad->id, [
            'fecha' => '2025-05-02',
            'texto' => '',
        ], csrf: true);

        self::assertSame('Original', $this->tramites->novedad($id, $novedad->id)?->texto);
        self::assertSame('error', $_SESSION['seguimientoFlash']['tipo'] ?? null);
    }

    public function testEditarYBorrarUnaNotaInterna(): void
    {
        $id = $this->crearTramite('Interna SRL');
        $this->tramites->agregarNotaInterna($id, Etapa::REUNIENDO_DOCUMENTACION, 'Original');
        $nota = $this->tramites->notasInternas($id)[0];

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/notas-internas/' . $nota->id, [
            'fecha' => '2025-05-02',
            'texto' => 'Corregida',
        ], csrf: true);
        self::assertSame('Corregida', $this->tramites->notaInterna($id, $nota->id)?->texto);

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/notas-internas/' . $nota->id . '/eliminar', [], csrf: true);
        self::assertSame([], $this->tramites->notasInternas($id));
    }

    public function testBorrarUnaNovedad(): void
    {
        $id = $this->crearTramite('Borrar SRL');
        $this->tramites->publicarNovedad($id, Etapa::REUNIENDO_DOCUMENTACION, 'Se va');
        $novedad = $this->tramites->novedades($id)[0];

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/novedades/' . $novedad->id . '/eliminar', [], csrf: true);

        self::assertSame([], $this->tramites->novedades($id));
        self::assertSame(Etapa::REUNIENDO_DOCUMENTACION, $this->tramites->porId($id)?->etapaActual);
    }

    /** La URL trae tramite y novedad: una novedad de OTRO tramite no se toca desde ahi. */
    public function testUnaNovedadDeOtroTramiteNoSeTocaDesdeEstaUrl(): void
    {
        $a = $this->crearTramite('A SRL');
        $b = $this->crearTramite('B SRL');
        $this->tramites->publicarNovedad($b, Etapa::REUNIENDO_DOCUMENTACION, 'De B');
        $this->tramites->agregarNotaInterna($b, Etapa::REUNIENDO_DOCUMENTACION, 'De B');
        $novedad = $this->tramites->novedades($b)[0];
        $nota = $this->tramites->notasInternas($b)[0];

        $this->pedirAlPanel('POST', '/admin/tramites/' . $a . '/historial/novedades/' . $novedad->id, ['fecha' => '2025-01-01', 'texto' => 'pisada'], csrf: true);
        $this->pedirAlPanel('POST', '/admin/tramites/' . $a . '/historial/novedades/' . $novedad->id . '/eliminar', [], csrf: true);
        $this->pedirAlPanel('POST', '/admin/tramites/' . $a . '/historial/notas-internas/' . $nota->id . '/eliminar', [], csrf: true);

        self::assertSame('De B', $this->tramites->novedad($b, $novedad->id)?->texto);
        self::assertNotNull($this->tramites->notaInterna($b, $nota->id));
    }
}
