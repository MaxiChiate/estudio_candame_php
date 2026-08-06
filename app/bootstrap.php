<?php

declare(strict_types=1);

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

(require APP_PATH . '/config/routes.php')($app, $twig);

$app->run();
