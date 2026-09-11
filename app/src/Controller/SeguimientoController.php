<?php

declare(strict_types=1);

namespace EstudioCandame\Controller;

use EstudioCandame\Seguimiento\AccesoRepository;
use EstudioCandame\Seguimiento\CookieSeguimiento;
use EstudioCandame\Seguimiento\LineaEtapas;
use EstudioCandame\Seguimiento\TramiteRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Portal publico de seguimiento. Le muestra al cliente en que etapa esta su tramite y
 * nada mas: ni socios, ni documentos, ni identificaciones fiscales, ni descargas.
 *
 * Nunca revela por que un enlace no sirve. Token inexistente, revocado o malformado
 * comparten exactamente la misma respuesta -- misma pagina, mismo 404 -- porque
 * distinguirlos convertiria la ruta en un oraculo para saber que tokens existieron.
 */
final class SeguimientoController
{
    /** @param array<string, array{label: string, detalle: string}> $etapasConfig */
    public function __construct(
        private readonly Twig $twig,
        private readonly AccesoRepository $accesos,
        private readonly TramiteRepository $tramites,
        private readonly array $etapasConfig,
        private readonly string $basePath = '',
    ) {
    }

    /** @param array<string, string> $args */
    public function verEstado(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');

        // resolver() ya devuelve null indistintamente para formato invalido, token
        // inexistente y token revocado: no hay forma de diferenciarlos desde aca.
        $acceso = $this->accesos->resolver($token);
        if ($acceso === null) {
            return $this->noDisponible($request, $response);
        }

        $tramite = $this->tramites->porId($acceso->tramiteId);
        if ($tramite === null) {
            // Solo pasaria con la FK rota a mano. Misma respuesta que el resto.
            return $this->noDisponible($request, $response);
        }

        $this->accesos->registrarAcceso($acceso->accesoId);

        $eventos = $this->tramites->eventosPublicos($tramite->id);

        $response = $this->twig->render($response, 'seguimiento/estado.html.twig', [
            'pageTitle' => sprintf('Estado del trámite %s - Estudio Candame', $tramite->codigo),
            'metaDescription' => 'Estado de avance del trámite.',
            'metaKeywords' => '',
            'tramite' => $tramite,
            'linea' => LineaEtapas::construir($tramite->etapaActual, $eventos, $this->etapasConfig),
            'etapaActualLabel' => LineaEtapas::label($tramite->etapaActual, $this->etapasConfig),
            // Para el detalle bajo la linea: solo los eventos que tienen algo que decir.
            'eventos' => array_values(array_filter(
                $eventos,
                static fn ($evento): bool => $evento->notaPublica !== null && $evento->notaPublica !== '',
            )),
            'etapasConfig' => $this->etapasConfig,
        ]);

        return $this->conCabecerasPrivadas($response)
            ->withHeader(
                'Set-Cookie',
                CookieSeguimiento::valorSet($token, CookieSeguimiento::esHttps($request)),
            );
    }

    /**
     * Pagina informativa: que es el portal y como se lee cada etapa. Deliberadamente
     * SIN campo para ingresar un codigo -- un buscador publico haria enumerables los
     * tramites.
     */
    public function info(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'seguimiento/info.html.twig', [
            'pageTitle' => 'Seguimiento de trámites - Estudio Candame',
            'metaDescription' => 'Cómo funciona el seguimiento de trámites del estudio y qué significa cada etapa.',
            'metaKeywords' => '',
            'etapas' => $this->etapasConfig,
            'basePath' => $this->basePath,
        ]);
    }

    /**
     * "El enlace no esta disponible", 404. Es la respuesta unica para los tres motivos
     * de fallo. Ademas borra la cookie: si el visitante llego con un token que ya no
     * sirve, no tiene sentido seguir ofreciendole la barra en la home.
     */
    private function noDisponible(Request $request, Response $response): Response
    {
        $response = $this->twig->render($response->withStatus(404), 'seguimiento/no-disponible.html.twig', [
            'pageTitle' => 'Enlace no disponible - Estudio Candame',
            'metaDescription' => 'El enlace de seguimiento no está disponible.',
            'metaKeywords' => '',
            'basePath' => $this->basePath,
        ]);

        return $this->conCabecerasPrivadas($response)
            ->withHeader('Set-Cookie', CookieSeguimiento::valorBorrar(CookieSeguimiento::esHttps($request)));
    }

    /**
     * El token viaja en la URL: sin no-referrer, al hacer clic en el link de contacto
     * el token se filtraria en el Referer. noindex evita que un buscador que haya visto
     * el enlace lo publique, y no-store que quede cacheado en un equipo compartido.
     */
    private function conCabecerasPrivadas(Response $response): Response
    {
        return $response
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
