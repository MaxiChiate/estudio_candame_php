<?php

declare(strict_types=1);

use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpException;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require APP_PATH . '/vendor/autoload.php';

// El servidor de hosting corre en UTC (date.timezone=UTC en el php.ini de cPanel);
// forzamos Buenos Aires aca para que las fechas de los documentos no se corran.
date_default_timezone_set('America/Argentina/Buenos_Aires');

mb_internal_encoding('UTF-8');

if (file_exists(APP_PATH . '/.env')) {
    Dotenv\Dotenv::createImmutable(APP_PATH)->load();
}

session_start();

$app = AppFactory::create();

$basePath = rtrim((string) ($_ENV['APP_BASE_PATH'] ?? ''), '/');
if ($basePath !== '') {
    $app->setBasePath($basePath);
}

$twig = Twig::create(APP_PATH . '/templates', [
    'cache' => false,
    'charset' => 'utf-8',
]);
$twig->getEnvironment()->addGlobal('basePath', $basePath);
$twig->getEnvironment()->addGlobal('configuradorEnabled', filter_var($_ENV['CONFIGURADOR_ENABLED'] ?? false, FILTER_VALIDATE_BOOL));
$app->add(TwigMiddleware::create($app, $twig));

$app->addRoutingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(
    (bool) ($_ENV['APP_DEBUG'] ?? false),
    true,
    true,
);

// Reemplaza la pagina de error HTML por defecto de Slim (blanca, sin estilos del
// sitio) por error.html.twig, para que un 404 o un 500 se vean como el resto del
// sitio en vez de una pantalla generica de framework.
$errorMiddleware->setDefaultErrorHandler(
    function (Request $request, Throwable $exception, bool $displayErrorDetails) use ($app, $twig, $basePath) {
        $statusCode = $exception instanceof HttpException ? $exception->getCode() : 500;
        if ($statusCode < 400 || $statusCode > 599) {
            $statusCode = 500;
        }

        if ($statusCode === 404) {
            $errorTitle = 'Pagina no encontrada';
            $errorMessage = 'La pagina que busca no existe o fue movida.';
        } else {
            $errorTitle = 'Ocurrio un error';
            $errorMessage = 'Ocurrio un error inesperado. Por favor intente nuevamente en unos minutos.';
        }

        if ($displayErrorDetails) {
            $errorMessage .= ' (' . $exception->getMessage() . ')';
        }

        if ($statusCode >= 500) {
            error_log('Unhandled exception: ' . $exception->getMessage());
        }

        $response = $app->getResponseFactory()->createResponse($statusCode);

        return $twig->render($response, 'error.html.twig', [
            'pageTitle' => $errorTitle . ' - Estudio Candame',
            'metaDescription' => $errorMessage,
            'metaKeywords' => '',
            'statusCode' => $statusCode,
            'errorTitle' => $errorTitle,
            'errorMessage' => $errorMessage,
        ]);
    }
);

(require APP_PATH . '/config/routes.php')($app, $twig);

$app->run();
