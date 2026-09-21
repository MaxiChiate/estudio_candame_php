<?php

declare(strict_types=1);

namespace EstudioCandame\Controller;

use EstudioCandame\Seguimiento\AccesoRepository;
use EstudioCandame\Seguimiento\CatalogoFlujos;
use EstudioCandame\Seguimiento\Etapa;
use EstudioCandame\Seguimiento\Flujo;
use EstudioCandame\Seguimiento\LineaEtapas;
use EstudioCandame\Seguimiento\Tramite;
use EstudioCandame\Seguimiento\TramiteRepository;
use EstudioCandame\Support\AntiAbuso\CsrfToken;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Panel de la doctora. Protegido por AutenticacionBasica (HTTP Basic) desde routes.php.
 *
 * Prioridad de diseno: avanzar de etapa es UN click desde el listado. Es la accion que
 * se hace todos los dias; si cuesta, el portal deja de actualizarse y queda mintiendo.
 * El resto (notas, observaciones, accesos) vive en el detalle, donde molesta menos.
 *
 * Todos los POST llevan CSRF: HTTP Basic hace que el browser mande las credenciales
 * solo en cada request, asi que sin token un formulario de otro sitio podria avanzar
 * un tramite mientras la doctora tiene la sesion abierta.
 */
final class AdminTramitesController
{
    private const CLAVE_CSRF = CsrfToken::CLAVE_ADMIN_SEGUIMIENTO;

    public function __construct(
        private readonly Twig $twig,
        private readonly TramiteRepository $tramites,
        private readonly AccesoRepository $accesos,
        private readonly CatalogoFlujos $catalogo,
        private readonly string $basePath = '',
        private readonly string $appUrl = '',
    ) {
    }

    public function listado(Request $request, Response $response): Response
    {
        $tramites = $this->tramites->listado();

        $filas = [];
        foreach ($tramites as $tramite) {
            $filas[] = [
                'tramite' => $tramite,
                'etapaLabel' => $this->catalogo->label($tramite->flujo, $tramite->etapaActual),
                'flujoNombre' => $this->catalogo->nombre($tramite->flujo),
                'siguientes' => $this->siguientes($tramite),
            ];
        }

        return $this->render($response, 'admin/listado.html.twig', [
            'pageTitle' => 'Trámites - Panel',
            'filas' => $filas,
            'flash' => $this->tomarFlash(),
        ]);
    }

    public function formularioNuevo(Request $request, Response $response): Response
    {
        return $this->render($response, 'admin/nuevo.html.twig', [
            'pageTitle' => 'Nuevo trámite - Panel',
            'errores' => [],
            'valores' => ['denominacion' => '', 'flujo' => ''],
            'flujos' => $this->flujosParaElegir(),
        ]);
    }

    /**
     * Paso 2 del alta: muestra lo cargado y el recorrido COMPLETO del flujo elegido,
     * para confirmar. No escribe nada.
     *
     * El alta es en dos pasos por una sola razon: el flujo no se puede cambiar despues
     * de creado el tramite, asi que elegirlo mal se arregla borrando y volviendo a
     * empezar. Ver la secuencia entera antes de guardar es lo que evita eso.
     */
    public function previsualizar(Request $request, Response $response): Response
    {
        $datos = (array) $request->getParsedBody();

        if (!CsrfToken::validar($this->campo($datos, '_csrf'), self::CLAVE_CSRF)) {
            return $this->redirigir($response, '/admin/tramites');
        }

        $denominacion = $this->campo($datos, 'denominacion');
        $flujoPedido = $this->campo($datos, 'flujo');
        $flujo = Flujo::tryFrom($flujoPedido);
        $errores = $this->validarAlta($denominacion, $flujo);

        if ($errores !== [] || $flujo === null) {
            return $this->formularioConErrores($response, $errores, $denominacion, $flujoPedido);
        }

        return $this->render($response, 'admin/previsualizacion.html.twig', [
            'pageTitle' => 'Confirmar el nuevo trámite - Panel',
            'valores' => ['denominacion' => $denominacion, 'flujo' => $flujo->value],
            'flujoNombre' => $this->catalogo->nombre($flujo),
            'flujoDescripcion' => $this->catalogo->descripcion($flujo),
            // Mismo partial que la vista publica: lo que se confirma es exactamente lo
            // que va a ver el cliente.
            'linea' => LineaEtapas::previsualizar($flujo, $this->catalogo),
        ]);
    }

    /**
     * "Volver a editar" desde la confirmacion: repuebla el paso 1 con lo que se habia
     * cargado. Sin errores -- no es un rechazo, es que la doctora cambio de idea.
     */
    public function volverAEditar(Request $request, Response $response): Response
    {
        $datos = (array) $request->getParsedBody();

        if (!CsrfToken::validar($this->campo($datos, '_csrf'), self::CLAVE_CSRF)) {
            return $this->redirigir($response, '/admin/tramites');
        }

        return $this->render($response, 'admin/nuevo.html.twig', [
            'pageTitle' => 'Nuevo trámite - Panel',
            'errores' => [],
            'valores' => [
                'denominacion' => $this->campo($datos, 'denominacion'),
                'flujo' => $this->campo($datos, 'flujo'),
            ],
            'flujos' => $this->flujosParaElegir(),
        ]);
    }

    /**
     * Paso 3: crea. Revalida todo -- este endpoint NO confia en que se haya pasado por
     * la previsualizacion, que es solo una pantalla y no deja nada guardado.
     */
    public function crear(Request $request, Response $response): Response
    {
        $datos = (array) $request->getParsedBody();

        if (!CsrfToken::validar($this->campo($datos, '_csrf'), self::CLAVE_CSRF)) {
            return $this->redirigir($response, '/admin/tramites');
        }

        $denominacion = $this->campo($datos, 'denominacion');
        $flujoPedido = $this->campo($datos, 'flujo');
        $flujo = Flujo::tryFrom($flujoPedido);
        $errores = $this->validarAlta($denominacion, $flujo);

        if ($errores !== [] || $flujo === null) {
            return $this->formularioConErrores($response, $errores, $denominacion, $flujoPedido);
        }

        // La referencia la genera el repositorio al crear: no se pide ni se valida acá.
        $id = $this->tramites->crear($denominacion, $flujo, $this->catalogo->etapaInicial($flujo));
        $tramite = $this->tramites->porId($id);
        $this->flash(sprintf(
            'Trámite %s creado como %s.',
            $tramite?->referencia ?? '',
            $this->catalogo->nombre($flujo),
        ));

        return $this->redirigir($response, '/admin/tramites/' . $id);
    }

    /**
     * Validaciones del alta, iguales para la previsualizacion y para la creacion.
     *
     * @return list<string>
     */
    private function validarAlta(string $denominacion, ?Flujo $flujo): array
    {
        $errores = [];

        if ($denominacion === '') {
            $errores[] = 'La denominación es obligatoria.';
        } elseif (mb_strlen($denominacion) > 255) {
            $errores[] = 'La denominación no puede superar los 255 caracteres.';
        }

        if ($flujo === null) {
            $errores[] = 'Elegí el tipo de trámite.';
        }

        return $errores;
    }

    /**
     * Vuelve al form del paso 1 con lo cargado y los errores. El flujo pedido se
     * devuelve tal cual vino (aunque sea invalido) solo para no perder la seleccion; el
     * template lo compara contra los flujos reales.
     *
     * @param list<string> $errores
     */
    private function formularioConErrores(
        Response $response,
        array $errores,
        string $denominacion,
        string $flujoPedido,
    ): Response {
        return $this->render($response->withStatus(422), 'admin/nuevo.html.twig', [
            'pageTitle' => 'Nuevo trámite - Panel',
            'errores' => $errores,
            'valores' => ['denominacion' => $denominacion, 'flujo' => $flujoPedido],
            'flujos' => $this->flujosParaElegir(),
        ]);
    }

    /**
     * Opciones del selector de tipo de tramite. Solo el nombre: la descripcion se
     * muestra en la confirmacion, junto al recorrido, que es donde sirve para decidir.
     *
     * @return list<array{valor: string, nombre: string}>
     */
    private function flujosParaElegir(): array
    {
        return array_map(
            fn (Flujo $flujo): array => [
                'valor' => $flujo->value,
                'nombre' => $this->catalogo->nombre($flujo),
            ],
            Flujo::cases(),
        );
    }

    /** @param array<string, string> $args */
    public function detalle(Request $request, Response $response, array $args): Response
    {
        $tramite = $this->tramites->porId((int) ($args['id'] ?? 0));
        if ($tramite === null) {
            return $this->redirigir($response, '/admin/tramites');
        }

        return $this->render($response, 'admin/detalle.html.twig', [
            'pageTitle' => sprintf('%s - Panel', $tramite->referencia),
            'tramite' => $tramite,
            'etapaLabel' => $this->catalogo->label($tramite->flujo, $tramite->etapaActual),
            // Dato de solo lectura: el flujo se eligio al crear y no se puede cambiar.
            'flujoNombre' => $this->catalogo->nombre($tramite->flujo),
            'eventos' => $this->tramites->eventos($tramite->id),
            'accesos' => $this->accesos->porTramite($tramite->id),
            // El select ofrece TODO el catalogo -- saltar fuera del flujo sigue siendo
            // legal -- pero primero las del flujo, en su orden, que es el 99% de los
            // casos.
            'etapasDelFlujo' => $this->opciones($tramite, $this->catalogo->etapas($tramite->flujo)),
            'etapasFueraDelFlujo' => $this->opciones($tramite, array_values(array_filter(
                Etapa::cases(),
                fn (Etapa $etapa): bool => !$this->catalogo->pertenece($tramite->flujo, $etapa),
            ))),
            'siguientes' => $this->siguientes($tramite),
            // Labels resueltos con el flujo, para que el historial muestre el mismo
            // texto que la linea del cliente cuando la etapa tiene override.
            'labelPorEtapa' => $this->labelPorEtapa($tramite),
            'flash' => $this->tomarFlash(),
            // El token en claro se muestra UNA vez, en el redirect posterior a emitirlo.
            'tokenNuevo' => $this->tomarTokenNuevo(),
            'appUrl' => rtrim($this->appUrl, '/'),
        ]);
    }

    /** @param array<string, string> $args */
    public function avanzar(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $datos = (array) $request->getParsedBody();

        if (!CsrfToken::validar($this->campo($datos, '_csrf'), self::CLAVE_CSRF)) {
            return $this->redirigir($response, '/admin/tramites');
        }

        $tramite = $this->tramites->porId($id);
        if ($tramite === null) {
            return $this->redirigir($response, '/admin/tramites');
        }

        // Si no viene etapa explicita, avanza a la siguiente DEL FLUJO del tramite: ese
        // es el camino de un click desde el listado. Con etapa explicita se puede ir a
        // CUALQUIERA del catalogo, incluidas anteriores (el loop de la vista) y las que
        // no pertenecen al flujo. Lo unico que se valida es que sea un valor del enum:
        // el operador sabe lo que hace y el portal no le discute el recorrido.
        $etapaPedida = $this->campo($datos, 'etapa');

        if ($etapaPedida !== '') {
            $etapa = Etapa::tryFrom($etapaPedida);
            if ($etapa === null) {
                $this->flash('La etapa indicada no existe.', 'error');

                return $this->redirigir($response, $this->volverA($datos, $id));
            }
        } else {
            $etapa = $this->catalogo->siguientesSugeridas($tramite->flujo, $tramite->etapaActual)[0] ?? null;
            if ($etapa === null) {
                $this->flash(sprintf('El trámite %s ya está en la última etapa.', $tramite->referencia), 'error');

                return $this->redirigir($response, $this->volverA($datos, $id));
            }
        }

        $this->tramites->avanzar(
            $id,
            $etapa,
            $this->campoONull($datos, 'nota_publica'),
            $this->campoONull($datos, 'nota_interna'),
        );

        $this->flash(sprintf(
            '%s pasó a %s.',
            $tramite->referencia,
            $this->catalogo->label($tramite->flujo, $etapa),
        ));

        return $this->redirigir($response, $this->volverA($datos, $id));
    }

    /**
     * Atajos de "proximo paso" para los botones del panel: normalmente uno solo -- la
     * siguiente etapa del flujo del tramite -- y dos donde la vista hace ambiguo el
     * siguiente (ver CatalogoFlujos::siguientesSugeridas).
     *
     * @return list<array{valor: string, label: string}>
     */
    private function siguientes(Tramite $tramite): array
    {
        return $this->opciones(
            $tramite,
            $this->catalogo->siguientesSugeridas($tramite->flujo, $tramite->etapaActual),
        );
    }

    /**
     * Label de cada etapa del catalogo resuelto con el flujo del tramite. Para el
     * historial de eventos, que puede incluir etapas de afuera del flujo.
     *
     * @return array<string, string>
     */
    private function labelPorEtapa(Tramite $tramite): array
    {
        $labels = [];
        foreach (Etapa::cases() as $etapa) {
            $labels[$etapa->value] = $this->catalogo->label($tramite->flujo, $etapa);
        }

        return $labels;
    }

    /**
     * Etapas listas para un <select> o un boton: value y label resuelto con el flujo del
     * tramite, marcando cual es la actual.
     *
     * @param list<Etapa> $etapas
     *
     * @return list<array{valor: string, label: string, actual: bool}>
     */
    private function opciones(Tramite $tramite, array $etapas): array
    {
        return array_map(
            fn (Etapa $etapa): array => [
                'valor' => $etapa->value,
                'label' => $this->catalogo->label($tramite->flujo, $etapa),
                'actual' => $etapa === $tramite->etapaActual,
            ],
            $etapas,
        );
    }

    /** @param array<string, string> $args */
    public function observacion(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $datos = (array) $request->getParsedBody();

        if (!CsrfToken::validar($this->campo($datos, '_csrf'), self::CLAVE_CSRF)) {
            return $this->redirigir($response, '/admin/tramites');
        }

        $tramite = $this->tramites->porId($id);
        if ($tramite === null) {
            return $this->redirigir($response, '/admin/tramites');
        }

        // Toggle: el estado nuevo es el contrario del actual.
        $observado = !$tramite->observado;
        $this->tramites->actualizarObservacion($id, $observado, $this->campoONull($datos, 'nota_observacion'));

        $this->flash($observado
            ? sprintf('%s quedó marcado como observado.', $tramite->referencia)
            : sprintf('Se levantó la observación de %s.', $tramite->referencia));

        return $this->redirigir($response, '/admin/tramites/' . $id);
    }

    /** @param array<string, string> $args */
    public function emitirAcceso(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $datos = (array) $request->getParsedBody();

        if (!CsrfToken::validar($this->campo($datos, '_csrf'), self::CLAVE_CSRF)) {
            return $this->redirigir($response, '/admin/tramites');
        }

        $tramite = $this->tramites->porId($id);
        if ($tramite === null) {
            return $this->redirigir($response, '/admin/tramites');
        }

        $etiqueta = $this->campo($datos, 'etiqueta');
        if ($etiqueta === '') {
            $this->flash('Poné una etiqueta para saber a quién se le dio el enlace.', 'error');

            return $this->redirigir($response, '/admin/tramites/' . $id);
        }

        $token = $this->accesos->emitir($id, mb_substr($etiqueta, 0, 120));

        // Unica vez que el token existe en claro fuera de la URL del cliente. Va por
        // sesion (no por query string) para que no quede en el historial ni en los logs
        // de acceso del servidor, y se consume en el primer render del detalle.
        $_SESSION['seguimientoTokenNuevo'] = $token;

        return $this->redirigir($response, '/admin/tramites/' . $id);
    }

    /** @param array<string, string> $args */
    public function revocarAcceso(Request $request, Response $response, array $args): Response
    {
        $accesoId = (int) ($args['id'] ?? 0);
        $datos = (array) $request->getParsedBody();

        if (!CsrfToken::validar($this->campo($datos, '_csrf'), self::CLAVE_CSRF)) {
            return $this->redirigir($response, '/admin/tramites');
        }

        $acceso = $this->accesos->porId($accesoId);
        if ($acceso === null) {
            return $this->redirigir($response, '/admin/tramites');
        }

        $this->accesos->revocar($accesoId);
        $this->flash(sprintf('Se revocó el enlace de "%s".', $acceso->etiqueta));

        return $this->redirigir($response, '/admin/tramites/' . $acceso->tramiteId);
    }

    /**
     * Vuelve al listado o al detalle segun de donde vino el POST, para que avanzar
     * desde el listado no te saque del listado.
     *
     * @param array<string, mixed> $datos
     */
    private function volverA(array $datos, int $id): string
    {
        return $this->campo($datos, 'volver') === 'listado'
            ? '/admin/tramites'
            : '/admin/tramites/' . $id;
    }

    /** @param array<string, mixed> $datos */
    private function campo(array $datos, string $clave): string
    {
        $valor = $datos[$clave] ?? '';

        return is_string($valor) ? trim($valor) : '';
    }

    /** @param array<string, mixed> $datos */
    private function campoONull(array $datos, string $clave): ?string
    {
        $valor = $this->campo($datos, $clave);

        return $valor === '' ? null : $valor;
    }

    /** @param array<string, mixed> $datos */
    private function render(Response $response, string $template, array $datos): Response
    {
        $datos['metaDescription'] = '';
        $datos['metaKeywords'] = '';
        $datos['basePath'] = $this->basePath;
        $datos['csrf'] = CsrfToken::generar(self::CLAVE_CSRF);

        return $this->twig->render($response, $template, $datos)
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Cache-Control', 'no-store, private');
    }

    private function redirigir(Response $response, string $ruta): Response
    {
        return $response->withHeader('Location', $this->basePath . $ruta)->withStatus(302);
    }

    private function flash(string $mensaje, string $tipo = 'ok'): void
    {
        $_SESSION['seguimientoFlash'] = ['mensaje' => $mensaje, 'tipo' => $tipo];
    }

    /** @return array{mensaje: string, tipo: string}|null */
    private function tomarFlash(): ?array
    {
        $flash = $_SESSION['seguimientoFlash'] ?? null;
        unset($_SESSION['seguimientoFlash']);

        return is_array($flash) ? $flash : null;
    }

    private function tomarTokenNuevo(): ?string
    {
        $token = $_SESSION['seguimientoTokenNuevo'] ?? null;
        unset($_SESSION['seguimientoTokenNuevo']);

        return is_string($token) ? $token : null;
    }
}
