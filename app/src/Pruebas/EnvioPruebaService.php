<?php

declare(strict_types=1);

namespace EstudioCandame\Pruebas;

use EstudioCandame\Service\ConsultaConstitucionMailer;
use EstudioCandame\Service\FichaConstitucionXlsxBuilder;
use EstudioCandame\Support\Reloj;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Orquesta el flujo completo de una consulta con datos de prueba: carga el caso, valida
 * de verdad, arma el xlsx y manda los 2 mails -- exactamente los mismos pasos que
 * ConsultaConstitucionController::enviar(), con el mismo generador de xlsx y el mismo
 * servicio de envio, solo que $mailer ya viene armado en modo prueba por quien
 * construye este servicio. Unico consumidor tanto de bin/consulta-test.php como de
 * HumoController -- cero codigo duplicado del camino de prueba.
 *
 * No sabe ni le importa si el mail sale de verdad por la red: eso lo decide el closure
 * $transporte que ya trae el $mailer inyectado (mismo seam que usan los tests).
 */
final class EnvioPruebaService
{
    public function __construct(
        private readonly FichaConstitucionXlsxBuilder $xlsxBuilder,
        private readonly ConsultaConstitucionMailer $mailer,
        private readonly Reloj $reloj,
    ) {
    }

    public function ejecutar(string $caso): ResultadoEnvioPrueba
    {
        $fixture = CasoPruebaLoader::cargar($caso);
        $form = $fixture['form'];
        $capitalMinimo = $fixture['capitalMinimo'];
        $ahora = $this->reloj->ahora();

        $errores = $form->validate($ahora, $capitalMinimo);
        if ($errores !== []) {
            return ResultadoEnvioPrueba::validacionFallida($caso, $errores);
        }

        $xlsxBytes = $this->xlsxBuilder->build($form, $capitalMinimo, $ahora);
        $nombreArchivo = $this->xlsxBuilder->nombreArchivo($form, $ahora);

        try {
            $this->mailer->enviarConsultaAlEstudio($form, $xlsxBytes, $nombreArchivo, $caso);
            $this->mailer->enviarAcuseAlRemitente($form, $caso);
        } catch (PHPMailerException $exception) {
            return ResultadoEnvioPrueba::smtpFallido($caso, $exception->getMessage());
        }

        return ResultadoEnvioPrueba::completado($caso, $nombreArchivo, $xlsxBytes);
    }
}
