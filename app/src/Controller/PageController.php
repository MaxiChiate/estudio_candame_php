<?php

declare(strict_types=1);

namespace EstudioCandame\Controller;

use EstudioCandame\Support\SiteMeta;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class PageController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly string $basePath = '',
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

        return $this->twig->render($response, 'index.html.twig', $data);
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
