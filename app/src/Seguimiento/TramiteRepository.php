<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

use DateTimeImmutable;
use EstudioCandame\Support\Reloj;
use PDO;

/**
 * Acceso a tramite y sus tablas hijas: tramite_evento (cambios de etapa), tramite_novedad
 * (mensajes al cliente sin cambio de etapa) y tramite_nota_interna.
 *
 * Punto clave de privacidad: eventosPublicos() NO trae nota_interna en el SELECT. La
 * separacion entre lo que ve el cliente y lo que ve la doctora se decide aca, en el
 * SQL, no en el template. Lo mismo con las notas internas sueltas: viven en
 * tramite_nota_interna, y ningun metodo que alimente al portal la lee.
 */
final class TramiteRepository
{
    private const FORMATO_FECHA = 'Y-m-d H:i:s';

    /**
     * Prefijo de la referencia que ve el cliente (EC-2026-0001). Son las iniciales del
     * estudio: cambiarlas es cambiar esta constante, y los tramites ya creados conservan
     * la suya porque la referencia se guarda, no se calcula al leer.
     */
    private const PREFIJO_REFERENCIA = 'EC';

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

    public function porReferencia(string $referencia): ?Tramite
    {
        $stmt = $this->conexion->pdo()->prepare('SELECT * FROM tramite WHERE referencia = :referencia');
        $stmt->execute(['referencia' => $referencia]);
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
     * El flujo llega resuelto de afuera y no se puede cambiar despues: no hay ningun
     * metodo aca que lo actualice, a proposito. La etapa inicial tambien viene dada
     * (CatalogoFlujos::etapaInicial) porque depende del flujo, no del catalogo de
     * etapas.
     *
     * @return int id del tramite creado
     */
    public function crear(string $denominacion, Flujo $flujo, Etapa $etapaInicial): int
    {
        $pdo = $this->conexion->pdo();
        $ahora = $this->reloj->ahora();
        $ahoraTexto = $ahora->format(self::FORMATO_FECHA);
        $etapa = $etapaInicial;

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO tramite (referencia, flujo, denominacion, etapa_actual, observado, creado_el, actualizado_el)
                 VALUES (:referencia, :flujo, :denominacion, :etapa, 0, :creado, :actualizado)'
            );
            $stmt->execute([
                'referencia' => $this->proximaReferencia((int) $ahora->format('Y')),
                'flujo' => $flujo->value,
                'denominacion' => $denominacion,
                'etapa' => $etapa->value,
                'creado' => $ahoraTexto,
                'actualizado' => $ahoraTexto,
            ]);
            $id = (int) $pdo->lastInsertId();

            $this->insertarEvento($id, $etapa, $ahoraTexto, null, null);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        return $id;
    }

    /**
     * Siguiente referencia del año: EC-2026-0001, EC-2026-0002...
     *
     * El correlativo reinicia cada año, asi que no revela cuantos tramites lleva el
     * estudio en total. Sale de mirar la mayor referencia del año en curso y sumarle
     * uno: no hay contador aparte que se pueda desincronizar. La llama crear() DENTRO de
     * su transaccion, y ademas hay un UNIQUE en la columna -- si alguna vez dos altas
     * simultaneas pidieran el mismo numero, la segunda falla en vez de duplicar.
     */
    private function proximaReferencia(int $anio): string
    {
        $prefijoAnio = sprintf('%s-%d-', self::PREFIJO_REFERENCIA, $anio);

        $stmt = $this->conexion->pdo()->prepare(
            'SELECT MAX(referencia) FROM tramite WHERE referencia LIKE :prefijo'
        );
        $stmt->execute(['prefijo' => $prefijoAnio . '%']);
        $ultima = $stmt->fetchColumn();

        // El LPAD a 4 hace que el orden alfabetico coincida con el numerico hasta 9999,
        // asi que MAX() alcanza y no hace falta ordenar en PHP.
        $numero = is_string($ultima) ? ((int) substr($ultima, mb_strlen($prefijoAnio))) + 1 : 1;

        return $prefijoAnio . str_pad((string) $numero, 4, '0', STR_PAD_LEFT);
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

    /**
     * Un evento suelto, para editarlo o borrarlo desde el panel. Filtra por tramite
     * ademas de por id: la ruta trae los dos, y un id de evento de OTRO tramite tiene
     * que dar null en vez de dejarse tocar desde una URL que no le corresponde.
     */
    public function evento(int $tramiteId, int $eventoId): ?TramiteEvento
    {
        foreach ($this->eventos($tramiteId) as $evento) {
            if ($evento->id === $eventoId) {
                return $evento;
            }
        }

        return null;
    }

    /**
     * Carga un evento en el historial con fecha a eleccion, SIN mover etapa_actual.
     *
     * Existe para corregir el pasado -- tipicamente, tramites que ya venian avanzados
     * cuando se cargaron en el portal --, no para avanzar: para eso esta avanzar(), que
     * es la que mueve la etapa en curso. Por eso aca etapa_actual y el ultimo evento
     * pueden quedar distintos, y es lo que se pidio.
     */
    public function agregarEvento(
        int $tramiteId,
        Etapa $etapa,
        DateTimeImmutable $ocurridoEl,
        ?string $notaPublica,
        ?string $notaInterna,
    ): void {
        $this->insertarEvento($tramiteId, $etapa, $ocurridoEl->format(self::FORMATO_FECHA), $notaPublica, $notaInterna);
    }

    /** Corrige fecha y notas de un evento. La etapa no se edita: eso es borrar y agregar. */
    public function editarEvento(
        int $eventoId,
        DateTimeImmutable $ocurridoEl,
        ?string $notaPublica,
        ?string $notaInterna,
    ): void {
        $stmt = $this->conexion->pdo()->prepare(
            'UPDATE tramite_evento SET ocurrido_el = :ocurrido, nota_publica = :publica, nota_interna = :interna
             WHERE id = :id'
        );
        $stmt->execute([
            'ocurrido' => $ocurridoEl->format(self::FORMATO_FECHA),
            'publica' => $notaPublica !== null && $notaPublica !== '' ? $notaPublica : null,
            'interna' => $notaInterna !== null && $notaInterna !== '' ? $notaInterna : null,
            'id' => $eventoId,
        ]);
    }

    /**
     * Borra un evento. etapa_actual no se toca: si se borra el evento de la etapa en
     * curso, el tramite sigue en ella y el cliente la ve sin fecha.
     */
    public function eliminarEvento(int $eventoId): void
    {
        $stmt = $this->conexion->pdo()->prepare('DELETE FROM tramite_evento WHERE id = :id');
        $stmt->execute(['id' => $eventoId]);
    }

    /**
     * Publica un mensaje al cliente SIN cambiar de etapa. Queda asociado a la etapa
     * vigente como referencia; no crea evento, asi que no mueve ni la fecha de "etapa
     * actual desde" ni el contador de la vista.
     */
    public function publicarNovedad(int $tramiteId, Etapa $etapaVigente, string $texto): void
    {
        $stmt = $this->conexion->pdo()->prepare(
            'INSERT INTO tramite_novedad (tramite_id, etapa, publicado_el, texto)
             VALUES (:tramite, :etapa, :publicado, :texto)'
        );
        $stmt->execute([
            'tramite' => $tramiteId,
            'etapa' => $etapaVigente->value,
            'publicado' => $this->reloj->ahora()->format(self::FORMATO_FECHA),
            'texto' => $texto,
        ]);
    }

    /**
     * Novedades para la VISTA PUBLICA, de la mas vieja a la mas nueva: fecha y texto.
     *
     * @return NovedadPublica[]
     */
    public function novedadesPublicas(int $tramiteId): array
    {
        $stmt = $this->conexion->pdo()->prepare(
            'SELECT publicado_el, texto FROM tramite_novedad
             WHERE tramite_id = :id ORDER BY publicado_el ASC, id ASC'
        );
        $stmt->execute(['id' => $tramiteId]);

        return array_map(
            static fn (array $fila): NovedadPublica => new NovedadPublica(
                new DateTimeImmutable((string) $fila['publicado_el']),
                (string) $fila['texto'],
            ),
            $stmt->fetchAll(),
        );
    }

    /**
     * Novedades para el panel, mas recientes primero (mismo orden que eventos()).
     *
     * @return Novedad[]
     */
    public function novedades(int $tramiteId): array
    {
        $stmt = $this->conexion->pdo()->prepare(
            'SELECT * FROM tramite_novedad WHERE tramite_id = :id ORDER BY publicado_el DESC, id DESC'
        );
        $stmt->execute(['id' => $tramiteId]);

        $novedades = [];
        foreach ($stmt->fetchAll() as $fila) {
            $etapa = Etapa::tryFrom((string) $fila['etapa']);
            if ($etapa === null) {
                continue;
            }
            $novedades[] = new Novedad(
                (int) $fila['id'],
                (int) $fila['tramite_id'],
                $etapa,
                new DateTimeImmutable((string) $fila['publicado_el']),
                (string) $fila['texto'],
            );
        }

        return $novedades;
    }

    /** Una novedad del tramite, o null si no existe o es de otro tramite (ver evento()). */
    public function novedad(int $tramiteId, int $novedadId): ?Novedad
    {
        foreach ($this->novedades($tramiteId) as $novedad) {
            if ($novedad->id === $novedadId) {
                return $novedad;
            }
        }

        return null;
    }

    public function editarNovedad(int $novedadId, DateTimeImmutable $publicadoEl, string $texto): void
    {
        $stmt = $this->conexion->pdo()->prepare(
            'UPDATE tramite_novedad SET publicado_el = :publicado, texto = :texto WHERE id = :id'
        );
        $stmt->execute([
            'publicado' => $publicadoEl->format(self::FORMATO_FECHA),
            'texto' => $texto,
            'id' => $novedadId,
        ]);
    }

    public function eliminarNovedad(int $novedadId): void
    {
        $stmt = $this->conexion->pdo()->prepare('DELETE FROM tramite_novedad WHERE id = :id');
        $stmt->execute(['id' => $novedadId]);
    }

    /**
     * Nota interna suelta: ni cambia la etapa ni le publica nada al cliente. Va a su
     * propia tabla, que ninguna consulta del portal publico lee.
     */
    public function agregarNotaInterna(int $tramiteId, Etapa $etapaVigente, string $texto): void
    {
        $stmt = $this->conexion->pdo()->prepare(
            'INSERT INTO tramite_nota_interna (tramite_id, etapa, escrita_el, texto)
             VALUES (:tramite, :etapa, :escrita, :texto)'
        );
        $stmt->execute([
            'tramite' => $tramiteId,
            'etapa' => $etapaVigente->value,
            'escrita' => $this->reloj->ahora()->format(self::FORMATO_FECHA),
            'texto' => $texto,
        ]);
    }

    /**
     * Notas internas sueltas, SOLO para el panel. Mas recientes primero.
     *
     * @return NotaInterna[]
     */
    public function notasInternas(int $tramiteId): array
    {
        $stmt = $this->conexion->pdo()->prepare(
            'SELECT * FROM tramite_nota_interna WHERE tramite_id = :id ORDER BY escrita_el DESC, id DESC'
        );
        $stmt->execute(['id' => $tramiteId]);

        $notas = [];
        foreach ($stmt->fetchAll() as $fila) {
            $etapa = Etapa::tryFrom((string) $fila['etapa']);
            if ($etapa === null) {
                continue;
            }
            $notas[] = new NotaInterna(
                (int) $fila['id'],
                (int) $fila['tramite_id'],
                $etapa,
                new DateTimeImmutable((string) $fila['escrita_el']),
                (string) $fila['texto'],
            );
        }

        return $notas;
    }

    /** Una nota interna del tramite, o null si no existe o es de otro tramite. */
    public function notaInterna(int $tramiteId, int $notaId): ?NotaInterna
    {
        foreach ($this->notasInternas($tramiteId) as $nota) {
            if ($nota->id === $notaId) {
                return $nota;
            }
        }

        return null;
    }

    public function editarNotaInterna(int $notaId, DateTimeImmutable $escritaEl, string $texto): void
    {
        $stmt = $this->conexion->pdo()->prepare(
            'UPDATE tramite_nota_interna SET escrita_el = :escrita, texto = :texto WHERE id = :id'
        );
        $stmt->execute([
            'escrita' => $escritaEl->format(self::FORMATO_FECHA),
            'texto' => $texto,
            'id' => $notaId,
        ]);
    }

    public function eliminarNotaInterna(int $notaId): void
    {
        $stmt = $this->conexion->pdo()->prepare('DELETE FROM tramite_nota_interna WHERE id = :id');
        $stmt->execute(['id' => $notaId]);
    }

    /**
     * Borra el tramite entero. Eventos, novedades, notas internas y enlaces de acceso
     * caen solos por el ON DELETE CASCADE de sus FK: los enlaces que el cliente tenga
     * dejan de andar en el acto.
     */
    public function eliminar(int $tramiteId): void
    {
        $stmt = $this->conexion->pdo()->prepare('DELETE FROM tramite WHERE id = :id');
        $stmt->execute(['id' => $tramiteId]);
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
