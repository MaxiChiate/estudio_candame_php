<?php

declare(strict_types=1);

namespace EstudioCandame\Controller;

use EstudioCandame\Seguimiento\BarraSeguimiento;
use EstudioCandame\Seguimiento\CookieSeguimiento;
use EstudioCandame\Support\SiteMeta;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class PageController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly string $basePath = '',
        // null cuando SEGUIMIENTO_ENABLED esta apagado: la home no sabe nada del portal
        // y, sobre todo, no se construye ninguna Conexion.
        private readonly ?BarraSeguimiento $barraSeguimiento = null,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $data = SiteMeta::defaults();

        // Si venimos de un redirect de ContactController con flash de "enviado",
        // se toma de la sesion; en el resto de los casos no hay contactForm previo
        // (formulario en blanco).
        $data['contactForm'] = $_SESSION['contactForm'] ?? ['name' => '', 'email' => '', 'phone' => '', 'message' => ''];
        if (isset($_SESSION['contactSent'])) {
            $data['sent'] = true;
            unset($_SESSION['contactSent'], $_SESSION['contactForm']);
        }

        // Barra de acceso al portal, si el visitante ya entro con un token valido.
        // paraToken() se traga cualquier fallo (base caida incluida) y devuelve null:
        // la home nunca se cae por esto.
        $tokenCookie = CookieSeguimiento::leer($request);
        $barra = $this->barraSeguimiento?->paraToken($tokenCookie);
        $data['barraSeguimiento'] = $barra;

        $response = $this->twig->render($response, 'index.html.twig', $data);

        // El visitante trae una cookie que ya no resuelve (token revocado, tramite
        // borrado): se la sacamos para no volver a consultar la base en cada visita.
        if ($tokenCookie !== null && $barra === null && $this->barraSeguimiento !== null) {
            $response = $response->withHeader(
                'Set-Cookie',
                CookieSeguimiento::valorBorrar(CookieSeguimiento::esHttps($request)),
            );
        }

        return $response;
    }

    public function redirectToAnchor(Request $request, Response $response, string $anchor): Response
    {
        return $response
            ->withHeader('Location', $this->basePath . '/#' . $anchor)
            ->withStatus(302);
    }

    public function redirectToHome(Request $request, Response $response): Response
    {
        return $response
            ->withHeader('Location', $this->basePath . '/')
            ->withStatus(302);
    }
}
