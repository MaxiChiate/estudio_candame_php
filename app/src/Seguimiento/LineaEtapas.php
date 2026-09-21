<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

use DateTimeImmutable;

/**
 * Arma la linea de etapas que ve el cliente: que etapas recorre su tramite, cuales ya
 * ocurrieron y con que fecha, y en cual esta ahora.
 *
 * Que etapas se dibujan lo define el FLUJO del tramite (ver CatalogoFlujos), no un
 * pipeline unico: una constitucion de SRL pasa por escribania y edictos, una
 * designacion de autoridades de asociacion civil no. Antes habia una sola secuencia y
 * el operador salteaba a mano lo que no aplicaba; el cliente veia etapas que nunca le
 * iban a tocar.
 *
 * Una etapa esta CUMPLIDA si tiene al menos un evento en tramite_evento, o si se
 * dibuja ANTES de la actual. Lo primero no se deriva de comparar posiciones: el
 * recorrido no es lineal ni dentro del propio flujo. La vista es un loop (el inspector
 * puede despachar varias), asi que un tramite vuelve de VISTA_CONTESTADA a VISTA; con un
 * criterio solo posicional, al volver atras las etapas posteriores se des-completaban
 * solas. Como efecto de esto, una etapa POSTERIOR a la actual puede figurar cumplida --
 * y es lo correcto: el tramite efectivamente paso por ahi.
 *
 * Lo segundo cubre los saltos hacia adelante: si el operador pasa el tramite de una
 * etapa a otra varias mas adelante, las del medio no tienen evento, pero el cliente las
 * ve cumplidas (sin fecha), como si el tramite hubiera pasado por todas. Mostrarlas
 * pendientes detras de la actual le haria creer que algo quedo sin hacer. Las opcionales
 * salteadas siguen sin dibujarse: no hay por que anunciar una vista que no existio.
 *
 * Etapas FUERA DE FLUJO: el operador puede saltar a cualquier etapa del catalogo,
 * incluidas las que no pertenecen al flujo del tramite. Si eso paso -- hay un evento --
 * la etapa se dibuja igual, cumplida, ubicada entre sus vecinas segun el orden del
 * catalogo (Etapa::ordenCatalogo). Para el cliente es una etapa mas que ocurrio: la
 * linea NO le avisa que "no correspondia", porque no es informacion suya sino un detalle
 * de como el estudio carga los tramites. Una etapa fuera del flujo y sin eventos
 * simplemente no se dibuja.
 *
 * Las etapas marcadas 'opcional' en la config (las de la vista) no se anuncian de
 * antemano: si no ocurrieron, no aparecen. Un tramite puede terminar sin ninguna vista,
 * y mostrarla pendiente le anticiparia al cliente algo que quizas no pase.
 */
final class LineaEtapas
{
    public const CUMPLIDA = 'cumplida';
    public const ACTUAL = 'actual';
    public const PENDIENTE = 'pendiente';

    /**
     * @param EventoPublico[] $eventos
     *
     * @return list<array{valor: string, label: string, detalle: string, accion: ?string,
     *                    estado: string, fecha: ?DateTimeImmutable, repeticion: ?string,
     *                    fueraDeFlujo: bool}>
     */
    public static function construir(
        Flujo $flujo,
        Etapa $actual,
        array $eventos,
        CatalogoFlujos $catalogo,
    ): array {
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
        // Mientras no se llegue a la actual, todo lo que se dibuja quedo atras.
        $antesDeLaActual = true;
        foreach (self::etapasADibujar($flujo, $actual, array_keys($veces), $catalogo) as $etapa) {
            $cantidad = $veces[$etapa->value] ?? 0;
            $enElFlujo = $catalogo->pertenece($flujo, $etapa);

            if ($etapa === $actual) {
                $estado = self::ACTUAL;
                $antesDeLaActual = false;
            } elseif ($cantidad > 0 || $antesDeLaActual) {
                // Con evento, vale tanto para las del flujo como para las de afuera: si
                // el tramite paso por ahi, para el cliente es una etapa cumplida y nada
                // mas. Sin evento pero antes de la actual, es una etapa que el operador
                // salteo: el cliente la ve cumplida igual, porque el tramite ya esta
                // mas adelante y mostrarla pendiente le haria creer que falta.
                $estado = self::CUMPLIDA;
            } else {
                $estado = self::PENDIENTE;
            }

            $linea[] = [
                'valor' => $etapa->value,
                'label' => $catalogo->label($flujo, $etapa),
                'detalle' => $catalogo->detalle($flujo, $etapa),
                'accion' => $catalogo->accion($etapa),
                'estado' => $estado,
                // Solo tiene sentido mostrar fecha de lo que ya paso. Una salteada
                // queda cumplida sin fecha: no hay evento del que sacarla.
                'fecha' => $fechas[$etapa->value] ?? null,
                'repeticion' => self::repeticion($etapa, $cantidad, $catalogo),
                // No se usa en la vista publica: esta para el panel, donde si conviene
                // que se note que esa etapa no es del flujo del tramite.
                'fueraDeFlujo' => !$enElFlujo,
            ];
        }

        return $linea;
    }

    /**
     * Previsualizacion del recorrido de un flujo, sin tramite todavia: todas sus etapas,
     * en orden, sin fechas ni estado real. La usa la confirmacion del alta, donde hay
     * que mostrar la secuencia completa ANTES de guardar nada, porque el flujo no se
     * puede cambiar despues.
     *
     * A diferencia de la linea de un tramite, aca SI se muestran las etapas opcionales:
     * quien mira es el operador eligiendo un flujo, no el cliente, y lo que necesita ver
     * es el recorrido completo posible.
     *
     * @return list<array{valor: string, label: string, detalle: string, accion: ?string,
     *                    estado: string, fecha: ?DateTimeImmutable, repeticion: ?string,
     *                    fueraDeFlujo: bool}>
     */
    public static function previsualizar(Flujo $flujo, CatalogoFlujos $catalogo): array
    {
        return array_map(
            static fn (Etapa $etapa): array => [
                'valor' => $etapa->value,
                'label' => $catalogo->label($flujo, $etapa),
                'detalle' => $catalogo->detalle($flujo, $etapa),
                'accion' => $catalogo->accion($etapa),
                'estado' => self::PENDIENTE,
                'fecha' => null,
                'repeticion' => null,
                'fueraDeFlujo' => false,
            ],
            $catalogo->etapas($flujo),
        );
    }

    /**
     * Etapas que entran en la linea, ya ordenadas: las del flujo, mas las de afuera por
     * las que el tramite efectivamente paso, intercaladas donde corresponde.
     *
     * Las de afuera se ubican por orden de catalogo respecto de las del flujo. Funciona
     * porque todos los flujos avanzan en el mismo sentido que el catalogo (no hay flujo
     * que use B antes que A si en el catalogo A viene antes que B), asi que "la primera
     * del flujo que en el catalogo va despues" es un lugar bien definido. Lo que quede
     * sin ubicar -- etapas de catalogo posterior a todo el flujo -- va al final.
     *
     * @param string[] $etapasConEventos values de las etapas con al menos un evento
     *
     * @return list<Etapa>
     */
    private static function etapasADibujar(
        Flujo $flujo,
        Etapa $actual,
        array $etapasConEventos,
        CatalogoFlujos $catalogo,
    ): array {
        $ocurrieron = [];
        foreach ($etapasConEventos as $valor) {
            $etapa = Etapa::tryFrom($valor);
            if ($etapa !== null) {
                $ocurrieron[$etapa->value] = $etapa;
            }
        }
        // La etapa actual se dibuja siempre, aunque sea de afuera del flujo y todavia no
        // tenga evento propio.
        $ocurrieron[$actual->value] = $actual;

        $fueraDeFlujo = array_values(array_filter(
            $ocurrieron,
            static fn (Etapa $etapa): bool => !$catalogo->pertenece($flujo, $etapa),
        ));
        usort(
            $fueraDeFlujo,
            static fn (Etapa $a, Etapa $b): int => $a->ordenCatalogo() <=> $b->ordenCatalogo(),
        );

        $linea = [];
        foreach ($catalogo->etapas($flujo) as $delFlujo) {
            while ($fueraDeFlujo !== [] && $fueraDeFlujo[0]->ordenCatalogo() < $delFlujo->ordenCatalogo()) {
                $linea[] = array_shift($fueraDeFlujo);
            }

            // Las opcionales del flujo (las de la vista) no se anuncian: solo aparecen
            // si el tramite efectivamente paso por ahi.
            if (
                $catalogo->esOpcional($delFlujo)
                && $delFlujo !== $actual
                && !isset($ocurrieron[$delFlujo->value])
            ) {
                continue;
            }

            $linea[] = $delFlujo;
        }

        return [...$linea, ...$fueraDeFlujo];
    }

    /**
     * "2ª vista", "3ª vista": cuantas veces se registro una etapa que es un loop. Sale
     * de contar eventos, no de un campo guardado.
     *
     * El formato lo pone la config y no el codigo, porque el ordinal tiene que
     * concordar en genero con el label ("2ª vista", no "2ª tramite iniciado"). Una
     * etapa sin 'repeticion' en la config no muestra contador aunque se repita.
     */
    private static function repeticion(Etapa $etapa, int $cantidad, CatalogoFlujos $catalogo): ?string
    {
        $formato = $catalogo->formatoRepeticion($etapa);
        if ($formato === null || $cantidad < 2) {
            return null;
        }

        return sprintf($formato, (string) $cantidad);
    }
}
