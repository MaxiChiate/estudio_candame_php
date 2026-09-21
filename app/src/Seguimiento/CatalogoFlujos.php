<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

use RuntimeException;

/**
 * Lee app/config/flujos.php y responde lo unico que el resto del portal necesita saber
 * de un flujo: que etapas lo componen, en que orden, y con que texto.
 *
 * Por que existe esta clase y no metodos en el enum Flujo: el orden de las etapas es
 * CONFIGURACION, no codigo (la doctora reordena y renombra sin que haya que tocar PHP
 * ni migrar datos, igual que con etapas.php). Un enum no puede depender de un archivo
 * de config sin volverse un singleton global, asi que el catalogo se instancia una vez
 * en routes.php con la config ya cargada y se inyecta a mano, que es como este proyecto
 * resuelve todo lo demas (no hay container de DI; ver Conexion).
 *
 * Validar la config es parte del trabajo: una etapa inexistente o repetida en un flujo
 * rompe la linea de etapas de un tramite real, asi que se detecta al construir el
 * catalogo -- al arrancar, no cuando un cliente abre su enlace.
 */
final class CatalogoFlujos
{
    /** @var array<string, list<Etapa>> */
    private array $etapasPorFlujo = [];

    /** @var array<string, array<string, array{label?: string, detalle?: string}>> */
    private array $overrides = [];

    /**
     * @param array<string, array{nombre: string, descripcion: string,
     *                            etapas: list<array{etapa: string, label?: string, detalle?: string}>}> $config
     * @param array<string, array{label: string, detalle: string, accion?: string, opcional?: bool,
     *                            repeticion?: string}>                                                 $etapasConfig
     */
    public function __construct(
        private readonly array $config,
        private readonly array $etapasConfig,
    ) {
        foreach (Flujo::cases() as $flujo) {
            $definicion = $this->config[$flujo->value] ?? throw new RuntimeException(
                sprintf('El flujo "%s" no esta definido en config/flujos.php.', $flujo->value),
            );

            $etapas = [];
            foreach ($definicion['etapas'] as $entrada) {
                $etapa = Etapa::tryFrom($entrada['etapa']) ?? throw new RuntimeException(sprintf(
                    'El flujo "%s" referencia la etapa "%s", que no existe en el catalogo.',
                    $flujo->value,
                    $entrada['etapa'],
                ));

                if (isset($this->etapasPorFlujo[$flujo->value][$etapa->value])) {
                    throw new RuntimeException(sprintf(
                        'El flujo "%s" repite la etapa "%s".',
                        $flujo->value,
                        $etapa->value,
                    ));
                }

                // Indexado por value mientras se arma, para detectar repetidas en O(1);
                // se reindexa a lista abajo, que es como lo consume todo el mundo.
                $this->etapasPorFlujo[$flujo->value][$etapa->value] = $etapa;

                $override = array_filter([
                    'label' => $entrada['label'] ?? null,
                    'detalle' => $entrada['detalle'] ?? null,
                ], static fn (?string $valor): bool => $valor !== null);
                if ($override !== []) {
                    $this->overrides[$flujo->value][$etapa->value] = $override;
                }

                $etapas[] = $etapa;
            }

            if ($etapas === []) {
                throw new RuntimeException(sprintf('El flujo "%s" no tiene ninguna etapa.', $flujo->value));
            }

            $this->etapasPorFlujo[$flujo->value] = $etapas;
        }
    }

    /** Arma el catalogo con las configs del proyecto. Atajo para routes.php y los tests. */
    public static function desdeConfig(string $appPath): self
    {
        return new self(
            require $appPath . '/config/flujos.php',
            require $appPath . '/config/etapas.php',
        );
    }

    /**
     * Etapas del flujo, en el orden en que se recorren. Este orden ES el pipeline.
     *
     * @return list<Etapa>
     */
    public function etapas(Flujo $flujo): array
    {
        return $this->etapasPorFlujo[$flujo->value];
    }

    /** Primera etapa del flujo: donde arranca todo tramite nuevo de ese tipo. */
    public function etapaInicial(Flujo $flujo): Etapa
    {
        return $this->etapas($flujo)[0];
    }

    public function pertenece(Flujo $flujo, Etapa $etapa): bool
    {
        return in_array($etapa, $this->etapas($flujo), true);
    }

    public function nombre(Flujo $flujo): string
    {
        return $this->config[$flujo->value]['nombre'];
    }

    public function descripcion(Flujo $flujo): string
    {
        return $this->config[$flujo->value]['descripcion'];
    }

    /**
     * Nombre visible de la etapa en este flujo: override del flujo, si no el generico de
     * etapas.php.
     *
     * Si la etapa no esta en ninguna de las dos configs es un error de programa, no un
     * dato faltante: antes se caia al value crudo del enum y el cliente veia
     * "copiando_actas_al_libro" en su linea de etapas. Preferimos romper visible.
     */
    public function label(Flujo $flujo, Etapa $etapa): string
    {
        return $this->overrides[$flujo->value][$etapa->value]['label']
            ?? $this->etapasConfig[$etapa->value]['label']
            ?? throw new RuntimeException(sprintf(
                'La etapa "%s" no tiene label en config/etapas.php.',
                $etapa->value,
            ));
    }

    public function detalle(Flujo $flujo, Etapa $etapa): string
    {
        return $this->overrides[$flujo->value][$etapa->value]['detalle']
            ?? $this->etapasConfig[$etapa->value]['detalle']
            ?? throw new RuntimeException(sprintf(
                'La etapa "%s" no tiene detalle en config/etapas.php.',
                $etapa->value,
            ));
    }

    /**
     * Pedido concreto al cliente para una etapa, si lo hay. No se puede pisar por flujo:
     * si una etapa le pide algo al cliente, se lo pide igual en todos.
     */
    public function accion(Etapa $etapa): ?string
    {
        return $this->etapasConfig[$etapa->value]['accion'] ?? null;
    }

    public function esOpcional(Etapa $etapa): bool
    {
        return (bool) ($this->etapasConfig[$etapa->value]['opcional'] ?? false);
    }

    /**
     * "2ª vista", "3ª vista": formato del contador de repeticiones de una etapa que es
     * un loop. null si la etapa no se repite o si la config no define formato.
     */
    public function formatoRepeticion(Etapa $etapa): ?string
    {
        return $this->etapasConfig[$etapa->value]['repeticion'] ?? null;
    }

    /**
     * Proximo paso que el panel ofrece de un click. Normalmente uno solo -- la siguiente
     * etapa del flujo -- y dos en las etapas donde la vista hace ambiguo el "siguiente"
     * (ver Etapa::sugerenciasDeVista).
     *
     * Si la etapa actual no pertenece al flujo (el operador salto a mano a una de afuera)
     * no hay "siguiente" que ofrecer: el panel muestra solo el select del detalle.
     *
     * @return list<Etapa>
     */
    public function siguientesSugeridas(Flujo $flujo, Etapa $actual): array
    {
        $sugerenciasDeVista = $actual->sugerenciasDeVista();
        if ($sugerenciasDeVista !== []) {
            // Se filtran contra el flujo por las dudas: un flujo sin loop de vista no
            // deberia ofrecer VISTA como proximo paso.
            $enElFlujo = array_values(array_filter(
                $sugerenciasDeVista,
                fn (Etapa $etapa): bool => $this->pertenece($flujo, $etapa),
            ));

            if ($enElFlujo !== []) {
                return $enElFlujo;
            }
        }

        $etapas = $this->etapas($flujo);
        $posicion = array_search($actual, $etapas, true);
        if ($posicion === false) {
            return [];
        }

        $siguiente = $etapas[$posicion + 1] ?? null;

        return $siguiente === null ? [] : [$siguiente];
    }
}
