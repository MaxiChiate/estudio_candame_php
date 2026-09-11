<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Conexion PDO perezosa a la base del portal de seguimiento.
 *
 * El proyecto no tiene container de DI (routes.php arma los servicios con `new` a
 * mano), asi que el "singleton lazy" es esto: una instancia que se pasa por
 * constructor y que recien abre el socket la primera vez que alguien llama pdo().
 *
 * Que sea perezoso importa por dos motivos:
 *  - con SEGUIMIENTO_ENABLED apagado las rutas ni se registran, pero ademas la home
 *    construye esta clase para el chequeo de la cookie: si abriera la conexion en el
 *    constructor, cada visita a la home pagaria un handshake con MySQL al pedo.
 *  - si la base esta caida, el fallo tiene que aparecer donde se usa (y poder
 *    atajarse), no al armar el grafo de servicios.
 */
final class Conexion
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $host,
        private readonly string $nombre,
        private readonly string $usuario,
        private readonly string $password,
        private readonly string $charset = 'utf8mb4',
    ) {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $this->host, $this->nombre, $this->charset);

        try {
            $this->pdo = new PDO($dsn, $this->usuario, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $e) {
            // El mensaje de PDO trae el DSN y a veces el usuario. No lo propagamos tal
            // cual: se loguea del lado del server y afuera va un mensaje seco.
            error_log('Seguimiento: fallo la conexion a la base: ' . $e->getMessage());

            throw new RuntimeException('No se pudo conectar a la base de datos.', 0, $e);
        }

        return $this->pdo;
    }

    /** True si ya hay socket abierto. Solo para tests: evita asserts por reflexion. */
    public function estaConectada(): bool
    {
        return $this->pdo instanceof PDO;
    }
}
