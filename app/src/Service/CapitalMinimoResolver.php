<?php

declare(strict_types=1);

namespace EstudioCandame\Service;

use DateTimeImmutable;
use EstudioCandame\Model\TipoSocietario;

/**
 * Punto unico de resolucion del capital minimo por tipo societario: SAS delega en
 * SmvmService, SA lee capitales_minimos.php -- ninguno de los dos es bloqueante, son
 * solo un aviso informativo (decision del usuario: no trabar el envio de la consulta
 * por el capital). SRL no tiene piso. Se llama una sola vez por request y el resultado
 * se reutiliza tanto para validar como para armar la ficha.
 */
final class CapitalMinimoResolver
{
    /** @param array<string, array{capitalMinimo: float, norma: string, vigenciaDesde: string, ultimaVerificacion: string}> $capitalesMinimosConfig */
    public function __construct(
        private readonly SmvmService $smvmService,
        private readonly array $capitalesMinimosConfig,
    ) {
    }

    public function resolver(TipoSocietario $tipo): CapitalMinimoInfo
    {
        return match ($tipo) {
            TipoSocietario::SAS => $this->resolverSas(),
            TipoSocietario::SA => $this->resolverSa(),
            TipoSocietario::SRL => new CapitalMinimoInfo(TipoSocietario::SRL, null, false, 'La SRL no tiene capital mínimo legal.', ''),
        };
    }

    private function resolverSas(): CapitalMinimoInfo
    {
        $capital = $this->smvmService->obtenerCapitalMinimo();

        return new CapitalMinimoInfo(
            TipoSocietario::SAS,
            $capital->capital,
            false,
            sprintf('Mínimo legal: %d veces el SMVM vigente (art. 40, Ley 27.349).', $capital->multiplo),
            $capital->fechaSmvm,
        );
    }

    private function resolverSa(): CapitalMinimoInfo
    {
        $config = $this->capitalesMinimosConfig['SA'] ?? null;
        if ($config === null) {
            return new CapitalMinimoInfo(TipoSocietario::SA, null, false, '', '');
        }

        return new CapitalMinimoInfo(
            TipoSocietario::SA,
            (float) $config['capitalMinimo'],
            false,
            sprintf('%s, vigente desde el %s.', $config['norma'], self::fechaLegible((string) $config['vigenciaDesde'])),
            (string) $config['vigenciaDesde'],
        );
    }

    private static function fechaLegible(string $fechaIso): string
    {
        $fecha = DateTimeImmutable::createFromFormat('Y-m-d', $fechaIso);

        return $fecha !== false ? $fecha->format('d/m/Y') : $fechaIso;
    }
}
