<?php

declare(strict_types=1);

namespace EstudioCandame\Service;

use EstudioCandame\Model\TipoSocietario;

/**
 * Punto unico de resolucion del capital sugerido por tipo societario. Ninguno de los
 * tres tipos es bloqueante: el capital sugerido es siempre un aviso informativo, nunca
 * traba el envio de la consulta (decision del usuario). SAS delega en SmvmService
 * (2 x SMVM vigente); SRL y SA leen capitales_minimos.php ($500.000 sugeridos). Se
 * llama una sola vez por request y el resultado se reutiliza tanto para validar como
 * para armar la ficha.
 */
final class CapitalMinimoResolver
{
    /** @param array<string, array{capitalSugerido: float, detalle: string, ultimaVerificacion: string}> $capitalesMinimosConfig */
    public function __construct(
        private readonly SmvmService $smvmService,
        private readonly array $capitalesMinimosConfig,
    ) {
    }

    public function resolver(TipoSocietario $tipo): CapitalMinimoInfo
    {
        return match ($tipo) {
            TipoSocietario::SAS => $this->resolverSas(),
            TipoSocietario::SA, TipoSocietario::SRL => $this->resolverSugerido($tipo),
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

    private function resolverSugerido(TipoSocietario $tipo): CapitalMinimoInfo
    {
        $config = $this->capitalesMinimosConfig[$tipo->value] ?? null;
        if ($config === null) {
            return new CapitalMinimoInfo($tipo, null, false, '', '');
        }

        return new CapitalMinimoInfo(
            $tipo,
            (float) $config['capitalSugerido'],
            false,
            (string) $config['detalle'],
            '',
        );
    }
}
