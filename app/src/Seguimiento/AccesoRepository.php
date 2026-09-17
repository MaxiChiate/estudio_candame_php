<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

use DateTimeImmutable;
use EstudioCandame\Support\Reloj;

/**
 * Acceso a tramite_acceso: emision, resolucion y revocacion de los links del portal.
 *
 * El token en claro entra y sale de esta clase una sola vez, en emitir(). Todo lo demas
 * trabaja contra el sha256. No hay ningun metodo que devuelva un token guardado, porque
 * no hay ningun token guardado.
 */
final class AccesoRepository
{
    private const FORMATO_FECHA = 'Y-m-d H:i:s';

    /** Accesos del mismo enlace dentro de esta ventana cuentan como una sola visita. */
    public const VENTANA_VISITA_MINUTOS = 30;

    public function __construct(
        private readonly Conexion $conexion,
        private readonly Reloj $reloj,
        private readonly TokenGenerator $tokens,
    ) {
    }

    /**
     * Emite un acceso nuevo y devuelve el token EN CLARO. Es la unica oportunidad de
     * verlo: en base queda solo el hash. Quien llama tiene que mostrarlo y olvidarlo
     * -- no loguearlo ni guardarlo en una nota.
     */
    public function emitir(int $tramiteId, string $etiqueta): string
    {
        $token = $this->tokens->generar();

        $stmt = $this->conexion->pdo()->prepare(
            'INSERT INTO tramite_acceso (tramite_id, token_hash, etiqueta, creado_el, accesos)
             VALUES (:tramite, :hash, :etiqueta, :creado, 0)'
        );
        $stmt->execute([
            'tramite' => $tramiteId,
            'hash' => $this->tokens->hash($token),
            'etiqueta' => $etiqueta,
            'creado' => $this->reloj->ahora()->format(self::FORMATO_FECHA),
        ]);

        return $token;
    }

    /**
     * Busca el acceso vigente de un token en claro. SOLO LECTURA: no toca el contador
     * ni la fecha de ultimo acceso -- para eso esta registrarAcceso(), que se llama
     * aparte y a proposito. La barra de la home usa este metodo en cada carga, asi que
     * si algun dia sumara la visita aca, el contador se inflaria con cada visita a la
     * home.
     *
     * Devuelve null en los tres casos que el spec exige indistinguibles: formato
     * invalido, token inexistente y token revocado. El controller no puede diferenciar
     * aunque quisiera, asi que no hay forma de que se filtre por el status ni por el
     * texto de la respuesta.
     */
    public function buscarPorToken(string $token): ?AccesoVigente
    {
        // Se chequea el formato ANTES de tocar la base: cualquier basura que venga en
        // la URL se descarta sin gastar una consulta.
        if (!TokenGenerator::formatoValido($token)) {
            return null;
        }

        $stmt = $this->conexion->pdo()->prepare(
            'SELECT id, tramite_id FROM tramite_acceso WHERE token_hash = :hash AND revocado_el IS NULL'
        );
        $stmt->execute(['hash' => $this->tokens->hash($token)]);
        $fila = $stmt->fetch();

        return $fila === false ? null : new AccesoVigente((int) $fila['id'], (int) $fila['tramite_id']);
    }

    /**
     * Registra una visita: la fecha del ultimo acceso se actualiza siempre, pero el
     * contador solo suma si pasaron mas de VENTANA_VISITA_MINUTOS desde el acceso
     * anterior. El portal va con Cache-Control: no-store, asi que el navegador no guarda
     * la pagina para atras/adelante: cada recarga o atras/adelante es un GET real. Sin
     * la ventana, un cliente mirando un rato inflaba el contador de a varios.
     *
     * Lo llama unicamente SeguimientoController::verEstado, y solo para un GET real (ni
     * HEAD ni prefetch).
     */
    public function registrarAcceso(int $accesoId): void
    {
        $ahora = $this->reloj->ahora();

        // MySQL evalua las asignaciones del SET de izquierda a derecha: accesos tiene que
        // ir primero para comparar contra el ultimo_acceso_el ANTERIOR, no el nuevo.
        $stmt = $this->conexion->pdo()->prepare(
            'UPDATE tramite_acceso
             SET accesos = accesos + (ultimo_acceso_el IS NULL OR ultimo_acceso_el <= :limite),
                 ultimo_acceso_el = :ahora
             WHERE id = :id'
        );
        $stmt->execute([
            'limite' => $ahora->modify(sprintf('-%d minutes', self::VENTANA_VISITA_MINUTOS))->format(self::FORMATO_FECHA),
            'ahora' => $ahora->format(self::FORMATO_FECHA),
            'id' => $accesoId,
        ]);
    }

    public function revocar(int $accesoId): void
    {
        $stmt = $this->conexion->pdo()->prepare(
            'UPDATE tramite_acceso SET revocado_el = :ahora WHERE id = :id AND revocado_el IS NULL'
        );
        $stmt->execute([
            'ahora' => $this->reloj->ahora()->format(self::FORMATO_FECHA),
            'id' => $accesoId,
        ]);
    }

    public function porId(int $accesoId): ?TramiteAcceso
    {
        $stmt = $this->conexion->pdo()->prepare('SELECT * FROM tramite_acceso WHERE id = :id');
        $stmt->execute(['id' => $accesoId]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $this->desdeFila($fila);
    }

    /**
     * Accesos emitidos para un tramite, para el detalle del panel. No incluye el token
     * (no existe): solo etiqueta, estado y uso.
     *
     * @return TramiteAcceso[]
     */
    public function porTramite(int $tramiteId): array
    {
        $stmt = $this->conexion->pdo()->prepare(
            'SELECT * FROM tramite_acceso WHERE tramite_id = :id ORDER BY creado_el DESC, id DESC'
        );
        $stmt->execute(['id' => $tramiteId]);

        return array_map(fn (array $fila): TramiteAcceso => $this->desdeFila($fila), $stmt->fetchAll());
    }

    /** @param array<string, mixed> $fila */
    private function desdeFila(array $fila): TramiteAcceso
    {
        return new TramiteAcceso(
            (int) $fila['id'],
            (int) $fila['tramite_id'],
            (string) $fila['etiqueta'],
            new DateTimeImmutable((string) $fila['creado_el']),
            $fila['revocado_el'] !== null ? new DateTimeImmutable((string) $fila['revocado_el']) : null,
            $fila['ultimo_acceso_el'] !== null ? new DateTimeImmutable((string) $fila['ultimo_acceso_el']) : null,
            (int) $fila['accesos'],
        );
    }
}
