<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Ficha;

use DateTimeImmutable;
use EstudioCandame\Support\RelojFijo;
use EstudioCandame\Support\RelojSistema;
use PHPUnit\Framework\TestCase;

/**
 * Con RelojFijo, la fecha es siempre la del reloj, nunca la del sistema. RelojSistema es
 * la unica implementacion que de verdad instancia DateTimeImmutable en el momento del
 * llamado -- se prueba con una ventana de tolerancia, no con igualdad exacta.
 */
final class RelojTest extends TestCase
{
    public function testRelojFijoDevuelveSiempreElMismoMomento(): void
    {
        $momento = new DateTimeImmutable('2026-08-10');
        $reloj = new RelojFijo($momento);

        self::assertSame($momento, $reloj->ahora());
        self::assertSame($momento, $reloj->ahora());
    }

    public function testRelojSistemaDevuelveLaHoraReal(): void
    {
        $antes = new DateTimeImmutable();
        $ahora = (new RelojSistema())->ahora();
        $despues = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($antes->getTimestamp(), $ahora->getTimestamp());
        self::assertLessThanOrEqual($despues->getTimestamp(), $ahora->getTimestamp());
    }
}
