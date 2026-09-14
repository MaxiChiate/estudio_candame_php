<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

use DateTimeImmutable;
use EstudioCandame\Support\Reloj;
use PDO;

/**
 * Acceso a tramite y tramite_evento.
 *
 * Punto clave de privacidad: eventosPublicos() NO trae nota_interna en el SELECT. La
 * separacion entre lo que ve el cliente y lo que ve la doctora se decide aca, en el
 * SQL, no en el template.
 */
final class TramiteRepository
{
    private const FORMATO_FECHA = 'Y-m-d H:i:s';

    public function __construct(
        private readonly Conexion $conexion,
        private readonly Reloj $reloj,
    ) {
    }

    public function porId(int $id): ?Tramite
    {
        $stmt = $this->conexion->pdo()->prepare('SELECT * FROM tramite WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $fila = $stmt->fetch();

        return $fila === false ? null : Tramite::desdeFila($fila);
    }

    public function porCodigo(string $codigo): ?Tramite
    {
        $stmt = $this->conexion->pdo()->prepare('SELECT * FROM tramite WHERE codigo = :codigo');
        $stmt->execute(['codigo' => $codigo]);
        $fila = $stmt->fetch();

        return $fila === false ? null : Tramite::desdeFila($fila);
    }

    /**
     * Listado del panel, mas recientes primero.
     *
     * @return Tramite[]
     */
    public function listado(): array
    {
        $stmt = $this->conexion->pdo()->query('SELECT * FROM tramite ORDER BY creado_el DESC, id DESC');

        return array_map(
            static fn (array $fila): Tramite => Tramite::desdeFila($fila),
            $stmt->fetchAll(),
        );
    }

    /**
     * Alta de tramite. Deja sentado el primer evento en la etapa inicial en la misma
     * transaccion: un tramite sin ningun evento mostraria una linea de etapas vacia.
     *
     * @return int id del tramite creado
     */
    public function crear(string $codigo, string $denominacion, string $tipo = 'SAS'): int
    {
        $pdo = $this->conexion->pdo();
        $ahora = $this->reloj->ahora()->format(self::FORMATO_FECHA);
        $etapa = Etapa::inicial();

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO tramite (codigo, tipo, denominacion, etapa_actual, observado, creado_el, actualizado_el)
                 VALUES (:codigo, :tipo, :denominacion, :etapa, 0, :creado, :actualizado)'
            );
            $stmt->execute([
                'codigo' => $codigo,
                'tipo' => $tipo,
                'denominacion' => $denominacion,
                'etapa' => $etapa->value,
                'creado' => $ahora,
                'actualizado' => $ahora,
            ]);
            $id = (int) $pdo->lastInsertId();

            $this->insertarEvento($id, $etapa, $ahora, null, null);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        return $id;
    }

    /**
     * Avanza el tramite a una etapa: crea el evento y mueve etapa_actual en una sola
     * transaccion. Si se hicieran por separado, un fallo en el medio dejaria la linea
     * de etapas contando una historia distinta a la del estado actual.
     */
    public function avanzar(int $tramiteId, Etapa $etapa, ?string $notaPublica, ?string $notaInterna): void
    {
        $pdo = $this->conexion->pdo();
        $ahora = $this->reloj->ahora()->format(self::FORMATO_FECHA);

        $pdo->beginTransaction();
        try {
            $this->insertarEvento($tramiteId, $etapa, $ahora, $notaPublica, $notaInterna);

            $stmt = $pdo->prepare('UPDATE tramite SET etapa_actual = :etapa, actualizado_el = :ahora WHERE id = :id');
            $stmt->execute(['etapa' => $etapa->value, 'ahora' => $ahora, 'id' => $tramiteId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }

    public function actualizarObservacion(int $tramiteId, bool $observado, ?string $nota): void
    {
        $stmt = $this->conexion->pdo()->prepare(
            'UPDATE tramite SET observado = :observado, nota_observacion = :nota, actualizado_el = :ahora WHERE id = :id'
        );
        $stmt->execute([
            'observado' => $observado ? 1 : 0,
            'nota' => $observado ? $nota : null,
            'ahora' => $this->reloj->ahora()->format(self::FORMATO_FECHA),
            'id' => $tramiteId,
        ]);
    }

    /**
     * Eventos para la VISTA PUBLICA. nota_interna no entra en el SELECT: que no se
     * pueda filtrar es una propiedad de esta consulta, no del template.
     *
     * @return EventoPublico[]
     */
    public function eventosPublicos(int $tramiteId): array
    {
        $stmt = $this->conexion->pdo()->prepare(
            'SELECT etapa, ocurrido_el, nota_publica FROM tramite_evento
             WHERE tramite_id = :id ORDER BY ocurrido_el ASC, id ASC'
        );
        $stmt->execute(['id' => $tramiteId]);

        $eventos = [];
        foreach ($stmt->fetchAll() as $fila) {
            $etapa = Etapa::tryFrom((string) $fila['etapa']);
            if ($etapa === null) {
                continue;
            }
            $eventos[] = new EventoPublico(
                $etapa,
                new DateTimeImmutable((string) $fila['ocurrido_el']),
                $fila['nota_publica'] !== null ? (string) $fila['nota_publica'] : null,
            );
        }

        return $eventos;
    }

    /**
     * Eventos completos, con nota interna. SOLO para el panel.
     *
     * @return TramiteEvento[]
     */
    public function eventos(int $tramiteId): array
    {
        $stmt = $this->conexion->pdo()->prepare(
            'SELECT * FROM tramite_evento WHERE tramite_id = :id ORDER BY ocurrido_el DESC, id DESC'
        );
        $stmt->execute(['id' => $tramiteId]);

        $eventos = [];
        foreach ($stmt->fetchAll() as $fila) {
            $etapa = Etapa::tryFrom((string) $fila['etapa']);
            if ($etapa === null) {
                continue;
            }
            $eventos[] = new TramiteEvento(
                (int) $fila['id'],
                (int) $fila['tramite_id'],
                $etapa,
                new DateTimeImmutable((string) $fila['ocurrido_el']),
                $fila['nota_publica'] !== null ? (string) $fila['nota_publica'] : null,
                $fila['nota_interna'] !== null ? (string) $fila['nota_interna'] : null,
            );
        }

        return $eventos;
    }

    private function insertarEvento(int $tramiteId, Etapa $etapa, string $ocurridoEl, ?string $notaPublica, ?string $notaInterna): void
    {
        $stmt = $this->conexion->pdo()->prepare(
            'INSERT INTO tramite_evento (tramite_id, etapa, ocurrido_el, nota_publica, nota_interna)
             VALUES (:tramite, :etapa, :ocurrido, :publica, :interna)'
        );
        $stmt->execute([
            'tramite' => $tramiteId,
            'etapa' => $etapa->value,
            'ocurrido' => $ocurridoEl,
            'publica' => $notaPublica !== null && $notaPublica !== '' ? $notaPublica : null,
            'interna' => $notaInterna !== null && $notaInterna !== '' ? $notaInterna : null,
        ]);
    }
}
