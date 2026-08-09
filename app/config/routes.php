<?php

declare(strict_types=1);

use EstudioCandame\Controller\ContactController;
use EstudioCandame\Controller\PageController;
use EstudioCandame\Controller\SasConstitucionController;
use EstudioCandame\Service\SasDocumentService;
use EstudioCandame\Service\SmvmService;
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

    // Ruta descartable para probar el pipeline de deploy (CI + deploy.sh) end to
    // end. Sacar cuando ya no haga falta.
    $app->get('/deploy-test', fn ($request, $response) => $twig->render($response, 'deploy-test.html.twig'));

    // "contacto.php" es la URL indexada del sitio viejo (webcandame.old/contacto.php).
    $app->get('/contacto.php', fn ($request, $response) => $pageController->redirectToAnchor($request, $response, 'contacto'));

    $contactController = new ContactController(
        $twig,
        $basePath,
        (string) ($_ENV['MAIL_HOST'] ?? 'smtp.gmail.com'),
        (int) ($_ENV['MAIL_PORT'] ?? 587),
        (string) ($_ENV['MAIL_USERNAME'] ?? ''),
        (string) ($_ENV['MAIL_PASSWORD'] ?? ''),
        filter_var($_ENV['MAIL_SMTP_AUTH'] ?? true, FILTER_VALIDATE_BOOL),
        filter_var($_ENV['MAIL_SMTP_STARTTLS'] ?? true, FILTER_VALIDATE_BOOL),
        (string) ($_ENV['CONTACT_TO_ADDRESS'] ?? 'estudiocandame@gmail.com'),
    );
    $app->get('/contacto', [$contactController, 'redirectToAnchor']);
    $app->post('/contacto', [$contactController, 'submit']);

    $configuradorEnabled = filter_var($_ENV['CONFIGURADOR_ENABLED'] ?? false, FILTER_VALIDATE_BOOL);
    if ($configuradorEnabled) {
        $smvmService = new SmvmService(
            (string) ($_ENV['SAS_SMVM_API_URL'] ?? ''),
            (float) ($_ENV['SMVM_FALLBACK_VALOR'] ?? 0),
            (string) ($_ENV['SMVM_FALLBACK_FECHA'] ?? ''),
            (int) ($_ENV['SAS_CAPITAL_MULTIPLO_SMVM'] ?? 2),
            APP_PATH . '/var/cache/smvm.json',
        );
        $sasController = new SasConstitucionController($twig, $smvmService, new SasDocumentService($smvmService));
        $app->get('/tramites/sas/constitucion', [$sasController, 'form']);
        $app->post('/tramites/sas/constitucion', [$sasController, 'generar']);
    }
};
