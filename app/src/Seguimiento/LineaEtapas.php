<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

use DateTimeImmutable;

/**
 * Arma la linea de etapas que ve el cliente: para cada etapa del enum, si ya esta
 * cumplida (con la fecha del ultimo evento que la registro), si es la actual, o si
 * todavia no paso por ahi.
 *
 * Una etapa esta CUMPLIDA si tiene al menos un evento en tramite_evento. No se deriva
 * de comparar posiciones contra la etapa actual: el pipeline no es lineal. La vista es
 * un loop (el inspector puede despachar varias), asi que un tramite vuelve de
 * VISTA_CONTESTADA a VISTA; con el criterio viejo, al volver atras las etapas previas
 * se des-completaban solas. Como efecto de esto, una etapa POSTERIOR a la actual puede
 * figurar cumplida -- y es lo correcto: el tramite efectivamente paso por ahi.
 *
 * Tambien hay etapas que no aplican a un tramite (DICTAMENES en una SAS por estatuto
 * modelo, por ejemplo) y se saltean: quedan pendientes para siempre, sin evento, y eso
 * no traba nada.
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
     * @param EventoPublico[]                                                                 $eventos
     * @param array<string, array{label: string, detalle: string, accion?: string,
     *                            repeticion?: string}>                                       $config
     *
     * @return list<array{valor: string, label: string, detalle: string, accion: ?string,
     *                    estado: string, fecha: ?DateTimeImmutable, repeticion: ?string}>
     */
    public static function construir(Etapa $actual, array $eventos, array $config): array
    {
        // Ultima vez que el tramite paso por cada etapa, y cuantas veces en total. La
        // fecha es la del ultimo evento: con el loop de la vista, lo que le importa al
        // cliente es cuando fue la vez mas reciente, no la primera.
        $fechas = [];
        $veces = [];
        foreach ($eventos as $evento) {
            $fechas[$evento->etapa->value] = $evento->ocurridoEl;
            $veces[$evento->etapa->value] = ($veces[$evento->etapa->value] ?? 0) + 1;
        }

        $linea = [];
        foreach (Etapa::cases() as $etapa) {
            $cantidad = $veces[$etapa->value] ?? 0;

            if ($etapa === $actual) {
                $estado = self::ACTUAL;
            } elseif ($cantidad > 0) {
                $estado = self::CUMPLIDA;
            } else {
                $estado = self::PENDIENTE;
            }

            $linea[] = [
                'valor' => $etapa->value,
                'label' => self::label($etapa, $config),
                'detalle' => $config[$etapa->value]['detalle'] ?? '',
                'accion' => $config[$etapa->value]['accion'] ?? null,
                'estado' => $estado,
                // Solo tiene sentido mostrar fecha de lo que ya paso.
                'fecha' => $fechas[$etapa->value] ?? null,
                'repeticion' => self::repeticion($etapa, $cantidad, $config),
            ];
        }

        return $linea;
    }

    /**
     * "2ª vista", "3ª vista": cuantas veces se registro una etapa que es un loop. Sale
     * de contar eventos, no de un campo guardado.
     *
     * El formato lo pone la config y no el codigo, porque el ordinal tiene que
     * concordar en genero con el label ("2ª vista", no "2ª tramite iniciado"). Una
     * etapa sin 'repeticion' en la config no muestra contador aunque se repita.
     *
     * @param array<string, array{label: string, detalle: string, accion?: string,
     *                            repeticion?: string}> $config
     */
    private static function repeticion(Etapa $etapa, int $cantidad, array $config): ?string
    {
        $formato = $config[$etapa->value]['repeticion'] ?? null;
        if ($formato === null || $cantidad < 2) {
            return null;
        }

        return sprintf($formato, (string) $cantidad);
    }

    /**
     * @param array<string, array{label: string, detalle: string, accion?: string,
     *                            repeticion?: string}> $config
     */
    public static function label(Etapa $etapa, array $config): string
    {
        return $config[$etapa->value]['label'] ?? $etapa->value;
    }

    /**
     * Pedido concreto al cliente para la etapa en curso, si lo hay. Se muestra
     * destacado y nunca colapsado: es lo unico de la pagina que le pide algo.
     *
     * @param array<string, array{label: string, detalle: string, accion?: string,
     *                            repeticion?: string}> $config
     */
    public static function accion(Etapa $etapa, array $config): ?string
    {
        return $config[$etapa->value]['accion'] ?? null;
    }
}
