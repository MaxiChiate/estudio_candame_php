<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use EstudioCandame\Seguimiento\Etapa;
use EstudioCandame\Seguimiento\Flujo;
use EstudioCandame\Seguimiento\LineaEtapas;

/**
 * Consistencia entre etapa_actual y el historial de eventos: son dos representaciones
 * del mismo hecho y no pueden divergir.
 */
final class AvanceEtapaTest extends BaseDeDatosTestCase
{
    public function testElAltaDejaElTramiteEnLaEtapaInicialConSuEvento(): void
    {
        $id = $this->crearTramite('Alta SAS');

        $tramite = $this->tramites->porId($id);
        self::assertNotNull($tramite);
        self::assertSame(Etapa::REUNIENDO_DOCUMENTACION, $tramite->etapaActual);
        self::assertFalse($tramite->observado);

        $eventos = $this->tramites->eventos($id);
        self::assertCount(1, $eventos, 'El alta tiene que dejar sentado el evento inicial.');
        self::assertSame(Etapa::REUNIENDO_DOCUMENTACION, $eventos[0]->etapa);
    }

    public function testAvanzarCreaElEventoYActualizaLaEtapaActual(): void
    {
        $id = $this->crearTramite('Avance SAS');

        $this->tramites->avanzar($id, Etapa::PROCESANDO_DOCUMENTACION, 'Firmado.', 'nota interna');

        $tramite = $this->tramites->porId($id);
        self::assertNotNull($tramite);
        self::assertSame(Etapa::PROCESANDO_DOCUMENTACION, $tramite->etapaActual);

        $eventos = $this->tramites->eventos($id);
        self::assertCount(2, $eventos);
        // eventos() ordena descendente: el mas nuevo primero.
        self::assertSame(Etapa::PROCESANDO_DOCUMENTACION, $eventos[0]->etapa);
        self::assertSame('Firmado.', $eventos[0]->notaPublica);
        self::assertSame('nota interna', $eventos[0]->notaInterna);
    }

    public function testLaEtapaActualSiempreCoincideConElUltimoEvento(): void
    {
        $id = $this->crearTramite('Recorrido SAS');

        foreach ([Etapa::PROCESANDO_DOCUMENTACION, Etapa::TRAMITE_INICIADO, Etapa::ESPERANDO_CONFIRMACION, Etapa::DICTAMENES, Etapa::PARA_RETIRAR] as $etapa) {
            $this->tramites->avanzar($id, $etapa, null, null);

            $tramite = $this->tramites->porId($id);
            $eventos = $this->tramites->eventos($id);

            self::assertNotNull($tramite);
            self::assertSame(
                $tramite->etapaActual,
                $eventos[0]->etapa,
                'etapa_actual y el ultimo evento divergieron.',
            );
        }

        self::assertCount(6, $this->tramites->eventos($id));
    }

    /**
     * La vista es un loop: el inspector puede despachar varias. Cada pasada queda como
     * un evento propio y, sobre esos eventos, la linea no des-completa nada de lo previo.
     */
    public function testElLoopDeVistaNoDesCompletaLoAnterior(): void
    {
        $id = $this->crearTramite('Loop SAS');

        foreach ([Etapa::TRAMITE_INICIADO, Etapa::VISTA, Etapa::VISTA_CONTESTADA, Etapa::VISTA] as $etapa) {
            $this->tramites->avanzar($id, $etapa, null, null);
        }

        $tramite = $this->tramites->porId($id);
        self::assertNotNull($tramite);
        self::assertSame(Etapa::VISTA, $tramite->etapaActual);

        $linea = LineaEtapas::construir(
            $tramite->flujo,
            $tramite->etapaActual,
            $this->tramites->eventosPublicos($id),
            $this->catalogo,
        );
        $estados = array_column($linea, 'estado', 'valor');
        $repeticiones = array_column($linea, 'repeticion', 'valor');

        self::assertSame(LineaEtapas::CUMPLIDA, $estados['REUNIENDO_DOCUMENTACION']);
        self::assertSame(LineaEtapas::CUMPLIDA, $estados['TRAMITE_INICIADO']);
        self::assertSame(LineaEtapas::CUMPLIDA, $estados['VISTA_CONTESTADA']);
        self::assertSame(LineaEtapas::ACTUAL, $estados['VISTA']);
        self::assertSame('2ª vista', $repeticiones['VISTA']);
    }

    /**
     * Saltar etapas sigue siendo legal, incluso a una que no pertenece al flujo del
     * tramite: el operador es quien sabe. Lo del medio queda pendiente -- no cumplido --
     * y lo de afuera del flujo se dibuja igual, como una etapa mas que ocurrio.
     */
    public function testSaltarAUnaEtapaFueraDelFlujoLaRegistraIgual(): void
    {
        // Un tramite SAS: su flujo termina en TRAMITE_INICIADO_DIGITALMENTE, asi que
        // TRAMITE_INICIADO (el de los tramites en papel) le queda afuera.
        $id = $this->crearTramite('Salteo SAS', Flujo::SAS);

        $this->tramites->avanzar($id, Etapa::ESPERANDO_ESCRIBANIA, null, null);
        $this->tramites->avanzar($id, Etapa::TRAMITE_INICIADO, null, null);

        $tramite = $this->tramites->porId($id);
        self::assertNotNull($tramite);
        self::assertSame(Etapa::TRAMITE_INICIADO, $tramite->etapaActual);
        self::assertFalse($this->catalogo->pertenece($tramite->flujo, Etapa::TRAMITE_INICIADO));

        $linea = LineaEtapas::construir(
            $tramite->flujo,
            $tramite->etapaActual,
            $this->tramites->eventosPublicos($id),
            $this->catalogo,
        );
        $estados = array_column($linea, 'estado', 'valor');

        // La etapa de afuera del flujo es la actual y se dibuja.
        self::assertSame(LineaEtapas::ACTUAL, $estados[Etapa::TRAMITE_INICIADO->value]);
        self::assertTrue(array_column($linea, 'fueraDeFlujo', 'valor')[Etapa::TRAMITE_INICIADO->value]);

        // Las del flujo que nunca ocurrieron siguen pendientes, no cumplidas.
        self::assertSame(LineaEtapas::PENDIENTE, $estados['EDICTO_PUBLICADO']);
        self::assertSame(LineaEtapas::PENDIENTE, $estados['DICTAMENES']);
        self::assertNull(array_column($linea, 'fecha', 'valor')['DICTAMENES']);

        // Y por la que si paso, cumplida.
        self::assertSame(LineaEtapas::CUMPLIDA, $estados['ESPERANDO_ESCRIBANIA']);
    }

    public function testObservadoEsOrtogonalALaEtapa(): void
    {
        $id = $this->crearTramite('Observada SAS');
        $this->tramites->avanzar($id, Etapa::TRAMITE_INICIADO, null, null);

        $this->tramites->actualizarObservacion($id, true, 'Falta acompañar la reserva de nombre.');

        $tramite = $this->tramites->porId($id);
        self::assertNotNull($tramite);
        // Observar NO mueve al tramite de etapa: sigue perteneciendo a la suya.
        self::assertSame(Etapa::TRAMITE_INICIADO, $tramite->etapaActual);
        self::assertTrue($tramite->observado);
        self::assertSame('Falta acompañar la reserva de nombre.', $tramite->notaObservacion);

        $this->tramites->actualizarObservacion($id, false, null);
        $tramite = $this->tramites->porId($id);
        self::assertNotNull($tramite);
        self::assertFalse($tramite->observado);
        self::assertSame(Etapa::TRAMITE_INICIADO, $tramite->etapaActual);
    }
}
