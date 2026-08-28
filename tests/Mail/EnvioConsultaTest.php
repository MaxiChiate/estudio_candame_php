<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Mail;

use EstudioCandame\Model\ConsultaConstitucionForm;
use EstudioCandame\Service\ConsultaConstitucionMailer;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;

/**
 * Con un doble del mailer (el $transporte inyectable), sin red: verifica destinatario,
 * asunto, Reply-To, que el adjunto esta y se llama como corresponde, y que el acuse no
 * contiene nada provisto por el usuario mas alla de su nombre.
 */
final class EnvioConsultaTest extends TestCase
{
    public function testMailAlEstudioTieneDestinatarioAsuntoReplyToYAdjunto(): void
    {
        $capturado = $this->capturarMailer(
            fn (ConsultaConstitucionMailer $mailer, ConsultaConstitucionForm $form) => $mailer->enviarConsultaAlEstudio($form, 'contenido-del-xlsx', 'ficha-sas-acme-2026-08-10.xlsx'),
        );

        self::assertSame(['info@estudiocandame.com.ar'], array_column($capturado->getToAddresses(), 0));
        self::assertSame(['juan@example.com'], array_column($capturado->getReplyToAddresses(), 0));
        self::assertSame('Consulta constitución SAS — Acme', $capturado->Subject);

        $adjuntos = $capturado->getAttachments();
        self::assertCount(1, $adjuntos);
        self::assertSame('ficha-sas-acme-2026-08-10.xlsx', $adjuntos[0][2]);
    }

    public function testAcuseAlRemitenteNoTieneEcoDeLoEscritoMasAllaDelNombre(): void
    {
        $capturado = $this->capturarMailer(
            fn (ConsultaConstitucionMailer $mailer, ConsultaConstitucionForm $form) => $mailer->enviarAcuseAlRemitente($form),
        );

        self::assertSame(['juan@example.com'], array_column($capturado->getToAddresses(), 0));
        self::assertSame([], $capturado->getAttachments());
        self::assertStringContainsString('Juan Pérez', $capturado->Body);

        // Texto fijo: no debe repetir ningun dato provisto por el usuario mas alla del
        // nombre (email, denominaciones, objeto social, etc.).
        self::assertStringNotContainsString('juan@example.com', $capturado->Body);
        self::assertStringNotContainsString('Acme', $capturado->Body);
        self::assertStringNotContainsString('Desarrollo de software', $capturado->Body);
    }

    private function capturarMailer(callable $accion): PHPMailer
    {
        $form = $this->formValido();
        $capturado = null;

        $mailer = new ConsultaConstitucionMailer(
            'smtp.test',
            587,
            'web@estudiocandame.com.ar',
            'password',
            true,
            true,
            'info@estudiocandame.com.ar',
            function (PHPMailer $mailer) use (&$capturado): void {
                $capturado = $mailer;
            },
        );

        $accion($mailer, $form);

        self::assertInstanceOf(PHPMailer::class, $capturado);

        return $capturado;
    }

    private function formValido(): ConsultaConstitucionForm
    {
        return ConsultaConstitucionForm::fromArray([
            'tipoSocietario' => 'SAS',
            'contacto' => ['nombre' => 'Juan Pérez', 'email' => 'juan@example.com', 'telefono' => '111', 'rol' => 'CONTADOR'],
            'sociedad' => [
                'nombreOpcion1' => 'Acme',
                'nombreOpcion2' => 'Beta',
                'nombreOpcion3' => 'Gamma',
                'objetoSocial' => 'Desarrollo de software',
                'capitalSocial' => 800000,
                'urgente' => false,
            ],
            'socios' => [['apellidoYNombre' => 'Juan Pérez']],
            'administradores' => [['apellidoYNombre' => 'Juan Pérez']],
        ]);
    }
}
