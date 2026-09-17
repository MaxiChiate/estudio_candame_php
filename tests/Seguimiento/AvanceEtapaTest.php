<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use EstudioCandame\Seguimiento\Etapa;
use EstudioCandame\Seguimiento\LineaEtapas;

/**
 * Consistencia entre etapa_actual y el historial de eventos: son dos representaciones
 * del mismo hecho y no pueden divergir.
 */
final class AvanceEtapaTest extends BaseDeDatosTestCase
{
    public function testElAltaDejaElTramiteEnLaEtapaInicialConSuEvento(): void
    {
        $id = $this->tramites->crear('PRES-2026-0200', 'Alta SAS');

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
        $id = $this->tramites->crear('PRES-2026-0201', 'Avance SAS');

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
        $id = $this->tramites->crear('PRES-2026-0202', 'Recorrido SAS');

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
        $config = require APP_PATH . '/config/etapas.php';
        $id = $this->tramites->crear('PRES-2026-0204', 'Loop SAS');

        foreach ([Etapa::TRAMITE_INICIADO, Etapa::VISTA, Etapa::VISTA_CONTESTADA, Etapa::VISTA] as $etapa) {
            $this->tramites->avanzar($id, $etapa, null, null);
        }

        $tramite = $this->tramites->porId($id);
        self::assertNotNull($tramite);
        self::assertSame(Etapa::VISTA, $tramite->etapaActual);

        $linea = LineaEtapas::construir($tramite->etapaActual, $this->tramites->eventosPublicos($id), $config);
        $estados = array_column($linea, 'estado', 'valor');
        $repeticiones = array_column($linea, 'repeticion', 'valor');

        self::assertSame(LineaEtapas::CUMPLIDA, $estados['REUNIENDO_DOCUMENTACION']);
        self::assertSame(LineaEtapas::CUMPLIDA, $estados['TRAMITE_INICIADO']);
        self::assertSame(LineaEtapas::CUMPLIDA, $estados['VISTA_CONTESTADA']);
        self::assertSame(LineaEtapas::ACTUAL, $estados['VISTA']);
        self::assertSame('2ª vista', $repeticiones['VISTA']);
    }

    /** Ir a una etapa muy posterior saltea las del medio sin darlas por cumplidas. */
    public function testSaltearEtapasFunciona(): void
    {
        $config = require APP_PATH . '/config/etapas.php';
        $id = $this->tramites->crear('PRES-2026-0205', 'Salteo SAS');

        $this->tramites->avanzar($id, Etapa::ESPERANDO_ESCRIBANIA, null, null);
        $this->tramites->avanzar($id, Etapa::TRAMITE_INICIADO, null, null);

        $tramite = $this->tramites->porId($id);
        self::assertNotNull($tramite);
        self::assertSame(Etapa::TRAMITE_INICIADO, $tramite->etapaActual);

        $linea = LineaEtapas::construir($tramite->etapaActual, $this->tramites->eventosPublicos($id), $config);
        $estados = array_column($linea, 'estado', 'valor');

        self::assertSame(LineaEtapas::SALTEADA, $estados['EDICTO_PUBLICADO']);
        self::assertSame(LineaEtapas::SALTEADA, $estados['DICTAMENES']);
    }

    public function testObservadoEsOrtogonalALaEtapa(): void
    {
        $id = $this->tramites->crear('PRES-2026-0203', 'Observada SAS');
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
