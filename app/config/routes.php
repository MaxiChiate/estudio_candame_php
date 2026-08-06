<?php

declare(strict_types=1);

use EstudioCandame\Controller\PageController;
use Slim\App;
use Slim\Views\Twig;

return function (App $app, Twig $twig): void {
    $basePath = rtrim((string) ($_ENV['APP_BASE_PATH'] ?? ''), '/');

    $pageController = new PageController($twig, $basePath);
    $app->get('/', [$pageController, 'index']);

    // Rutas heredadas de bookmarks/SEO viejos: redirigen al anchor correspondiente
    // de la pagina unica en vez de servir una pagina aparte.
    $legacyAnchors = [
        '/servicios' => 'servicios',
        '/sindicatura-concursal' => 'sindicatura',
        // "sidicatura-concursal.html" (sin la primera "n") es la URL que realmente
        // quedo indexada del sitio viejo (webcandame.old/sidicatura-concursal.html):
        // se mantiene el redirect aunque el nombre correcto ya exista arriba.
        '/sidicatura-concursal' => 'sindicatura',
        '/concursos-preventivos' => 'concursos',
        '/sociedades-comerciales' => 'sociedades',
        '/atencion-contadores-publicos' => 'contadores',
        '/trayectoria' => 'trayectoria',
    ];
    foreach ($legacyAnchors as $path => $anchor) {
        $app->get($path, fn ($request, $response) => $pageController->redirectToAnchor($request, $response, $anchor));
    }

    $app->get('/links', [$pageController, 'redirectToHome']);

    // "contacto.php" es la URL indexada del sitio viejo (webcandame.old/contacto.php).
    $app->get('/contacto.php', fn ($request, $response) => $pageController->redirectToAnchor($request, $response, 'contacto'));
};
