<?php

declare(strict_types=1);

use EstudioCandame\Controller\ContactController;
use EstudioCandame\Controller\ConsultaConstitucionController;
use EstudioCandame\Controller\HumoController;
use EstudioCandame\Controller\PageController;
use EstudioCandame\Pruebas\EnvioPruebaService;
use EstudioCandame\Service\CapitalMinimoResolver;
use EstudioCandame\Service\ConsultaConstitucionMailer;
use EstudioCandame\Service\FichaConstitucionXlsxBuilder;
use EstudioCandame\Service\SmvmService;
use EstudioCandame\Support\AntiAbuso\RateLimiter;
use EstudioCandame\Support\RelojSistema;
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
        $capitalesMinimos = require APP_PATH . '/config/capitales_minimos.php';
        $capitalMinimoResolver = new CapitalMinimoResolver($smvmService, $capitalesMinimos);

        $mailer = new ConsultaConstitucionMailer(
            (string) ($_ENV['MAIL_HOST'] ?? 'smtp.gmail.com'),
            (int) ($_ENV['MAIL_PORT'] ?? 587),
            (string) ($_ENV['MAIL_USERNAME'] ?? ''),
            (string) ($_ENV['MAIL_PASSWORD'] ?? ''),
            filter_var($_ENV['MAIL_SMTP_AUTH'] ?? true, FILTER_VALIDATE_BOOL),
            filter_var($_ENV['MAIL_SMTP_STARTTLS'] ?? true, FILTER_VALIDATE_BOOL),
            (string) ($_ENV['CONSULTA_CONSTITUCION_TO_ADDRESS'] ?? 'info@estudiocandame.com.ar'),
        );

        $rateLimiter = new RateLimiter(
            APP_PATH . '/var/cache/rate-limit-consulta-constitucion.json',
            (int) ($_ENV['CONSULTA_CONSTITUCION_RATE_LIMIT_VENTANA_SEGUNDOS'] ?? 3600),
            (int) ($_ENV['CONSULTA_CONSTITUCION_RATE_LIMIT_MAX_ENVIOS'] ?? 5),
        );

        $reloj = new RelojSistema();

        $consultaController = new ConsultaConstitucionController(
            $twig,
            $capitalMinimoResolver,
            new FichaConstitucionXlsxBuilder(),
            $mailer,
            $rateLimiter,
            $reloj,
        );
        $app->get('/tramites/constitucion', [$consultaController, 'form']);
        $app->post('/tramites/constitucion', [$consultaController, 'enviar']);

        // Ruta de humo: solo existe si estan las dos variables completas. Ademas de
        // "CONFIGURADOR_ENABLED apagado", esto ya resuelve dos de los cuatro motivos de
        // 404 del spec por simple ausencia de la ruta -- el token incorrecto se chequea
        // en HumoController porque depende del request.
        $smokeTestToken = (string) ($_ENV['SMOKE_TEST_TOKEN'] ?? '');
        $smokeTestTo = (string) ($_ENV['SMOKE_TEST_TO'] ?? '');
        if ($smokeTestToken !== '' && $smokeTestTo !== '') {
            $mailHost = (string) ($_ENV['MAIL_HOST'] ?? 'smtp.gmail.com');
            $mailPort = (int) ($_ENV['MAIL_PORT'] ?? 587);
            $mailUsername = (string) ($_ENV['MAIL_USERNAME'] ?? '');
            $estudioToAddress = (string) ($_ENV['CONSULTA_CONSTITUCION_TO_ADDRESS'] ?? 'info@estudiocandame.com.ar');

            $mailerPrueba = new ConsultaConstitucionMailer(
                $mailHost,
                $mailPort,
                $mailUsername,
                (string) ($_ENV['MAIL_PASSWORD'] ?? ''),
                filter_var($_ENV['MAIL_SMTP_AUTH'] ?? true, FILTER_VALIDATE_BOOL),
                filter_var($_ENV['MAIL_SMTP_STARTTLS'] ?? true, FILTER_VALIDATE_BOOL),
                $estudioToAddress,
                null,
                $smokeTestTo,
            );
            $envioPrueba = new EnvioPruebaService(new FichaConstitucionXlsxBuilder(), $mailerPrueba, $reloj);
            $humoRateLimiter = new RateLimiter(APP_PATH . '/var/cache/rate-limit-smoke-test.json', 60, 1);

            $humoController = new HumoController(
                $envioPrueba,
                $humoRateLimiter,
                $smokeTestToken,
                $mailHost,
                $mailPort,
                $mailUsername !== '' ? $mailUsername : $estudioToAddress,
                $smokeTestTo,
            );
            $app->get('/tramites/constitucion/_humo', [$humoController, 'humo']);
        }
    }
};
