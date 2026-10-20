<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use EstudioCandame\Seguimiento\CatalogoFlujos;
use EstudioCandame\Seguimiento\Etapa;
use EstudioCandame\Seguimiento\Flujo;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Los 7 flujos y su definicion en config. Dominio puro: no necesita base.
 *
 * Es el test que sostiene la promesa de "los flujos viven en config, no en codigo": si
 * la config se rompe (una etapa que no existe, una repetida, un flujo que no cierra),
 * la linea de etapas de un tramite real se rompe con ella.
 */
final class FlujoTest extends TestCase
{
    private static function catalogo(): CatalogoFlujos
    {
        return CatalogoFlujos::desdeConfig(dirname(__DIR__, 2) . '/app');
    }

    public function testTodosLosFlujosEstanDefinidosYSonUsables(): void
    {
        $catalogo = self::catalogo();

        foreach (Flujo::cases() as $flujo) {
            self::assertNotSame('', $catalogo->nombre($flujo), $flujo->value);
            self::assertNotSame('', $catalogo->descripcion($flujo), $flujo->value);
            self::assertNotEmpty($catalogo->etapas($flujo), $flujo->value);
        }
    }

    /**
     * El recorrido de cada flujo: etapas que existen, sin repetir, y que cierran en
     * PARA_RETIRAR. Un flujo que no cierra dejaria al tramite sin ultima etapa y el
     * panel sin "proximo paso" que ofrecer.
     */
    public function testCadaFlujoRecorreEtapasRealesSinRepetirYTerminaEnParaRetirar(): void
    {
        $catalogo = self::catalogo();

        foreach (Flujo::cases() as $flujo) {
            $etapas = $catalogo->etapas($flujo);
            $valores = array_map(static fn (Etapa $etapa): string => $etapa->value, $etapas);

            self::assertSame(
                array_values(array_unique($valores)),
                $valores,
                sprintf('El flujo %s repite alguna etapa.', $flujo->value),
            );

            foreach ($valores as $valor) {
                self::assertNotNull(
                    Etapa::tryFrom($valor),
                    sprintf('El flujo %s referencia la etapa inexistente %s.', $flujo->value, $valor),
                );
            }

            self::assertSame(
                Etapa::PARA_RETIRAR,
                $etapas[array_key_last($etapas)],
                sprintf('El flujo %s no termina en PARA_RETIRAR.', $flujo->value),
            );
            self::assertSame(
                Etapa::REUNIENDO_DOCUMENTACION,
                $catalogo->etapaInicial($flujo),
                sprintf('El flujo %s no arranca reuniendo documentación.', $flujo->value),
            );
        }
    }

    /**
     * Todos los flujos avanzan en el mismo sentido que el catalogo. LineaEtapas se apoya
     * en esto para ubicar una etapa fuera de flujo entre sus vecinas: si un flujo usara
     * B antes que A teniendo A menor orden de catalogo, ese lugar dejaria de estar bien
     * definido.
     */
    public function testNingunFlujoContradiceElOrdenDelCatalogo(): void
    {
        $catalogo = self::catalogo();

        foreach (Flujo::cases() as $flujo) {
            $ordenes = array_map(
                static fn (Etapa $etapa): int => $etapa->ordenCatalogo(),
                $catalogo->etapas($flujo),
            );
            $ordenados = $ordenes;
            sort($ordenados);

            self::assertSame($ordenados, $ordenes, sprintf(
                'El flujo %s usa las etapas en un orden que contradice al catálogo.',
                $flujo->value,
            ));
        }
    }

    /** Todos cierran igual: iniciado, loop opcional de vista, terminado, para retirar. */
    public function testTodosLosFlujosTerminanConLaColaComun(): void
    {
        $catalogo = self::catalogo();

        foreach (Flujo::cases() as $flujo) {
            $etapas = $catalogo->etapas($flujo);
            $cola = array_slice($etapas, -5);

            $iniciado = $flujo === Flujo::SAS
                ? Etapa::TRAMITE_INICIADO_DIGITALMENTE
                : Etapa::TRAMITE_INICIADO;

            self::assertSame(
                [$iniciado, Etapa::VISTA, Etapa::VISTA_CONTESTADA, Etapa::TERMINADO, Etapa::PARA_RETIRAR],
                $cola,
                $flujo->value,
            );
        }
    }

    /**
     * Art. 60 y Reforma SRL sin cambio de gerencia comparten pipeline a proposito. El
     * test fija esa duplicacion para que se note si alguien "corrige" uno solo de los
     * dos, y para que quede escrito que no es un copy-paste olvidado.
     */
    public function testArt60YReformaSinCambioDeGerenciaCompartenSecuencia(): void
    {
        $catalogo = self::catalogo();

        self::assertSame(
            $catalogo->etapas(Flujo::ART_60),
            $catalogo->etapas(Flujo::REFORMA_SRL_SIN_CAMBIO_GERENCIA),
        );
        // Pero son flujos distintos: nombre propio y no se colapsan.
        self::assertNotSame(
            $catalogo->nombre(Flujo::ART_60),
            $catalogo->nombre(Flujo::REFORMA_SRL_SIN_CAMBIO_GERENCIA),
        );
    }

    /** La SAS manda a revisar el estatuto, no un acta. */
    public function testLaSasUsaEsperandoConfirmacionYNoActaEnviadaRevision(): void
    {
        $catalogo = self::catalogo();

        self::assertTrue($catalogo->pertenece(Flujo::SAS, Etapa::ESPERANDO_CONFIRMACION));
        self::assertFalse($catalogo->pertenece(Flujo::SAS, Etapa::ACTA_ENVIADA_REVISION));
    }

    public function testLosFlujosSinEscribaniaNiEdictosNoLosIncluyen(): void
    {
        $catalogo = self::catalogo();

        foreach ([Etapa::HABILITADO_ESCRIBANIA, Etapa::ESPERANDO_ESCRIBANIA, Etapa::EDICTO_PUBLICADO, Etapa::DICTAMENES] as $etapa) {
            self::assertFalse(
                $catalogo->pertenece(Flujo::ASOC_CIVIL_DESIGNACION_REFORMA, $etapa),
                $etapa->value,
            );
        }

        foreach ([Etapa::EDICTO_PUBLICADO, Etapa::DICTAMENES] as $etapa) {
            self::assertFalse($catalogo->pertenece(Flujo::CONSTITUCION_ASOC_CIVIL, $etapa), $etapa->value);
        }
    }

    /**
     * Los overrides son por flujo: la misma etapa dice cosas distintas segun el tramite,
     * y el flujo que no la pisa se queda con el texto generico.
     */
    public function testElOverrideDeTextoAplicaSoloAlFlujoQueLoDefine(): void
    {
        $catalogo = self::catalogo();
        $generico = (require dirname(__DIR__, 2) . '/app/config/etapas.php')['ESPERANDO_ESCRIBANIA'];

        self::assertSame(
            'Escribanía procesando el trámite digital',
            $catalogo->label(Flujo::SAS, Etapa::ESPERANDO_ESCRIBANIA),
        );
        self::assertSame(
            'Escribanía legalizando firmas',
            $catalogo->label(Flujo::REFORMA_SRL_CON_CAMBIO_GERENCIA, Etapa::ESPERANDO_ESCRIBANIA),
        );
        // Constitucion de SRL/SA no lo pisa: texto generico.
        self::assertSame(
            $generico['label'],
            $catalogo->label(Flujo::CONSTITUCION_SRL_SA, Etapa::ESPERANDO_ESCRIBANIA),
        );

        // El detalle de PROCESANDO_DOCUMENTACION se pisa donde el copiado de actas es
        // una etapa aparte y posterior.
        self::assertSame(
            'Se revisa y organiza la documentación recibida.',
            $catalogo->detalle(Flujo::ART_60, Etapa::PROCESANDO_DOCUMENTACION),
        );
        self::assertStringContainsString(
            'libros',
            $catalogo->detalle(Flujo::CONSTITUCION_SRL_SA, Etapa::PROCESANDO_DOCUMENTACION),
        );
    }

    public function testElProximoPasoSaleDelFlujoYNoDelCatalogo(): void
    {
        $catalogo = self::catalogo();

        // En art_60 despues de procesar la documentacion viene el acta a revision; en
        // constitucion de SRL/SA, la confirmacion del borrador.
        self::assertSame(
            [Etapa::ACTA_ENVIADA_REVISION],
            $catalogo->siguientesSugeridas(Flujo::ART_60, Etapa::PROCESANDO_DOCUMENTACION),
        );
        self::assertSame(
            [Etapa::ESPERANDO_CONFIRMACION],
            $catalogo->siguientesSugeridas(Flujo::CONSTITUCION_SRL_SA, Etapa::PROCESANDO_DOCUMENTACION),
        );

        // La ultima no ofrece nada.
        self::assertSame([], $catalogo->siguientesSugeridas(Flujo::SAS, Etapa::PARA_RETIRAR));

        // Las dos ambiguas ofrecen lo mismo en cualquier flujo: otra vista o terminado.
        self::assertSame(
            [Etapa::VISTA, Etapa::TERMINADO],
            $catalogo->siguientesSugeridas(Flujo::SAS, Etapa::TRAMITE_INICIADO_DIGITALMENTE),
        );
        self::assertSame(
            [Etapa::VISTA, Etapa::TERMINADO],
            $catalogo->siguientesSugeridas(Flujo::ART_60, Etapa::VISTA_CONTESTADA),
        );
    }

    /** Una etapa de afuera del flujo no tiene "siguiente" que ofrecer. */
    public function testUnaEtapaFueraDelFlujoNoSugiereProximoPaso(): void
    {
        self::assertSame(
            [],
            self::catalogo()->siguientesSugeridas(Flujo::SAS, Etapa::ACTA_ENVIADA_REVISION),
        );
    }

    public function testUnaConfigInvalidaRevientaAlConstruirElCatalogo(): void
    {
        $etapasConfig = require dirname(__DIR__, 2) . '/app/config/etapas.php';
        $flujos = require dirname(__DIR__, 2) . '/app/config/flujos.php';
        $flujos['art_60']['etapas'][] = ['etapa' => 'ETAPA_QUE_NO_EXISTE'];

        $this->expectException(RuntimeException::class);
        new CatalogoFlujos($flujos, $etapasConfig);
    }

    public function testUnFlujoConUnaEtapaRepetidaRevienta(): void
    {
        $etapasConfig = require dirname(__DIR__, 2) . '/app/config/etapas.php';
        $flujos = require dirname(__DIR__, 2) . '/app/config/flujos.php';
        $flujos['art_60']['etapas'][] = ['etapa' => Etapa::DICTAMENES->value];

        $this->expectException(RuntimeException::class);
        new CatalogoFlujos($flujos, $etapasConfig);
    }
}
