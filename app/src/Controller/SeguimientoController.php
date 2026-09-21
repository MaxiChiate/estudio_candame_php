<?php

declare(strict_types=1);

namespace EstudioCandame\Controller;

use EstudioCandame\Seguimiento\AccesoRepository;
use EstudioCandame\Seguimiento\CatalogoFlujos;
use EstudioCandame\Seguimiento\CookieSeguimiento;
use EstudioCandame\Seguimiento\EventoPublico;
use EstudioCandame\Seguimiento\LineaEtapas;
use EstudioCandame\Seguimiento\Tramite;
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
    /**
     * $etapasConfig se sigue inyectando aparte del catalogo porque la pagina
     * informativa lista el catalogo entero de etapas, sin flujo de por medio. Todo lo
     * que dependa del tramite pasa por $catalogo, que es quien sabe el orden y los
     * textos del flujo.
     *
     * @param array<string, array{label: string, detalle: string, accion?: string, opcional?: bool,
     *                            repeticion?: string}> $etapasConfig
     */
    public function __construct(
        private readonly Twig $twig,
        private readonly AccesoRepository $accesos,
        private readonly TramiteRepository $tramites,
        private readonly CatalogoFlujos $catalogo,
        private readonly array $etapasConfig,
        private readonly string $basePath = '',
    ) {
    }

    /** @param array<string, string> $args */
    public function verEstado(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');

        // buscarPorToken() ya devuelve null indistintamente para formato invalido, token
        // inexistente y token revocado: no hay forma de diferenciarlos desde aca.
        $acceso = $this->accesos->buscarPorToken($token);
        if ($acceso === null) {
            return $this->noDisponible($request, $response);
        }

        $tramite = $this->tramites->porId($acceso->tramiteId);
        if ($tramite === null) {
            // Solo pasaria con la FK rota a mano. Misma respuesta que el resto.
            return $this->noDisponible($request, $response);
        }

        if (self::esVisitaReal($request)) {
            $this->accesos->registrarAcceso($acceso->accesoId);
        }

        $eventos = $this->tramites->eventosPublicos($tramite->id);

        $response = $this->twig->render($response, 'seguimiento/estado.html.twig', [
            'pageTitle' => sprintf('Estado del trámite %s - Estudio Candame', $tramite->referencia),
            'metaDescription' => 'Estado de avance del trámite.',
            'metaKeywords' => '',
            'tramite' => $tramite,
            // Que etapas se dibujan y en que orden lo define el flujo del tramite.
            'linea' => LineaEtapas::construir(
                $tramite->flujo,
                $tramite->etapaActual,
                $eventos,
                $this->catalogo,
            ),
            'etapaActualLabel' => $this->catalogo->label($tramite->flujo, $tramite->etapaActual),
            // Lo unico de la pagina que le pide algo al cliente: va destacado arriba de
            // todo y nunca colapsado.
            'accionActual' => $this->catalogo->accion($tramite->etapaActual),
            // Para el detalle bajo la linea: solo los eventos que tienen algo que decir.
            // El label se resuelve aca, con el flujo, para que una etapa con override lo
            // muestre igual que en la linea.
            'novedades' => $this->novedades($tramite, $eventos),
        ]);

        return $this->conCabecerasPrivadas($response)
            ->withHeader(
                'Set-Cookie',
                CookieSeguimiento::valorSet($token, CookieSeguimiento::esHttps($request)),
            );
    }

    /**
     * Eventos con nota publica, listos para el template: fecha, etapa con el label del
     * flujo, y la nota. Los que no tienen nada que decir no se listan.
     *
     * @param EventoPublico[] $eventos
     *
     * @return list<array{fecha: \DateTimeImmutable, label: string, nota: string}>
     */
    private function novedades(Tramite $tramite, array $eventos): array
    {
        $novedades = [];
        foreach ($eventos as $evento) {
            if ($evento->notaPublica === null || $evento->notaPublica === '') {
                continue;
            }

            $novedades[] = [
                'fecha' => $evento->ocurridoEl,
                'label' => $this->catalogo->label($tramite->flujo, $evento->etapa),
                'nota' => $evento->notaPublica,
            ];
        }

        return $novedades;
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
     * Solo un GET real cuenta como visita. La ruta se registra con get(), pero FastRoute
     * despacha los HEAD a las rutas GET por su cuenta (RegexBasedAbstract::dispatch), asi
     * que un HEAD llega igual a verEstado. Los prefetch del navegador (Sec-Purpose en
     * Chrome, Purpose/X-Moz en los mas viejos) tampoco son una visita: se sirven normal,
     * pero no suman.
     */
    private static function esVisitaReal(Request $request): bool
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return false;
        }

        foreach (['Sec-Purpose', 'Purpose', 'X-Moz'] as $cabecera) {
            if (str_contains(strtolower($request->getHeaderLine($cabecera)), 'prefetch')) {
                return false;
            }
        }

        return true;
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
