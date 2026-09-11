<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

use DateTimeImmutable;

/**
 * Arma la linea de etapas que ve el cliente: para cada etapa del enum, si ya esta
 * cumplida (con la fecha del evento que la marco), si es la actual, o si todavia no
 * llego.
 *
 * Los labels salen de app/config/etapas.php, no del enum -- ver el comentario de Etapa.
 * Si una etapa no tiene entrada en la config (alguien la borro del archivo), se cae al
 * value del enum como label antes que romper la pagina.
 */
final class LineaEtapas
{
    public const CUMPLIDA = 'cumplida';
    public const ACTUAL = 'actual';
    public const PENDIENTE = 'pendiente';

    /**
     * @param EventoPublico[]                                     $eventos
     * @param array<string, array{label: string, detalle: string}> $config
     *
     * @return list<array{valor: string, label: string, detalle: string, estado: string, fecha: ?DateTimeImmutable}>
     */
    public static function construir(Etapa $actual, array $eventos, array $config): array
    {
        // Primera vez que cada etapa fue alcanzada. Si una etapa se registro dos veces
        // (p. ej. volvio atras y avanzo de nuevo) vale la primera: es la fecha en que
        // el tramite efectivamente paso por ahi.
        $fechas = [];
        foreach ($eventos as $evento) {
            $fechas[$evento->etapa->value] ??= $evento->ocurridoEl;
        }

        $linea = [];
        foreach (Etapa::cases() as $etapa) {
            if ($etapa === $actual) {
                $estado = self::ACTUAL;
            } elseif ($etapa->esAnteriorA($actual)) {
                $estado = self::CUMPLIDA;
            } else {
                $estado = self::PENDIENTE;
            }

            $linea[] = [
                'valor' => $etapa->value,
                'label' => $config[$etapa->value]['label'] ?? $etapa->value,
                'detalle' => $config[$etapa->value]['detalle'] ?? '',
                'estado' => $estado,
                // Solo tiene sentido mostrar fecha de lo que ya paso.
                'fecha' => $estado === self::PENDIENTE ? null : ($fechas[$etapa->value] ?? null),
            ];
        }

        return $linea;
    }

    /** @param array<string, array{label: string, detalle: string}> $config */
    public static function label(Etapa $etapa, array $config): string
    {
        return $config[$etapa->value]['label'] ?? $etapa->value;
    }
}
