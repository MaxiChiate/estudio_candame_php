<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use DateTimeImmutable;
use EstudioCandame\Seguimiento\CatalogoFlujos;
use EstudioCandame\Seguimiento\Etapa;
use EstudioCandame\Seguimiento\EventoPublico;
use EstudioCandame\Seguimiento\Flujo;
use EstudioCandame\Seguimiento\LineaEtapas;
use PHPUnit\Framework\TestCase;

/** La linea que ve el cliente. Dominio puro: no necesita base. */
final class LineaEtapasTest extends TestCase
{
    private static function catalogo(): CatalogoFlujos
    {
        return CatalogoFlujos::desdeConfig(dirname(__DIR__, 2) . '/app');
    }

    /**
     * Un tramite recien creado dibuja EXACTAMENTE las etapas de su flujo, sin las
     * opcionales (las de la vista, que pueden no ocurrir nunca). Es el corazon de la
     * feature: antes todos los tramites mostraban la misma secuencia generica y el
     * cliente veia etapas que no le iban a tocar.
     */
    public function testUnTramiteNuevoDibujaLasEtapasDeSuFlujo(): void
    {
        $catalogo = self::catalogo();

        foreach (Flujo::cases() as $flujo) {
            $inicial = $catalogo->etapaInicial($flujo);
            $linea = LineaEtapas::construir(
                $flujo,
                $inicial,
                [new EventoPublico($inicial, new DateTimeImmutable('2026-03-01'), null)],
                $catalogo,
            );

            $esperadas = array_values(array_map(
                static fn (Etapa $etapa): string => $etapa->value,
                array_filter(
                    $catalogo->etapas($flujo),
                    static fn (Etapa $etapa): bool => !$catalogo->esOpcional($etapa),
                ),
            ));

            self::assertSame($esperadas, array_column($linea, 'valor'), $flujo->value);
            self::assertSame(LineaEtapas::ACTUAL, array_column($linea, 'estado', 'valor')[$inicial->value]);
        }
    }

    /** Ningun flujo muestra etapas de otro. */
    public function testLaLineaDeUnFlujoNoIncluyeEtapasDeOtro(): void
    {
        $catalogo = self::catalogo();
        $inicial = $catalogo->etapaInicial(Flujo::ASOC_CIVIL_DESIGNACION_REFORMA);

        $valores = array_column(
            LineaEtapas::construir(Flujo::ASOC_CIVIL_DESIGNACION_REFORMA, $inicial, [], $catalogo),
            'valor',
        );

        // Esta asociacion civil no pasa por escribania, edictos ni dictamenes.
        self::assertNotContains(Etapa::HABILITADO_ESCRIBANIA->value, $valores);
        self::assertNotContains(Etapa::EDICTO_PUBLICADO->value, $valores);
        self::assertNotContains(Etapa::DICTAMENES->value, $valores);
        // Y si las propias.
        self::assertContains(Etapa::PLANILLAS_NOMINAS_DDJJ_FIRMA->value, $valores);
        self::assertContains(Etapa::COPIANDO_ACTAS_AL_LIBRO->value, $valores);
    }

    public function testLaLineaMarcaCumplidasActualYPendientes(): void
    {
        $catalogo = self::catalogo();
        $eventos = [
            new EventoPublico(Etapa::REUNIENDO_DOCUMENTACION, new DateTimeImmutable('2026-03-01'), null),
            new EventoPublico(Etapa::PROCESANDO_DOCUMENTACION, new DateTimeImmutable('2026-03-10'), null),
            new EventoPublico(Etapa::TRAMITE_INICIADO, new DateTimeImmutable('2026-03-20'), null),
        ];

        $linea = LineaEtapas::construir(Flujo::CONSTITUCION_SRL_SA, Etapa::TRAMITE_INICIADO, $eventos, $catalogo);
        $estados = array_column($linea, 'estado', 'valor');

        self::assertSame(LineaEtapas::CUMPLIDA, $estados['REUNIENDO_DOCUMENTACION']);
        self::assertSame(LineaEtapas::CUMPLIDA, $estados['PROCESANDO_DOCUMENTACION']);
        self::assertSame(LineaEtapas::ACTUAL, $estados['TRAMITE_INICIADO']);
        self::assertSame(LineaEtapas::PENDIENTE, $estados['PARA_RETIRAR']);

        $fechas = array_column($linea, 'fecha', 'valor');
        self::assertSame('2026-03-10', $fechas['PROCESANDO_DOCUMENTACION']?->format('Y-m-d'));
        self::assertNull($fechas['PARA_RETIRAR'], 'Una etapa pendiente no puede tener fecha.');
    }

    /**
     * Las etapas de la vista pueden no ocurrir nunca: un tramite puede terminar sin
     * ninguna. Anunciarlas pendientes le avisaria al cliente de una vista que quizas no
     * exista, asi que no aparecen hasta que pasan.
     */
    public function testLasEtapasOpcionalesNoSeAnuncianDeAntemano(): void
    {
        $catalogo = self::catalogo();
        $eventos = [new EventoPublico(Etapa::TRAMITE_INICIADO, new DateTimeImmutable('2026-03-20'), null)];

        $valores = array_column(
            LineaEtapas::construir(Flujo::CONSTITUCION_SRL_SA, Etapa::TRAMITE_INICIADO, $eventos, $catalogo),
            'valor',
        );

        self::assertNotContains('VISTA', $valores);
        self::assertNotContains('VISTA_CONTESTADA', $valores);
        // El resto de la linea sigue completa.
        self::assertContains('TERMINADO', $valores);
        self::assertContains('PARA_RETIRAR', $valores);
    }

    public function testLaEtapaOpcionalApareceUnaVezQueOcurrio(): void
    {
        $catalogo = self::catalogo();
        $eventos = [
            new EventoPublico(Etapa::TRAMITE_INICIADO, new DateTimeImmutable('2026-03-20'), null),
            new EventoPublico(Etapa::VISTA, new DateTimeImmutable('2026-03-25'), null),
        ];

        $estados = array_column(
            LineaEtapas::construir(Flujo::CONSTITUCION_SRL_SA, Etapa::VISTA, $eventos, $catalogo),
            'estado',
            'valor',
        );

        self::assertSame(LineaEtapas::ACTUAL, $estados['VISTA']);
        // La contestacion todavia no ocurrio: sigue sin anunciarse.
        self::assertArrayNotHasKey('VISTA_CONTESTADA', $estados);
    }

    /**
     * El loop de la vista: el inspector despacha una segunda vista y el tramite vuelve
     * atras. Con un criterio posicional, todas las etapas entre VISTA y la actual se
     * des-completarian solas. Invariante vieja que la feature de flujos no toca.
     */
    public function testVolverAtrasNoDesCompletaLasEtapasPrevias(): void
    {
        $catalogo = self::catalogo();
        $eventos = [
            new EventoPublico(Etapa::REUNIENDO_DOCUMENTACION, new DateTimeImmutable('2026-03-01'), null),
            new EventoPublico(Etapa::TRAMITE_INICIADO, new DateTimeImmutable('2026-03-10'), null),
            new EventoPublico(Etapa::VISTA, new DateTimeImmutable('2026-03-15'), null),
            new EventoPublico(Etapa::VISTA_CONTESTADA, new DateTimeImmutable('2026-03-18'), null),
            new EventoPublico(Etapa::VISTA, new DateTimeImmutable('2026-03-25'), null),
        ];

        $linea = LineaEtapas::construir(Flujo::CONSTITUCION_SRL_SA, Etapa::VISTA, $eventos, $catalogo);
        $estados = array_column($linea, 'estado', 'valor');

        self::assertSame(LineaEtapas::CUMPLIDA, $estados['REUNIENDO_DOCUMENTACION']);
        self::assertSame(LineaEtapas::CUMPLIDA, $estados['TRAMITE_INICIADO']);
        self::assertSame(LineaEtapas::ACTUAL, $estados['VISTA']);
        // Posterior a la actual y cumplida a la vez: el tramite paso por ahi y volvio.
        self::assertSame(LineaEtapas::CUMPLIDA, $estados['VISTA_CONTESTADA']);

        // La fecha de la etapa actual es la de la ultima vez que paso, no la primera.
        $fechas = array_column($linea, 'fecha', 'valor');
        self::assertSame('2026-03-25', $fechas['VISTA']?->format('Y-m-d'));
    }

    public function testLaEtapaRepetidaMuestraElContador(): void
    {
        $catalogo = self::catalogo();
        $eventos = [
            new EventoPublico(Etapa::VISTA, new DateTimeImmutable('2026-03-01'), null),
            new EventoPublico(Etapa::VISTA_CONTESTADA, new DateTimeImmutable('2026-03-05'), null),
            new EventoPublico(Etapa::VISTA, new DateTimeImmutable('2026-03-10'), null),
            new EventoPublico(Etapa::VISTA_CONTESTADA, new DateTimeImmutable('2026-03-14'), null),
            new EventoPublico(Etapa::VISTA, new DateTimeImmutable('2026-03-20'), null),
        ];

        $repeticiones = array_column(
            LineaEtapas::construir(Flujo::CONSTITUCION_SRL_SA, Etapa::VISTA, $eventos, $catalogo),
            'repeticion',
            'valor',
        );

        self::assertSame('3ª vista', $repeticiones['VISTA']);
        // Una sola pasada no lleva contador: "1ª vista" no aporta nada.
        self::assertNull($repeticiones['TRAMITE_INICIADO']);
    }

    /**
     * El operador puede saltar a cualquier etapa del catalogo, incluidas las que no son
     * del flujo del tramite. Si eso paso, la etapa se dibuja: para el cliente es una
     * etapa mas que ocurrio, y la linea NO le informa que "no correspondia".
     */
    public function testUnaEtapaFueraDelFlujoConEventoSeDibujaComoCumplida(): void
    {
        $catalogo = self::catalogo();
        // Dictamenes no es parte de la constitucion de asociacion civil.
        $eventos = [
            new EventoPublico(Etapa::REUNIENDO_DOCUMENTACION, new DateTimeImmutable('2026-03-01'), null),
            new EventoPublico(Etapa::DICTAMENES, new DateTimeImmutable('2026-03-05'), null),
            new EventoPublico(Etapa::TRAMITE_INICIADO, new DateTimeImmutable('2026-03-10'), null),
        ];

        $linea = LineaEtapas::construir(Flujo::CONSTITUCION_ASOC_CIVIL, Etapa::TRAMITE_INICIADO, $eventos, $catalogo);
        $estados = array_column($linea, 'estado', 'valor');
        $valores = array_column($linea, 'valor');

        self::assertSame(LineaEtapas::CUMPLIDA, $estados[Etapa::DICTAMENES->value]);
        self::assertSame(
            '2026-03-05',
            array_column($linea, 'fecha', 'valor')[Etapa::DICTAMENES->value]?->format('Y-m-d'),
        );

        // Ubicada por orden de catalogo: despues de la escribania, antes del inicio.
        self::assertSame(
            array_search(Etapa::ESPERANDO_ESCRIBANIA->value, $valores, true) + 1,
            array_search(Etapa::DICTAMENES->value, $valores, true),
        );
        self::assertLessThan(
            array_search(Etapa::TRAMITE_INICIADO->value, $valores, true),
            array_search(Etapa::DICTAMENES->value, $valores, true),
        );

        // El flag existe para el panel; la vista publica no lo usa.
        self::assertTrue(array_column($linea, 'fueraDeFlujo', 'valor')[Etapa::DICTAMENES->value]);
        self::assertFalse(array_column($linea, 'fueraDeFlujo', 'valor')[Etapa::TRAMITE_INICIADO->value]);
    }

    /** Sin evento, una etapa que no es del flujo no se dibuja. El estado "salteada" murio. */
    public function testUnaEtapaFueraDelFlujoSinEventosNoSeDibuja(): void
    {
        $catalogo = self::catalogo();

        $valores = array_column(
            LineaEtapas::construir(
                Flujo::CONSTITUCION_ASOC_CIVIL,
                Etapa::REUNIENDO_DOCUMENTACION,
                [new EventoPublico(Etapa::REUNIENDO_DOCUMENTACION, new DateTimeImmutable('2026-03-01'), null)],
                $catalogo,
            ),
            'valor',
        );

        self::assertNotContains(Etapa::DICTAMENES->value, $valores);
        self::assertNotContains(Etapa::TRAMITE_SUBIDO_TAD->value, $valores);
    }

    /** Una etapa del flujo sin evento pero ya pasada queda pendiente, no "salteada". */
    public function testUnaEtapaDelFlujoSinEventoQuedaPendiente(): void
    {
        $catalogo = self::catalogo();
        $eventos = [new EventoPublico(Etapa::TRAMITE_INICIADO, new DateTimeImmutable('2026-03-10'), null)];

        $linea = LineaEtapas::construir(Flujo::CONSTITUCION_SRL_SA, Etapa::TRAMITE_INICIADO, $eventos, $catalogo);
        $estados = array_column($linea, 'estado', 'valor');

        self::assertSame(LineaEtapas::PENDIENTE, $estados['EDICTO_PUBLICADO']);
        self::assertNull(array_column($linea, 'fecha', 'valor')['EDICTO_PUBLICADO']);
    }

    /** Aunque sea de afuera del flujo, la etapa actual siempre se dibuja. */
    public function testLaEtapaActualSeDibujaAunqueNoSeaDelFlujo(): void
    {
        $catalogo = self::catalogo();

        $estados = array_column(
            LineaEtapas::construir(Flujo::CONSTITUCION_ASOC_CIVIL, Etapa::DICTAMENES, [], $catalogo),
            'estado',
            'valor',
        );

        self::assertSame(LineaEtapas::ACTUAL, $estados[Etapa::DICTAMENES->value]);
    }

    /** El texto sale del flujo cuando lo pisa, y del generico cuando no. */
    public function testLaLineaUsaElTextoDelFlujo(): void
    {
        $catalogo = self::catalogo();
        $inicial = $catalogo->etapaInicial(Flujo::SAS);

        $labels = array_column(
            LineaEtapas::construir(Flujo::SAS, $inicial, [], $catalogo),
            'label',
            'valor',
        );

        self::assertSame('Escribanía procesando el trámite digital', $labels[Etapa::ESPERANDO_ESCRIBANIA->value]);
        self::assertSame('Publicando edictos', $labels[Etapa::EDICTO_PUBLICADO->value]);
    }

    /**
     * La previsualizacion del alta muestra el recorrido COMPLETO, con las opcionales
     * incluidas: quien mira es el operador eligiendo un flujo, no el cliente.
     */
    public function testLaPrevisualizacionMuestraElFlujoEnteroSinFechas(): void
    {
        $catalogo = self::catalogo();
        $linea = LineaEtapas::previsualizar(Flujo::SAS, $catalogo);

        self::assertSame(
            array_map(static fn (Etapa $etapa): string => $etapa->value, $catalogo->etapas(Flujo::SAS)),
            array_column($linea, 'valor'),
        );
        self::assertSame([LineaEtapas::PENDIENTE], array_values(array_unique(array_column($linea, 'estado'))));
        self::assertSame([null], array_values(array_unique(array_column($linea, 'fecha'))));
        self::assertContains(Etapa::VISTA->value, array_column($linea, 'valor'));
    }
}
