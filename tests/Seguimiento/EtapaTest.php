<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use EstudioCandame\Seguimiento\Etapa;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** El catalogo de etapas. Dominio puro: no necesita base. */
final class EtapaTest extends TestCase
{
    /**
     * Los values de las etapas que ya existian estan guardados en tramite_evento y en
     * tramite.etapa_actual de todos los tramites cargados: cambiarlos obliga a una
     * migracion de datos a cambio de nada, porque el value nunca se muestra. Si alguien
     * "ordena" las mayusculas, este test avisa.
     */
    public function testLosValuesPreexistentesNoCambiaron(): void
    {
        $valores = Etapa::valores();

        foreach ([
            'REUNIENDO_DOCUMENTACION', 'PROCESANDO_DOCUMENTACION', 'ESPERANDO_CONFIRMACION',
            'HABILITADO_ESCRIBANIA', 'ESPERANDO_ESCRIBANIA', 'EDICTO_PUBLICADO',
            'DICTAMENES', 'TRAMITE_INICIADO', 'VISTA', 'VISTA_CONTESTADA',
            'TERMINADO', 'PARA_RETIRAR',
        ] as $valor) {
            self::assertContains($valor, $valores);
        }
    }

    public function testCadaEtapaTieneLabelYDetalleEnLaConfig(): void
    {
        $config = require dirname(__DIR__, 2) . '/app/config/etapas.php';

        // Si el enum y la config se desalinean, CatalogoFlujos::label() tira excepcion
        // al renderizar. Se chequea en los dos sentidos.
        self::assertSame(Etapa::valores(), array_keys($config));
        foreach ($config as $valor => $datos) {
            self::assertArrayHasKey('label', $datos, $valor);
            self::assertArrayHasKey('detalle', $datos, $valor);
            self::assertNotSame('', $datos['label']);
            self::assertNotSame('', $datos['detalle']);
        }
    }

    public function testObservadoNoEsUnaEtapa(): void
    {
        // Es un flag ortogonal en la tabla, no un case del enum.
        self::assertNull(Etapa::tryFrom('OBSERVADO'));
    }

    public function testElEnumNoDefineLabelsVisibles(): void
    {
        // Los labels los renombra la doctora en config/etapas.php (y los pisa por flujo
        // en config/flujos.php). Si alguien agrega un etiqueta() al enum, renombrar
        // vuelve a requerir tocar codigo y este test avisa.
        $metodos = array_map(
            static fn ($m): string => $m->getName(),
            (new ReflectionClass(Etapa::class))->getMethods(),
        );

        self::assertNotContains('etiqueta', $metodos);
        self::assertNotContains('label', $metodos);
    }

    /**
     * El enum dejo de ser una secuencia cuando el pipeline paso a definirlo el flujo.
     * Si vuelve a aparecer un siguiente()/esAnteriorA() sobre el enum, hay codigo
     * asumiendo un orden global que ya no existe.
     */
    public function testElEnumNoDefineUnPipeline(): void
    {
        $metodos = array_map(
            static fn ($m): string => $m->getName(),
            (new ReflectionClass(Etapa::class))->getMethods(),
        );

        self::assertNotContains('siguiente', $metodos);
        self::assertNotContains('esAnteriorA', $metodos);
        self::assertNotContains('esUltima', $metodos);
        self::assertNotContains('inicial', $metodos);
    }

    /** El orden de catalogo solo desempata etapas fuera de flujo (ver LineaEtapas). */
    public function testElOrdenDeCatalogoEsEstrictamenteCreciente(): void
    {
        $ordenes = array_map(static fn (Etapa $e): int => $e->ordenCatalogo(), Etapa::cases());

        self::assertSame(range(1, count(Etapa::cases())), $ordenes);
    }

    public function testSoloLasEtapasDeVistaSugierenDosCaminos(): void
    {
        // Donde el inspector puede despachar una vista o no, "el siguiente" es ambiguo.
        self::assertSame([Etapa::VISTA, Etapa::TERMINADO], Etapa::TRAMITE_INICIADO->sugerenciasDeVista());
        self::assertSame([Etapa::VISTA, Etapa::TERMINADO], Etapa::TRAMITE_INICIADO_DIGITALMENTE->sugerenciasDeVista());
        self::assertSame([Etapa::VISTA, Etapa::TERMINADO], Etapa::VISTA_CONTESTADA->sugerenciasDeVista());

        self::assertSame([], Etapa::REUNIENDO_DOCUMENTACION->sugerenciasDeVista());
        self::assertSame([], Etapa::PARA_RETIRAR->sugerenciasDeVista());
    }
}
