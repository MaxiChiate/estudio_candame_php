<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use EstudioCandame\Seguimiento\Etapa;

/**
 * Consistencia entre etapa_actual y el historial de eventos: son dos representaciones
 * del mismo hecho y no pueden divergir.
 */
final class AvanceEtapaTest extends BaseDeDatosTestCase
{
    public function testElAltaDejaElTramiteEnLaEtapaInicialConSuEvento(): void
    {
        $id = $this->tramites->crear('PRES-2026-0200', 'Alta SAS', 'MODELO');

        $tramite = $this->tramites->porId($id);
        self::assertNotNull($tramite);
        self::assertSame(Etapa::DOCUMENTACION, $tramite->etapaActual);
        self::assertFalse($tramite->observado);

        $eventos = $this->tramites->eventos($id);
        self::assertCount(1, $eventos, 'El alta tiene que dejar sentado el evento inicial.');
        self::assertSame(Etapa::DOCUMENTACION, $eventos[0]->etapa);
    }

    public function testAvanzarCreaElEventoYActualizaLaEtapaActual(): void
    {
        $id = $this->tramites->crear('PRES-2026-0201', 'Avance SAS', 'MODELO');

        $this->tramites->avanzar($id, Etapa::FIRMA, 'Firmado.', 'nota interna');

        $tramite = $this->tramites->porId($id);
        self::assertNotNull($tramite);
        self::assertSame(Etapa::FIRMA, $tramite->etapaActual);

        $eventos = $this->tramites->eventos($id);
        self::assertCount(2, $eventos);
        // eventos() ordena descendente: el mas nuevo primero.
        self::assertSame(Etapa::FIRMA, $eventos[0]->etapa);
        self::assertSame('Firmado.', $eventos[0]->notaPublica);
        self::assertSame('nota interna', $eventos[0]->notaInterna);
    }

    public function testLaEtapaActualSiempreCoincideConElUltimoEvento(): void
    {
        $id = $this->tramites->crear('PRES-2026-0202', 'Recorrido SAS', 'MODELO');

        foreach ([Etapa::FIRMA, Etapa::PRESENTACION, Etapa::INSCRIPCION, Etapa::CUIT, Etapa::LIBROS] as $etapa) {
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

    public function testObservadoEsOrtogonalALaEtapa(): void
    {
        $id = $this->tramites->crear('PRES-2026-0203', 'Observada SAS', 'MODELO');
        $this->tramites->avanzar($id, Etapa::PRESENTACION, null, null);

        $this->tramites->actualizarObservacion($id, true, 'Falta acompañar la reserva de nombre.');

        $tramite = $this->tramites->porId($id);
        self::assertNotNull($tramite);
        // Observar NO mueve al tramite de etapa: sigue perteneciendo a la suya.
        self::assertSame(Etapa::PRESENTACION, $tramite->etapaActual);
        self::assertTrue($tramite->observado);
        self::assertSame('Falta acompañar la reserva de nombre.', $tramite->notaObservacion);

        $this->tramites->actualizarObservacion($id, false, null);
        $tramite = $this->tramites->porId($id);
        self::assertNotNull($tramite);
        self::assertFalse($tramite->observado);
        self::assertSame(Etapa::PRESENTACION, $tramite->etapaActual);
    }
}
