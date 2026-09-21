<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use DateTimeImmutable;
use EstudioCandame\Seguimiento\Etapa;
use EstudioCandame\Seguimiento\Flujo;
use EstudioCandame\Seguimiento\TramiteRepository;
use EstudioCandame\Support\RelojFijo;

/**
 * La referencia es el id nuestro (EC-2026-0001) y el unico identificador del tramite:
 * el numero de expediente de IGJ no se guarda -- el cliente no tiene que verlo y, al dar
 * de alta, todavia no existe.
 */
final class ReferenciaTest extends BaseDeDatosTestCase
{
    public function testSeGeneraCorrelativaYNoSePide(): void
    {
        $repo = $this->repositorioEn('2026-03-01 10:00:00');

        $primera = $this->tramites->porId($repo->crear('Primera SAS', Flujo::SAS, Etapa::REUNIENDO_DOCUMENTACION))?->referencia;
        $segunda = $this->tramites->porId($repo->crear('Segunda SAS', Flujo::SAS, Etapa::REUNIENDO_DOCUMENTACION))?->referencia;

        self::assertSame('EC-2026-0001', $primera);
        self::assertSame('EC-2026-0002', $segunda);
    }

    public function testElCorrelativoReiniciaCadaAnio(): void
    {
        $repo2026 = $this->repositorioEn('2026-12-30 10:00:00');
        $repo2027 = $this->repositorioEn('2027-01-05 10:00:00');

        $repo2026->crear('De diciembre SAS', Flujo::SAS, Etapa::REUNIENDO_DOCUMENTACION);
        $deEnero = $this->tramites->porId($repo2027->crear('De enero SAS', Flujo::SAS, Etapa::REUNIENDO_DOCUMENTACION))?->referencia;

        // Reinicia: no revela cuantos tramites lleva el estudio en total.
        self::assertSame('EC-2027-0001', $deEnero);

        // Y el año siguiente sigue contando desde ahi, sin pisar al anterior.
        self::assertSame('EC-2027-0002', $this->tramites->porId($repo2027->crear('Otro de enero SAS', Flujo::SAS, Etapa::REUNIENDO_DOCUMENTACION))?->referencia);
    }

    public function testLaReferenciaNoCambiaAlAvanzarDeEtapa(): void
    {
        $id = $this->crearTramite('Estable SAS');
        $original = $this->tramites->porId($id)?->referencia;

        $this->tramites->avanzar($id, Etapa::TRAMITE_INICIADO, null, null);

        self::assertSame($original, $this->tramites->porId($id)?->referencia);
    }

    /**
     * La referencia esta guardada, no se calcula al leer: si se recalculara, renumerar o
     * borrar un tramite le cambiaria la referencia a los demas y el enlace que el cliente
     * ya tiene pasaria a mostrar otro numero.
     */
    public function testLaReferenciaQuedaGuardadaEnLaBase(): void
    {
        $id = $this->crearTramite('Guardada SAS');

        $guardada = $this->conexion->pdo()
            ->query('SELECT referencia FROM tramite WHERE id = ' . $id)
            ->fetchColumn();

        self::assertSame($this->tramites->porId($id)?->referencia, $guardada);
        self::assertMatchesRegularExpression('/^EC-\d{4}-\d{4}$/', (string) $guardada);
    }

    private function repositorioEn(string $momento): TramiteRepository
    {
        return new TramiteRepository($this->conexion, new RelojFijo(new DateTimeImmutable($momento)));
    }
}
