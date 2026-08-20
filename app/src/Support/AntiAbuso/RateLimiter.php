<?php

declare(strict_types=1);

namespace EstudioCandame\Support\AntiAbuso;

/**
 * Limite de envios por IP en archivo JSON -- no hay Redis en el hosting compartido.
 * Mismo patron de cache por archivo (LOCK_EX al escribir) que SmvmService.
 */
final class RateLimiter
{
    public function __construct(
        private readonly string $storageFile,
        private readonly int $ventanaSegundos,
        private readonly int $maxEnvios,
    ) {
    }

    public function permitir(string $ip): bool
    {
        return count($this->timestampsVigentes($this->leer(), $ip)) < $this->maxEnvios;
    }

    public function registrar(string $ip): void
    {
        $estado = $this->leer();
        $timestamps = $this->timestampsVigentes($estado, $ip);
        $timestamps[] = time();
        $estado[$ip] = $timestamps;

        $this->guardar($estado);
    }

    /**
     * @param array<string, int[]> $estado
     * @return int[]
     */
    private function timestampsVigentes(array $estado, string $ip): array
    {
        $limite = time() - $this->ventanaSegundos;

        return array_values(array_filter($estado[$ip] ?? [], static fn (int $ts): bool => $ts > $limite));
    }

    /** @return array<string, int[]> */
    private function leer(): array
    {
        if (!is_file($this->storageFile)) {
            return [];
        }

        $contenido = file_get_contents($this->storageFile);
        if ($contenido === false) {
            return [];
        }

        $data = json_decode($contenido, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Poda entradas vencidas de TODAS las IPs al guardar, para que el archivo no crezca
     * sin limite con el tiempo.
     *
     * @param array<string, int[]> $estado
     */
    private function guardar(array $estado): void
    {
        $dir = dirname($this->storageFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $podado = [];
        foreach ($estado as $ip => $timestamps) {
            $vigentes = $this->timestampsVigentes($estado, $ip);
            if ($vigentes !== []) {
                $podado[$ip] = $vigentes;
            }
        }

        @file_put_contents($this->storageFile, json_encode($podado), LOCK_EX);
    }
}
