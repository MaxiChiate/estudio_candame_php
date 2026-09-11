<?php

declare(strict_types=1);

namespace EstudioCandame\Controller;

use EstudioCandame\Seguimiento\AccesoRepository;
use EstudioCandame\Seguimiento\Etapa;
use EstudioCandame\Seguimiento\LineaEtapas;
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

    /** @param array<string, array{label: string, detalle: string}> $etapasConfig */
    public function __construct(
        private readonly Twig $twig,
        private readonly TramiteRepository $tramites,
        private readonly AccesoRepository $accesos,
        private readonly array $etapasConfig,
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
                'etapaLabel' => LineaEtapas::label($tramite->etapaActual, $this->etapasConfig),
                'siguiente' => $tramite->etapaActual->siguiente(),
                'siguienteLabel' => $tramite->etapaActual->siguiente() !== null
                    ? LineaEtapas::label($tramite->etapaActual->siguiente(), $this->etapasConfig)
                    : null,
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
            'valores' => ['codigo' => '', 'denominacion' => '', 'ruta_estatuto' => 'MODELO'],
        ]);
    }

    public function crear(Request $request, Response $response): Response
    {
        $datos = (array) $request->getParsedBody();

        if (!CsrfToken::validar($this->campo($datos, '_csrf'), self::CLAVE_CSRF)) {
            return $this->redirigir($response, '/admin/tramites');
        }

        $codigo = $this->campo($datos, 'codigo');
        $denominacion = $this->campo($datos, 'denominacion');
        $rutaEstatuto = $this->campo($datos, 'ruta_estatuto') === 'LIBRE' ? 'LIBRE' : 'MODELO';

        // Se juntan todos los errores, nunca se corta en el primero: mismo criterio que
        // la validacion de la consulta de constitucion.
        $errores = [];
        if ($codigo === '') {
            $errores[] = 'El código es obligatorio.';
        } elseif (mb_strlen($codigo) > 20) {
            $errores[] = 'El código no puede superar los 20 caracteres.';
        } elseif ($this->tramites->porCodigo($codigo) !== null) {
            $errores[] = sprintf('Ya existe un trámite con el código %s.', $codigo);
        }

        if ($denominacion === '') {
            $errores[] = 'La denominación es obligatoria.';
        } elseif (mb_strlen($denominacion) > 255) {
            $errores[] = 'La denominación no puede superar los 255 caracteres.';
        }

        if ($errores !== []) {
            return $this->render($response->withStatus(422), 'admin/nuevo.html.twig', [
                'pageTitle' => 'Nuevo trámite - Panel',
                'errores' => $errores,
                'valores' => ['codigo' => $codigo, 'denominacion' => $denominacion, 'ruta_estatuto' => $rutaEstatuto],
            ]);
        }

        $id = $this->tramites->crear($codigo, $denominacion, $rutaEstatuto);
        $this->flash(sprintf('Trámite %s creado.', $codigo));

        return $this->redirigir($response, '/admin/tramites/' . $id);
    }

    /** @param array<string, string> $args */
    public function detalle(Request $request, Response $response, array $args): Response
    {
        $tramite = $this->tramites->porId((int) ($args['id'] ?? 0));
        if ($tramite === null) {
            return $this->redirigir($response, '/admin/tramites');
        }

        return $this->render($response, 'admin/detalle.html.twig', [
            'pageTitle' => sprintf('%s - Panel', $tramite->codigo),
            'tramite' => $tramite,
            'etapaLabel' => LineaEtapas::label($tramite->etapaActual, $this->etapasConfig),
            'eventos' => $this->tramites->eventos($tramite->id),
            'accesos' => $this->accesos->porTramite($tramite->id),
            'etapas' => Etapa::cases(),
            'etapasConfig' => $this->etapasConfig,
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

        // Si no viene etapa explicita, avanza a la siguiente: ese es el camino de un
        // click desde el listado.
        $etapaPedida = $this->campo($datos, 'etapa');
        $etapa = $etapaPedida !== '' ? Etapa::tryFrom($etapaPedida) : $tramite->etapaActual->siguiente();

        if ($etapa === null) {
            $this->flash(sprintf('El trámite %s ya está en la última etapa.', $tramite->codigo), 'error');

            return $this->redirigir($response, $this->volverA($datos, $id));
        }

        $this->tramites->avanzar(
            $id,
            $etapa,
            $this->campoONull($datos, 'nota_publica'),
            $this->campoONull($datos, 'nota_interna'),
        );

        $this->flash(sprintf(
            '%s pasó a %s.',
            $tramite->codigo,
            LineaEtapas::label($etapa, $this->etapasConfig),
        ));

        return $this->redirigir($response, $this->volverA($datos, $id));
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
            ? sprintf('%s quedó marcado como observado.', $tramite->codigo)
            : sprintf('Se levantó la observación de %s.', $tramite->codigo));

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
        $datos['etapasConfig'] ??= $this->etapasConfig;

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
