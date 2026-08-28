#!/usr/bin/env php
<?php

declare(strict_types=1);

define('APP_PATH', dirname(__DIR__) . '/app');
require APP_PATH . '/vendor/autoload.php';

use EstudioCandame\Pruebas\CasoPruebaLoader;
use EstudioCandame\Pruebas\EnvioPruebaService;
use EstudioCandame\Pruebas\ResultadoEnvioPrueba;
use EstudioCandame\Service\ConsultaConstitucionMailer;
use EstudioCandame\Service\FichaConstitucionXlsxBuilder;
use EstudioCandame\Support\RelojSistema;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

date_default_timezone_set('America/Argentina/Buenos_Aires');
mb_internal_encoding('UTF-8');
if (file_exists(APP_PATH . '/.env')) {
    Dotenv\Dotenv::createImmutable(APP_PATH)->load();
}

/**
 * Corre el flujo completo de la consulta de constitucion (validar + armar el .xlsx +
 * mandar los 2 mails) con datos de prueba de app/config/casos_prueba/, redirigiendo
 * ambos mails al destinatario de prueba en vez de a info@estudiocandame.com.ar. Mismo
 * generador de xlsx y mismo servicio de envio que usa el controller -- ver
 * EstudioCandame\Pruebas\EnvioPruebaService.
 *
 * Uso:
 *   php bin/consulta-test.php --to=alguien@ejemplo.com
 *   php bin/consulta-test.php sas-dos-socios --to=alguien@ejemplo.com
 *   php bin/consulta-test.php --all --to=alguien@ejemplo.com
 *   php bin/consulta-test.php sas-dos-socios --dry-run
 *   php bin/consulta-test.php sas-dos-socios --verbose
 *
 * Codigos de salida: 0 OK, 1 validacion fallida, 2 fallo de SMTP, 3 uso incorrecto.
 */

$uso = "Uso: php bin/consulta-test.php [caso] [--to=direccion] [--all] [--dry-run] [--verbose]\n";

$casoArg = null;
$to = null;
$all = false;
$dryRun = false;
$verbose = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--all') {
        $all = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--verbose') {
        $verbose = true;
    } elseif (str_starts_with($arg, '--to=')) {
        $to = substr($arg, strlen('--to='));
    } elseif (!str_starts_with($arg, '--') && $casoArg === null) {
        $casoArg = $arg;
    } else {
        fwrite(STDERR, "Flag o argumento invalido: $arg\n" . $uso);
        exit(3);
    }
}

if ($all && $casoArg !== null) {
    fwrite(STDERR, "No se puede combinar --all con un caso especifico ($casoArg).\n" . $uso);
    exit(3);
}

$destinatario = $to ?? (string) ($_ENV['SMOKE_TEST_TO'] ?? '');
if ($destinatario === '') {
    fwrite(STDERR, "Falta un destinatario de prueba. Pasá --to=direccion@ejemplo.com o agregá SMOKE_TEST_TO=direccion@ejemplo.com a app/.env\n");
    exit(3);
}

$casos = $all ? CasoPruebaLoader::listarCasos() : [$casoArg ?? 'sas-dos-socios'];

$mailHost = (string) ($_ENV['MAIL_HOST'] ?? 'smtp.gmail.com');
$mailPort = (int) ($_ENV['MAIL_PORT'] ?? 587);
$mailUsername = (string) ($_ENV['MAIL_USERNAME'] ?? '');
$estudioToAddress = (string) ($_ENV['CONSULTA_CONSTITUCION_TO_ADDRESS'] ?? 'info@estudiocandame.com.ar');
$remitente = $mailUsername !== '' ? $mailUsername : $estudioToAddress;

$peorCodigo = 0;

foreach ($casos as $caso) {
    /** @var PHPMailer[] $capturados */
    $capturados = [];

    if ($dryRun) {
        $transporte = static function (PHPMailer $mailer) use (&$capturados): void {
            $capturados[] = $mailer;
        };
    } else {
        $transporte = static function (PHPMailer $mailer) use (&$capturados, $verbose): void {
            $capturados[] = $mailer;
            if ($verbose) {
                $mailer->SMTPDebug = SMTP::DEBUG_SERVER;
                $mailer->Debugoutput = static function (string $str): void {
                    fwrite(STDERR, rtrim($str) . "\n");
                };
            }
            $mailer->send();
        };
    }

    $mailer = new ConsultaConstitucionMailer(
        $mailHost,
        $mailPort,
        $mailUsername,
        (string) ($_ENV['MAIL_PASSWORD'] ?? ''),
        filter_var($_ENV['MAIL_SMTP_AUTH'] ?? true, FILTER_VALIDATE_BOOL),
        filter_var($_ENV['MAIL_SMTP_STARTTLS'] ?? true, FILTER_VALIDATE_BOOL),
        $estudioToAddress,
        $transporte,
        $destinatario,
    );

    $servicio = new EnvioPruebaService(new FichaConstitucionXlsxBuilder(), $mailer, new RelojSistema());
    $resultado = $servicio->ejecutar($caso);

    foreach ($resultado->lineasResumen($mailHost, $mailPort, $remitente, $destinatario) as $linea) {
        fwrite(STDOUT, $linea . "\n");
    }

    if ($dryRun && $resultado->ok()) {
        $dirTmp = APP_PATH . '/var/tmp';
        if (!is_dir($dirTmp)) {
            mkdir($dirTmp, 0775, true);
        }
        $rutaXlsx = $dirTmp . '/' . $resultado->nombreArchivo;
        file_put_contents($rutaXlsx, $resultado->xlsxBytes);
        fwrite(STDOUT, "Ficha escrita en: $rutaXlsx (no se envio ningun mail)\n");

        foreach ($capturados as $indice => $mailerCapturado) {
            $replyTo = array_column($mailerCapturado->getReplyToAddresses(), 0);
            fwrite(STDOUT, '--- Mail ' . ($indice + 1) . " ---\n");
            fwrite(STDOUT, 'Destinatario: ' . implode(', ', array_column($mailerCapturado->getToAddresses(), 0)) . "\n");
            if ($replyTo !== []) {
                fwrite(STDOUT, 'Reply-To: ' . implode(', ', $replyTo) . "\n");
            }
            fwrite(STDOUT, "Asunto: {$mailerCapturado->Subject}\n");
            fwrite(STDOUT, "Cuerpo:\n{$mailerCapturado->Body}\n");
        }
    }

    fwrite(STDOUT, "\n");

    $codigo = match ($resultado->estado) {
        ResultadoEnvioPrueba::COMPLETADO => 0,
        ResultadoEnvioPrueba::VALIDACION_FALLIDA => 1,
        ResultadoEnvioPrueba::SMTP_FALLIDO => 2,
    };
    $peorCodigo = max($peorCodigo, $codigo);
}

exit($peorCodigo);
