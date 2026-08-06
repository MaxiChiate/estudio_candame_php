<?php

declare(strict_types=1);

namespace EstudioCandame\Service;

/**
 * El capital minimo de una SAS es un multiplo del SMVM vigente (art. 40, Ley 27.349),
 * que el Consejo del Salario actualiza varias veces por anio. En vez de hardcodear el
 * valor, se consulta en vivo la API de series de tiempo de datos.gob.ar (serie oficial
 * de la Secretaria de Trabajo, id 57.1_SMVMM_0_M_34).
 *
 * A diferencia de la version Kotlin (que cacheaba en memoria del proceso), aca se
 * cachea en un archivo bajo var/cache: en PHP-FPM/CGI no hay un proceso de larga vida
 * que sobreviva entre requests. Si la API no responde se usa el valor de respaldo del
 * .env, que hay que mantener actualizado a mano como ultimo recurso.
 */
final class SmvmService
{
    private const CACHE_TTL_SECONDS = 6 * 3600;

    public function __construct(
        private readonly string $apiUrl,
        private readonly float $fallbackValor,
        private readonly string $fallbackFecha,
        private readonly int $multiploSmvm,
        private readonly string $cacheFile,
    ) {
    }

    public function obtenerCapitalMinimo(): CapitalSasInfo
    {
        $cached = $this->leerCache();
        if ($cached !== null) {
            return $cached;
        }

        $info = $this->fetchDesdeApi() ?? $this->fallback();
        $this->guardarCache($info);

        return $info;
    }

    private function leerCache(): ?CapitalSasInfo
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }

        $mtime = filemtime($this->cacheFile);
        if ($mtime === false || (time() - $mtime) > self::CACHE_TTL_SECONDS) {
            return null;
        }

        $contenido = file_get_contents($this->cacheFile);
        if ($contenido === false) {
            return null;
        }

        $data = json_decode($contenido, true);
        if (!is_array($data)) {
            return null;
        }

        try {
            return CapitalSasInfo::fromArray($data);
        } catch (\Throwable) {
            return null;
        }
    }

    private function guardarCache(CapitalSasInfo $info): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->cacheFile, json_encode($info->toArray()), LOCK_EX);
    }

    private function fetchDesdeApi(): ?CapitalSasInfo
    {
        try {
            $ch = curl_init($this->apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0 || $httpCode !== 200 || !is_string($body)) {
                return null;
            }

            $json = json_decode($body, true);
            $serie = $json['data'] ?? null;
            if (!is_array($serie) || $serie === []) {
                return null;
            }

            $ultimo = end($serie);
            $fecha = $ultimo[0] ?? null;
            $valor = $ultimo[1] ?? null;
            if (!is_string($fecha) || !is_numeric($valor)) {
                return null;
            }

            $valorFloat = (float) $valor;
            if ($valorFloat <= 0) {
                return null;
            }

            return $this->construir($valorFloat, $fecha, fuenteEnVivo: true);
        } catch (\Throwable) {
            // Si la API no responde (red, timeout, formato inesperado) se usa el
            // valor de respaldo; nunca se rompe el formulario por esto.
            return null;
        }
    }

    private function fallback(): CapitalSasInfo
    {
        return $this->construir($this->fallbackValor, $this->fallbackFecha, fuenteEnVivo: false);
    }

    private function construir(float $smvm, string $fecha, bool $fuenteEnVivo): CapitalSasInfo
    {
        $capital = round($smvm * $this->multiploSmvm, 2, PHP_ROUND_HALF_UP);

        return new CapitalSasInfo($smvm, $this->multiploSmvm, $capital, $fecha, $fuenteEnVivo);
    }
}
