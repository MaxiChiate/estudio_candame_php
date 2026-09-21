<?php

declare(strict_types=1);

use EstudioCandame\Controller\AdminTramitesController;
use EstudioCandame\Controller\ContactController;
use EstudioCandame\Controller\ConsultaConstitucionController;
use EstudioCandame\Controller\HumoController;
use EstudioCandame\Controller\PageController;
use EstudioCandame\Controller\SeguimientoController;
use EstudioCandame\Pruebas\EnvioPruebaService;
use EstudioCandame\Seguimiento\AccesoRepository;
use EstudioCandame\Seguimiento\BarraSeguimiento;
use EstudioCandame\Seguimiento\CatalogoFlujos;
use EstudioCandame\Seguimiento\Conexion;
use EstudioCandame\Seguimiento\TokenGenerator;
use EstudioCandame\Seguimiento\TramiteRepository;
use EstudioCandame\Service\CapitalMinimoResolver;
use EstudioCandame\Service\ConsultaConstitucionMailer;
use EstudioCandame\Service\FichaConstitucionXlsxBuilder;
use EstudioCandame\Service\SmvmService;
use EstudioCandame\Support\Admin\AutenticacionBasica;
use EstudioCandame\Support\AntiAbuso\RateLimiter;
use EstudioCandame\Support\RelojSistema;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Slim\Views\Twig;

return function (App $app, Twig $twig): void {
    $basePath = rtrim((string) ($_ENV['APP_BASE_PATH'] ?? ''), '/');

    // --- Portal de seguimiento -----------------------------------------------------
    // Se arma antes que la home porque PageController necesita la barra de acceso.
    // Con el flag apagado todo esto queda en null: no se registran rutas y, sobre todo,
    // no se construye ninguna Conexion (que igual es perezosa y no abriria socket).
    $seguimientoEnabled = filter_var($_ENV['SEGUIMIENTO_ENABLED'] ?? false, FILTER_VALIDATE_BOOL);
    $barraSeguimiento = null;
    $tramiteRepository = null;
    $accesoRepository = null;
    $catalogoFlujos = null;
    $etapasConfig = [];

    if ($seguimientoEnabled) {
        $relojSeguimiento = new RelojSistema();
        $conexion = new Conexion(
            (string) ($_ENV['DB_HOST'] ?? 'localhost'),
            (string) ($_ENV['DB_NAME'] ?? ''),
            (string) ($_ENV['DB_USER'] ?? ''),
            (string) ($_ENV['DB_PASS'] ?? ''),
            (string) ($_ENV['DB_CHARSET'] ?? 'utf8mb4'),
        );
        $etapasConfig = require APP_PATH . '/config/etapas.php';
        // Valida las dos configs al construirse: una etapa inexistente o repetida en un
        // flujo revienta aca, al arrancar, y no cuando un cliente abre su enlace.
        $catalogoFlujos = new CatalogoFlujos(require APP_PATH . '/config/flujos.php', $etapasConfig);
        $tramiteRepository = new TramiteRepository($conexion, $relojSeguimiento);
        $accesoRepository = new AccesoRepository($conexion, $relojSeguimiento, new TokenGenerator());
        $barraSeguimiento = new BarraSeguimiento($accesoRepository, $tramiteRepository, $catalogoFlujos);
    }

    $pageController = new PageController($twig, $basePath, $barraSeguimiento);
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

    // Rutas del portal. Solo existen con el flag prendido: apagado, son 404 por
    // ausencia de ruta, no por un chequeo dentro del controller.
    if (
        $seguimientoEnabled
        && $tramiteRepository !== null
        && $accesoRepository !== null
        && $catalogoFlujos !== null
    ) {
        $seguimientoController = new SeguimientoController(
            $twig,
            $accesoRepository,
            $tramiteRepository,
            $catalogoFlujos,
            $etapasConfig,
            $basePath,
        );

        // La informativa va primero por claridad; no compiten, /seguimiento no matchea
        // el patron con token.
        $app->get('/seguimiento', [$seguimientoController, 'info']);
        $app->get('/seguimiento/{token}', [$seguimientoController, 'verEstado']);

        $adminController = new AdminTramitesController(
            $twig,
            $tramiteRepository,
            $accesoRepository,
            $catalogoFlujos,
            $basePath,
            (string) ($_ENV['APP_URL'] ?? ''),
        );

        // HTTP Basic sobre todo el grupo. Si ADMIN_USER o ADMIN_PASS_HASH estan vacios,
        // AutenticacionBasica niega todo -- un .env incompleto no abre el panel.
        $autenticacion = new AutenticacionBasica(
            (string) ($_ENV['ADMIN_USER'] ?? ''),
            (string) ($_ENV['ADMIN_PASS_HASH'] ?? ''),
        );

        $app->group('/admin', function (RouteCollectorProxy $grupo) use ($adminController): void {
            $grupo->get('/tramites', [$adminController, 'listado']);
            // "nuevo" antes del patron con id, y el id acotado a digitos para que no se
            // pisen aunque cambie el orden.
            $grupo->get('/tramites/nuevo', [$adminController, 'formularioNuevo']);
            // Alta en dos pasos: el flujo no se puede cambiar despues de creado, asi que
            // la confirmacion muestra el recorrido completo antes de escribir nada.
            // Crear es POST /tramites a secas, y revalida por su cuenta.
            $grupo->post('/tramites/nuevo/previsualizar', [$adminController, 'previsualizar']);
            $grupo->post('/tramites/nuevo/editar', [$adminController, 'volverAEditar']);
            $grupo->post('/tramites', [$adminController, 'crear']);
            $grupo->get('/tramites/{id:[0-9]+}', [$adminController, 'detalle']);
            $grupo->post('/tramites/{id:[0-9]+}/avanzar', [$adminController, 'avanzar']);
            $grupo->post('/tramites/{id:[0-9]+}/observacion', [$adminController, 'observacion']);
            $grupo->post('/tramites/{id:[0-9]+}/accesos', [$adminController, 'emitirAcceso']);
            $grupo->post('/accesos/{id:[0-9]+}/revocar', [$adminController, 'revocarAcceso']);
        })->add($autenticacion);
    }

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
