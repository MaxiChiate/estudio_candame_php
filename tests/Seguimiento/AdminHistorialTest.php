<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use EstudioCandame\Seguimiento\Etapa;

/**
 * Editor del historial (/admin/tramites/{id}/historial) y borrado de tramites, pasando
 * por routes.php de verdad.
 *
 * Lo que importa: corregir el historial NUNCA mueve etapa_actual, un evento solo se
 * toca desde la URL de su propio tramite, y borrar un tramite se lleva sus eventos y
 * enlaces.
 */
final class AdminHistorialTest extends BaseDeDatosTestCase
{
    public function testElDetalleLlevaAlEditorYOfreceEliminar(): void
    {
        $id = $this->crearTramite('Detalle SRL');

        $html = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id)->getBody();

        self::assertStringContainsString('/admin/tramites/' . $id . '/historial', $html);
        self::assertStringContainsString('/admin/tramites/' . $id . '/eliminar', $html);
    }

    public function testElEditorMuestraCadaEventoConSuFechaEditable(): void
    {
        $id = $this->crearTramite('Editor SRL');
        $evento = $this->tramites->eventos($id)[0];

        $response = $this->pedirAlPanel('GET', '/admin/tramites/' . $id . '/historial');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('value="' . $evento->ocurridoEl->format('Y-m-d') . '"', $html);
        self::assertStringNotContainsString($evento->ocurridoEl->format('H:i'), $html);
        self::assertStringContainsString('/historial/' . $evento->id . '/eliminar', $html);
    }

    public function testEditarCambiaFechaYNotasSinMoverLaEtapaActual(): void
    {
        $id = $this->crearTramite('Fecha SRL');
        $this->tramites->avanzar($id, Etapa::PROCESANDO_DOCUMENTACION, null, null);
        $inicial = $this->eventoDe($id, Etapa::REUNIENDO_DOCUMENTACION);
        $hora = $this->tramites->evento($id, $inicial)?->ocurridoEl->format('H:i:s');

        $response = $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $inicial, [
            'ocurrido_el' => '2025-09-15',
            'nota_publica' => 'Arrancamos.',
            'nota_interna' => '',
        ], csrf: true);

        self::assertSame(302, $response->getStatusCode());
        $editado = $this->tramites->evento($id, $inicial);
        self::assertSame('2025-09-15', $editado?->ocurridoEl->format('Y-m-d'));
        self::assertSame($hora, $editado?->ocurridoEl->format('H:i:s'), 'Cambiar el dia no toca la hora guardada');
        self::assertSame('Arrancamos.', $editado?->notaPublica);
        self::assertNull($editado?->notaInterna);
        self::assertSame(Etapa::PROCESANDO_DOCUMENTACION, $this->tramites->porId($id)?->etapaActual);
    }

    public function testUnaFechaInexistenteSeRechaza(): void
    {
        $id = $this->crearTramite('Febrero SRL');
        $evento = $this->tramites->eventos($id)[0];

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $evento->id, [
            'ocurrido_el' => '2025-02-31',
        ], csrf: true);

        self::assertEquals($evento->ocurridoEl, $this->tramites->evento($id, $evento->id)?->ocurridoEl);
    }

    public function testBorrarUnEventoNoMueveLaEtapaActual(): void
    {
        $id = $this->crearTramite('Borrar SRL');
        $this->tramites->avanzar($id, Etapa::PROCESANDO_DOCUMENTACION, null, null);
        $actual = $this->eventoDe($id, Etapa::PROCESANDO_DOCUMENTACION);

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $actual . '/eliminar', csrf: true);

        self::assertNull($this->tramites->evento($id, $actual));
        self::assertCount(1, $this->tramites->eventos($id));
        self::assertSame(Etapa::PROCESANDO_DOCUMENTACION, $this->tramites->porId($id)?->etapaActual);
    }

    /** El id del evento viene en la URL: uno de otro tramite no se puede tocar desde aca. */
    public function testUnEventoDeOtroTramiteNoSeTocaDesdeEstaUrl(): void
    {
        $id = $this->crearTramite('Propio SRL');
        $otro = $this->crearTramite('Ajeno SRL');
        $ajeno = $this->tramites->eventos($otro)[0];

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $ajeno->id . '/eliminar', csrf: true);
        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $ajeno->id, [
            'ocurrido_el' => '2020-01-01',
        ], csrf: true);

        self::assertEquals($ajeno, $this->tramites->evento($otro, $ajeno->id));
    }

    public function testAgregarUnaEtapaConFechaPasadaNoMueveLaEtapaActual(): void
    {
        $id = $this->crearTramite('Agregar SRL');

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial', [
            'etapa' => Etapa::TRAMITE_INICIADO->value,
            'ocurrido_el' => '2025-10-01',
        ], csrf: true);

        $agregado = $this->eventoDe($id, Etapa::TRAMITE_INICIADO);
        self::assertSame('2025-10-01', $this->tramites->evento($id, $agregado)?->ocurridoEl->format('Y-m-d'));
        self::assertSame(Etapa::REUNIENDO_DOCUMENTACION, $this->tramites->porId($id)?->etapaActual);
    }

    public function testAgregarRechazaEtapaInexistente(): void
    {
        $id = $this->crearTramite('Invalida SRL');

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial', [
            'etapa' => 'LIBROS',
            'ocurrido_el' => '2025-10-01',
        ], csrf: true);

        self::assertCount(1, $this->tramites->eventos($id));
    }

    public function testEliminarBorraElTramiteConSusEventosYEnlaces(): void
    {
        $id = $this->crearTramite('Eliminar SRL');
        $this->accesos->emitir($id, 'cliente');

        $response = $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/eliminar', csrf: true);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/tramites', $response->getHeaderLine('Location'));
        self::assertNull($this->tramites->porId($id));
        self::assertSame([], $this->tramites->eventos($id));
        self::assertSame([], $this->accesos->porTramite($id));
    }

    public function testSinTokenCsrfNoSeBorraNada(): void
    {
        $id = $this->crearTramite('Csrf SRL');
        $evento = $this->tramites->eventos($id)[0];

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $evento->id . '/eliminar');
        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/eliminar');

        self::assertNotNull($this->tramites->porId($id));
        self::assertCount(1, $this->tramites->eventos($id));
    }

    private function eventoDe(int $tramiteId, Etapa $etapa): int
    {
        foreach ($this->tramites->eventos($tramiteId) as $evento) {
            if ($evento->etapa === $etapa) {
                return $evento->id;
            }
        }

        self::fail('No hay evento de ' . $etapa->value);
    }
}
