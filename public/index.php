<?php

declare(strict_types=1);

// Ruta a app/, en una constante para que mover app/ (por ejemplo adentro de
// public_html por restricciones de FTP) sea un cambio de una linea.
define('APP_PATH', dirname(__DIR__) . '/app');

require APP_PATH . '/bootstrap.php';
