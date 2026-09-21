<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use EstudioCandame\Seguimiento\Etapa;
use EstudioCandame\Seguimiento\Flujo;

/**
 * Alta de tramite en dos pasos, pasando por routes.php de verdad.
 *
 * El alta se parte en dos porque el flujo NO se puede cambiar despues de creado: la
 * confirmacion muestra el recorrido completo antes de escribir nada. Lo que estos tests
 * cuidan es exactamente eso -- que el paso 2 no guarde, y que el paso 3 no confie en
 * haber pasado por el 2.
 */
final class AdminAltaTest extends BaseDeDatosTestCase
{
    public function testElFormularioOfreceLosSieteFlujos(): void
    {
        $html = (string) $this->pedirAlPanel('GET', '/admin/tramites/nuevo')->getBody();

        foreach (Flujo::cases() as $flujo) {
            self::assertStringContainsString(
                sprintf('value="%s"', $flujo->value),
                $html,
                $flujo->value,
            );
        }
    }

    /** El paso 2 muestra el recorrido y NO escribe nada. */
    public function testLaPrevisualizacionMuestraElRecorridoSinCrearElTramite(): void
    {
        $response = $this->pedirAlPanel('POST', '/admin/tramites/nuevo/previsualizar', [
            'denominacion' => 'Puerto Norte SRL',
            'flujo' => Flujo::REFORMA_SRL_CON_CAMBIO_GERENCIA->value,
        ], csrf: true);

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();

        self::assertStringContainsString('Puerto Norte SRL', $html);
        self::assertStringContainsString('Reforma SRL', $html);
        // El recorrido completo, con el texto que le corresponde a ese flujo.
        self::assertStringContainsString('Escribanía legalizando firmas', $html);
        self::assertStringContainsString('Completando libros, copiando actas', $html);
        // Las dos salidas, al mismo nivel: volver a editar y confirmar.
        self::assertStringContainsString('Volver a editar', $html);
        self::assertStringContainsString('Crear el tr&aacute;mite', $html);

        self::assertSame([], $this->tramites->listado(), 'La previsualización no puede guardar nada.');
    }

    public function testLaPrevisualizacionSinFlujoVuelveAlFormularioConError(): void
    {
        $response = $this->pedirAlPanel('POST', '/admin/tramites/nuevo/previsualizar', [
            'denominacion' => 'Sin tipo SA',
            'flujo' => '',
        ], csrf: true);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Elegí el tipo de trámite.', (string) $response->getBody());
        self::assertSame([], $this->tramites->listado());
    }

    public function testCrearGuardaElTramiteConSuFlujoYLaEtapaInicial(): void
    {
        $response = $this->pedirAlPanel('POST', '/admin/tramites', [
            'denominacion' => 'Los Álamos Asociación Civil',
            'flujo' => Flujo::CONSTITUCION_ASOC_CIVIL->value,
        ], csrf: true);

        self::assertSame(302, $response->getStatusCode());

        $tramites = $this->tramites->listado();
        self::assertCount(1, $tramites);
        self::assertSame('Los Álamos Asociación Civil', $tramites[0]->denominacion);
        self::assertSame(Flujo::CONSTITUCION_ASOC_CIVIL, $tramites[0]->flujo);
        self::assertSame(Etapa::REUNIENDO_DOCUMENTACION, $tramites[0]->etapaActual);
        // El alta deja sentado el primer evento.
        self::assertCount(1, $this->tramites->eventos($tramites[0]->id));
    }

    /**
     * El paso 3 no confia en la previsualizacion: es un POST como cualquier otro y
     * revalida todo por su cuenta.
     */
    public function testCrearRevalidaAunqueSeSalteeLaPrevisualizacion(): void
    {
        $response = $this->pedirAlPanel('POST', '/admin/tramites', [
            'denominacion' => '',
            'flujo' => Flujo::SAS->value,
        ], csrf: true);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('La denominación es obligatoria.', (string) $response->getBody());
        self::assertSame([], $this->tramites->listado());
    }

    public function testCrearConUnFlujoInexistenteNoGuardaNada(): void
    {
        $response = $this->pedirAlPanel('POST', '/admin/tramites', [
            'denominacion' => 'Flujo trucho SA',
            'flujo' => 'constitucion_de_lo_que_sea',
        ], csrf: true);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->tramites->listado());
    }

    public function testSinTokenCsrfNoCrea(): void
    {
        $response = $this->pedirAlPanel('POST', '/admin/tramites', [
            'denominacion' => 'Sin csrf SA',
            'flujo' => Flujo::SAS->value,
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame([], $this->tramites->listado());
    }

    public function testVolverAEditarRepueblaElFormulario(): void
    {
        $response = $this->pedirAlPanel('POST', '/admin/tramites/nuevo/editar', [
            'denominacion' => 'Vuelta Atrás SRL',
            'flujo' => Flujo::ART_60->value,
        ], csrf: true);

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();

        self::assertStringContainsString('value="Vuelta Atrás SRL"', $html);
        self::assertStringContainsString(sprintf('value="%s" selected', Flujo::ART_60->value), $html);
        self::assertSame([], $this->tramites->listado());
    }

    /**
     * El flujo se elige una sola vez. Si aparece una ruta que lo cambie, este test
     * avisa: no alcanza con no ponerle un boton en el panel.
     */
    public function testNoHayNingunaRutaQueCambieElFlujoDeUnTramite(): void
    {
        $id = $this->crearTramite('Inmutable SAS', Flujo::SAS);

        foreach (['/admin/tramites/' . $id, '/admin/tramites/' . $id . '/flujo'] as $ruta) {
            $this->pedirAlPanel('POST', $ruta, [
                'flujo' => Flujo::ART_60->value,
                'denominacion' => 'Inmutable SAS',
            ], csrf: true);
        }

        self::assertSame(Flujo::SAS, $this->tramites->porId($id)?->flujo);
    }

    /** El detalle muestra el flujo, pero como dato y no como campo editable. */
    public function testElDetalleMuestraElFlujoComoSoloLectura(): void
    {
        $id = $this->crearTramite('Detalle SAS', Flujo::SAS);

        $html = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id)->getBody();

        self::assertStringContainsString('Tipo de tr&aacute;mite: SAS', $html);
        self::assertStringNotContainsString('name="flujo"', $html);
    }
}
