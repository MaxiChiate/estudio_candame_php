<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use DateTimeImmutable;
use EstudioCandame\Seguimiento\Etapa;
use EstudioCandame\Seguimiento\EventoPublico;
use EstudioCandame\Seguimiento\LineaEtapas;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Dominio puro: no necesita base. */
final class EtapaTest extends TestCase
{
    public function testElOrdenDeLasEtapasEsElDelSpec(): void
    {
        self::assertSame(
            [
                'REUNIENDO_DOCUMENTACION', 'PROCESANDO_DOCUMENTACION', 'ESPERANDO_CONFIRMACION',
                'HABILITADO_ESCRIBANIA', 'ESPERANDO_ESCRIBANIA', 'EDICTO_PUBLICADO',
                'DICTAMENES', 'TRAMITE_INICIADO', 'VISTA', 'VISTA_CONTESTADA',
                'TERMINADO', 'PARA_RETIRAR',
            ],
            Etapa::valores(),
        );
    }

    public function testCadaEtapaTieneLabelYDetalleEnLaConfig(): void
    {
        $config = require dirname(__DIR__, 2) . '/app/config/etapas.php';

        // Si el enum y la config se desalinean, la vista muestra el value crudo
        // (DOCUMENTACION en vez de "Documentación"). Se chequea en los dos sentidos.
        self::assertSame(Etapa::valores(), array_keys($config));
        foreach ($config as $valor => $datos) {
            self::assertArrayHasKey('label', $datos, $valor);
            self::assertArrayHasKey('detalle', $datos, $valor);
            self::assertNotSame('', $datos['label']);
        }
    }

    public function testObservadoNoEsUnaEtapa(): void
    {
        // Es un flag ortogonal en la tabla, no un case del enum.
        self::assertNull(Etapa::tryFrom('OBSERVADO'));
    }

    public function testElEnumNoDefineLabelsVisibles(): void
    {
        // Los labels los renombra la doctora en config/etapas.php. Si alguien agrega un
        // etiqueta() al enum, renombrar vuelve a requerir tocar codigo y este test avisa.
        $metodos = array_map(
            static fn ($m): string => $m->getName(),
            (new ReflectionClass(Etapa::class))->getMethods(),
        );

        self::assertNotContains('etiqueta', $metodos);
        self::assertNotContains('label', $metodos);
    }

    public function testLaLineaMarcaCumplidasActualYPendientes(): void
    {
        $config = self::config();
        $eventos = [
            new EventoPublico(Etapa::REUNIENDO_DOCUMENTACION, new DateTimeImmutable('2026-03-01'), null),
            new EventoPublico(Etapa::PROCESANDO_DOCUMENTACION, new DateTimeImmutable('2026-03-10'), null),
            new EventoPublico(Etapa::TRAMITE_INICIADO, new DateTimeImmutable('2026-03-20'), null),
        ];

        $linea = LineaEtapas::construir(Etapa::TRAMITE_INICIADO, $eventos, $config);
        $estados = array_column($linea, 'estado', 'valor');

        self::assertSame(LineaEtapas::CUMPLIDA, $estados['REUNIENDO_DOCUMENTACION']);
        self::assertSame(LineaEtapas::CUMPLIDA, $estados['PROCESANDO_DOCUMENTACION']);
        self::assertSame(LineaEtapas::ACTUAL, $estados['TRAMITE_INICIADO']);
        self::assertSame(LineaEtapas::PENDIENTE, $estados['VISTA']);
        self::assertSame(LineaEtapas::PENDIENTE, $estados['PARA_RETIRAR']);

        $fechas = array_column($linea, 'fecha', 'valor');
        self::assertSame('2026-03-10', $fechas['PROCESANDO_DOCUMENTACION']?->format('Y-m-d'));
        self::assertNull($fechas['VISTA'], 'Una etapa pendiente no puede tener fecha.');
    }

    /**
     * El loop de la vista: el inspector despacha una segunda vista y el tramite vuelve
     * atras. Con el criterio viejo (comparar posiciones contra la etapa actual) todas
     * las etapas entre VISTA y la actual se des-completaban solas.
     */
    public function testVolverAVistaNoDesCompletaLasEtapasPrevias(): void
    {
        $config = self::config();
        $eventos = [
            new EventoPublico(Etapa::REUNIENDO_DOCUMENTACION, new DateTimeImmutable('2026-03-01'), null),
            new EventoPublico(Etapa::TRAMITE_INICIADO, new DateTimeImmutable('2026-03-10'), null),
            new EventoPublico(Etapa::VISTA, new DateTimeImmutable('2026-03-15'), null),
            new EventoPublico(Etapa::VISTA_CONTESTADA, new DateTimeImmutable('2026-03-18'), null),
            new EventoPublico(Etapa::VISTA, new DateTimeImmutable('2026-03-25'), null),
        ];

        $linea = LineaEtapas::construir(Etapa::VISTA, $eventos, $config);
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
        $config = self::config();
        $eventos = [
            new EventoPublico(Etapa::VISTA, new DateTimeImmutable('2026-03-01'), null),
            new EventoPublico(Etapa::VISTA_CONTESTADA, new DateTimeImmutable('2026-03-05'), null),
            new EventoPublico(Etapa::VISTA, new DateTimeImmutable('2026-03-10'), null),
            new EventoPublico(Etapa::VISTA_CONTESTADA, new DateTimeImmutable('2026-03-14'), null),
            new EventoPublico(Etapa::VISTA, new DateTimeImmutable('2026-03-20'), null),
        ];

        $linea = LineaEtapas::construir(Etapa::VISTA, $eventos, $config);
        $repeticiones = array_column($linea, 'repeticion', 'valor');

        self::assertSame('3ª vista', $repeticiones['VISTA']);
        // Una sola pasada no lleva contador: "1ª vista" no aporta nada.
        self::assertNull($repeticiones['TRAMITE_INICIADO']);
    }

    /**
     * El pipeline es generico: hay etapas que no aplican a un tramite (DICTAMENES en una
     * SAS por estatuto modelo) y se saltean. Saltear no las da por cumplidas.
     */
    public function testSaltearEtapasNoLasMarcaCumplidas(): void
    {
        $config = self::config();
        $eventos = [
            new EventoPublico(Etapa::ESPERANDO_ESCRIBANIA, new DateTimeImmutable('2026-03-01'), null),
            new EventoPublico(Etapa::TRAMITE_INICIADO, new DateTimeImmutable('2026-03-10'), null),
        ];

        $linea = LineaEtapas::construir(Etapa::TRAMITE_INICIADO, $eventos, $config);
        $estados = array_column($linea, 'estado', 'valor');

        self::assertSame(LineaEtapas::CUMPLIDA, $estados['ESPERANDO_ESCRIBANIA']);
        self::assertSame(LineaEtapas::ACTUAL, $estados['TRAMITE_INICIADO']);
        // Salteadas: quedan pendientes para siempre y no traban nada.
        self::assertSame(LineaEtapas::PENDIENTE, $estados['EDICTO_PUBLICADO']);
        self::assertSame(LineaEtapas::PENDIENTE, $estados['DICTAMENES']);
        // Tampoco se completan las anteriores por las que el tramite nunca paso.
        self::assertSame(LineaEtapas::PENDIENTE, $estados['REUNIENDO_DOCUMENTACION']);
    }

    public function testSiguienteYUltima(): void
    {
        self::assertSame(Etapa::PROCESANDO_DOCUMENTACION, Etapa::REUNIENDO_DOCUMENTACION->siguiente());
        self::assertNull(Etapa::PARA_RETIRAR->siguiente());
        self::assertTrue(Etapa::PARA_RETIRAR->esUltima());
        self::assertFalse(Etapa::TERMINADO->esUltima());
        self::assertTrue(Etapa::REUNIENDO_DOCUMENTACION->esAnteriorA(Etapa::PARA_RETIRAR));
        self::assertFalse(Etapa::PARA_RETIRAR->esAnteriorA(Etapa::REUNIENDO_DOCUMENTACION));
    }

    /**
     * En VISTA_CONTESTADA "siguiente" es ambiguo: por orden del enum daria TERMINADO,
     * pero el inspector puede despachar otra vista. El panel ofrece las dos.
     */
    public function testVistaContestadaOfreceDosProximosPasos(): void
    {
        self::assertSame([Etapa::PROCESANDO_DOCUMENTACION], Etapa::REUNIENDO_DOCUMENTACION->siguientesSugeridas());
        self::assertSame([Etapa::VISTA, Etapa::TERMINADO], Etapa::VISTA_CONTESTADA->siguientesSugeridas());
        self::assertSame([], Etapa::PARA_RETIRAR->siguientesSugeridas());
    }

    /** @return array<string, array{label: string, detalle: string, accion?: string, repeticion?: string}> */
    private static function config(): array
    {
        return require dirname(__DIR__, 2) . '/app/config/etapas.php';
    }

    public function testEventoPublicoNoTieneNotaInterna(): void
    {
        // Garantia estructural: aunque el template quisiera, no hay de donde sacarla.
        $propiedades = array_map(
            static fn ($p): string => $p->getName(),
            (new ReflectionClass(EventoPublico::class))->getProperties(),
        );

        self::assertSame(['etapa', 'ocurridoEl', 'notaPublica'], $propiedades);
        self::assertNotContains('notaInterna', $propiedades);
    }
}
