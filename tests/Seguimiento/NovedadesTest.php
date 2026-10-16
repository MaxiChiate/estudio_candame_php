<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use EstudioCandame\Seguimiento\Etapa;
use EstudioCandame\Seguimiento\LineaEtapas;
use EstudioCandame\Seguimiento\NovedadPublica;
use ReflectionClass;

/**
 * Novedades y notas internas sueltas, a nivel repositorio: no son cambios de etapa, y
 * lo interno no tiene camino hacia lo publico.
 */
final class NovedadesTest extends BaseDeDatosTestCase
{
    public function testPublicarUnaNovedadNoCambiaLaEtapaNiCreaEventos(): void
    {
        $id = $this->crearTramite('Novedad SRL');
        $this->tramites->avanzar($id, Etapa::VISTA, null, null);

        $this->tramites->publicarNovedad($id, Etapa::VISTA, 'Estamos preparando la respuesta.');

        $tramite = $this->tramites->porId($id);
        self::assertSame(Etapa::VISTA, $tramite?->etapaActual);
        self::assertCount(2, $this->tramites->eventos($id), 'Una novedad no puede crear un evento de etapa.');

        $novedades = $this->tramites->novedades($id);
        self::assertCount(1, $novedades);
        self::assertSame(Etapa::VISTA, $novedades[0]->etapa);
        self::assertSame('Estamos preparando la respuesta.', $novedades[0]->texto);
    }

    /**
     * El bug que origino todo esto: publicar en VISTA registrando "la misma etapa" hacia
     * que el cliente viera "2a vista" con una sola vista.
     */
    public function testUnaNovedadEnVistaNoSumaUnaVista(): void
    {
        $id = $this->crearTramite('Contador SRL');
        $this->tramites->avanzar($id, Etapa::VISTA, null, null);
        $this->tramites->publicarNovedad($id, Etapa::VISTA, 'Una.');
        $this->tramites->publicarNovedad($id, Etapa::VISTA, 'Dos.');

        $tramite = $this->tramites->porId($id);
        self::assertNotNull($tramite);
        $linea = LineaEtapas::construir(
            $tramite->flujo,
            $tramite->etapaActual,
            $this->tramites->eventosPublicos($id),
            $this->catalogo,
        );

        self::assertNull(array_column($linea, 'repeticion', 'valor')[Etapa::VISTA->value]);
    }

    public function testLaNotaInternaSueltaNoAparecePorNingunaConsultaPublica(): void
    {
        $id = $this->crearTramite('Interna SRL');
        $this->tramites->agregarNotaInterna($id, Etapa::REUNIENDO_DOCUMENTACION, 'INTERNO-SUELTA');

        self::assertSame([], $this->tramites->novedadesPublicas($id));
        foreach ($this->tramites->eventosPublicos($id) as $evento) {
            self::assertNotSame('INTERNO-SUELTA', $evento->notaPublica);
        }

        // En el panel si.
        self::assertSame('INTERNO-SUELTA', $this->tramites->notasInternas($id)[0]->texto);
        self::assertCount(1, $this->tramites->eventos($id), 'Una nota interna no puede crear un evento de etapa.');
    }

    /**
     * Garantia estructural, como con EventoPublico: la clase que llega al template
     * publico no tiene donde guardar una nota interna, ni la etapa (que en la lista de
     * novedades se leeria como un cambio de etapa).
     */
    public function testNovedadPublicaSoloTieneFechaYTexto(): void
    {
        $propiedades = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new ReflectionClass(NovedadPublica::class))->getProperties(),
        );

        self::assertSame(['publicadoEl', 'texto'], $propiedades);
    }

    public function testEditarYBorrarNovedadesYNotas(): void
    {
        $id = $this->crearTramite('Edicion SRL');
        $this->tramites->publicarNovedad($id, Etapa::REUNIENDO_DOCUMENTACION, 'Original');
        $this->tramites->agregarNotaInterna($id, Etapa::REUNIENDO_DOCUMENTACION, 'Interna original');
        $novedad = $this->tramites->novedades($id)[0];
        $nota = $this->tramites->notasInternas($id)[0];

        $fecha = new \DateTimeImmutable('2025-03-04 10:20:30');
        $this->tramites->editarNovedad($novedad->id, $fecha, 'Corregida');
        $this->tramites->editarNotaInterna($nota->id, $fecha, 'Interna corregida');

        self::assertSame('Corregida', $this->tramites->novedad($id, $novedad->id)?->texto);
        self::assertEquals($fecha, $this->tramites->novedad($id, $novedad->id)?->publicadoEl);
        self::assertSame('Interna corregida', $this->tramites->notaInterna($id, $nota->id)?->texto);

        $this->tramites->eliminarNovedad($novedad->id);
        $this->tramites->eliminarNotaInterna($nota->id);
        self::assertSame([], $this->tramites->novedades($id));
        self::assertSame([], $this->tramites->notasInternas($id));
    }

    public function testUnaNovedadDeOtroTramiteDaNull(): void
    {
        $a = $this->crearTramite('A SRL');
        $b = $this->crearTramite('B SRL');
        $this->tramites->publicarNovedad($b, Etapa::REUNIENDO_DOCUMENTACION, 'De B');
        $this->tramites->agregarNotaInterna($b, Etapa::REUNIENDO_DOCUMENTACION, 'De B');

        self::assertNull($this->tramites->novedad($a, $this->tramites->novedades($b)[0]->id));
        self::assertNull($this->tramites->notaInterna($a, $this->tramites->notasInternas($b)[0]->id));
    }

    public function testBorrarElTramiteSeLlevaNovedadesYNotas(): void
    {
        $id = $this->crearTramite('Cascada SRL');
        $this->tramites->publicarNovedad($id, Etapa::REUNIENDO_DOCUMENTACION, 'x');
        $this->tramites->agregarNotaInterna($id, Etapa::REUNIENDO_DOCUMENTACION, 'y');

        $this->tramites->eliminar($id);

        $pdo = $this->conexion->pdo();
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM tramite_novedad')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM tramite_nota_interna')->fetchColumn());
    }
}
