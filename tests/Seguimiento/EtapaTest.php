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
            ['DOCUMENTACION', 'FIRMA', 'PRESENTACION', 'INSCRIPCION', 'CUIT', 'LIBROS'],
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
        $config = require dirname(__DIR__, 2) . '/app/config/etapas.php';
        $eventos = [
            new EventoPublico(Etapa::DOCUMENTACION, new DateTimeImmutable('2026-03-01'), null),
            new EventoPublico(Etapa::FIRMA, new DateTimeImmutable('2026-03-10'), null),
            new EventoPublico(Etapa::PRESENTACION, new DateTimeImmutable('2026-03-20'), null),
        ];

        $linea = LineaEtapas::construir(Etapa::PRESENTACION, $eventos, $config);
        $estados = array_column($linea, 'estado', 'valor');

        self::assertSame(LineaEtapas::CUMPLIDA, $estados['DOCUMENTACION']);
        self::assertSame(LineaEtapas::CUMPLIDA, $estados['FIRMA']);
        self::assertSame(LineaEtapas::ACTUAL, $estados['PRESENTACION']);
        self::assertSame(LineaEtapas::PENDIENTE, $estados['INSCRIPCION']);
        self::assertSame(LineaEtapas::PENDIENTE, $estados['LIBROS']);

        $fechas = array_column($linea, 'fecha', 'valor');
        self::assertSame('2026-03-10', $fechas['FIRMA']?->format('Y-m-d'));
        self::assertNull($fechas['INSCRIPCION'], 'Una etapa pendiente no puede tener fecha.');
    }

    public function testSiguienteYUltima(): void
    {
        self::assertSame(Etapa::FIRMA, Etapa::DOCUMENTACION->siguiente());
        self::assertNull(Etapa::LIBROS->siguiente());
        self::assertTrue(Etapa::LIBROS->esUltima());
        self::assertFalse(Etapa::CUIT->esUltima());
        self::assertTrue(Etapa::DOCUMENTACION->esAnteriorA(Etapa::LIBROS));
        self::assertFalse(Etapa::LIBROS->esAnteriorA(Etapa::DOCUMENTACION));
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
