<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Smoke;

use EstudioCandame\Model\ConsultaConstitucionForm;
use EstudioCandame\Service\ConsultaConstitucionMailer;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El test que justifica el ticket: con destinatarioPruebaOverride seteado,
 * info@estudiocandame.com.ar (ni ninguna otra direccion real) puede aparecer como
 * destinatario, sin importar que otra combinacion de flags/caso se use. Mismo patron de
 * $transporte capturado que EnvioConsultaTest, sin red.
 */
final class DestinatarioPruebaTest extends TestCase
{
    /** @return array<string, array{0: ?string}> */
    public static function casosPrueba(): array
    {
        return [
            'sin caso especificado' => [null],
            'caso real' => ['sas-dos-socios'],
            'caso arbitrario' => ['algo-inventado'],
        ];
    }

    #[DataProvider('casosPrueba')]
    public function testConsultaAlEstudioNuncaLlegaAInfoEnModoPrueba(?string $casoPrueba): void
    {
        $capturado = $this->capturarConsultaAlEstudio('info@estudiocandame.com.ar', $casoPrueba);

        self::assertSame(['prueba@example.com'], array_column($capturado->getToAddresses(), 0));
        self::assertNotContains('info@estudiocandame.com.ar', array_column($capturado->getToAddresses(), 0));
    }

    #[DataProvider('casosPrueba')]
    public function testAcuseNuncaLlegaAInfoEnModoPrueba(?string $casoPrueba): void
    {
        $capturado = $this->capturarAcuse('info@estudiocandame.com.ar', $casoPrueba);

        self::assertSame(['prueba@example.com'], array_column($capturado->getToAddresses(), 0));
        self::assertNotContains('info@estudiocandame.com.ar', array_column($capturado->getToAddresses(), 0));
    }

    public function testAsuntoLlevaPrefijoDePrueba(): void
    {
        $capturado = $this->capturarConsultaAlEstudio('info@estudiocandame.com.ar', 'sas-dos-socios');

        self::assertStringStartsWith('[PRUEBA] ', $capturado->Subject);
    }

    public function testCuerpoAvisaQueEsUnEnvioDePruebaYAQueCasoCorresponde(): void
    {
        $capturado = $this->capturarConsultaAlEstudio('info@estudiocandame.com.ar', 'sas-dos-socios');

        self::assertStringStartsWith('Este es un envío de PRUEBA', $capturado->Body);
        self::assertStringContainsString('sas-dos-socios', $capturado->Body);
    }

    public function testReplyToDeLaConsultaAlEstudioNoSeAltera(): void
    {
        $capturado = $this->capturarConsultaAlEstudio('info@estudiocandame.com.ar', 'sas-dos-socios');

        self::assertSame(['juan@example.com'], array_column($capturado->getReplyToAddresses(), 0));
    }

    public function testSinModoPruebaElDestinatarioEsElNormal(): void
    {
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

        $mailer->enviarConsultaAlEstudio($this->formValido(), 'contenido', 'ficha.xlsx');

        self::assertSame(['info@estudiocandame.com.ar'], array_column($capturado->getToAddresses(), 0));
        self::assertStringStartsWith('Consulta constitución', $capturado->Subject);
    }

    private function capturarConsultaAlEstudio(string $estudioToAddress, ?string $casoPrueba): PHPMailer
    {
        $capturado = null;
        $mailer = new ConsultaConstitucionMailer(
            'smtp.test',
            587,
            'web@estudiocandame.com.ar',
            'password',
            true,
            true,
            $estudioToAddress,
            function (PHPMailer $mailer) use (&$capturado): void {
                $capturado = $mailer;
            },
            'prueba@example.com',
        );

        $mailer->enviarConsultaAlEstudio($this->formValido(), 'contenido-del-xlsx', 'ficha.xlsx', $casoPrueba);

        self::assertInstanceOf(PHPMailer::class, $capturado);

        return $capturado;
    }

    private function capturarAcuse(string $estudioToAddress, ?string $casoPrueba): PHPMailer
    {
        $capturado = null;
        $mailer = new ConsultaConstitucionMailer(
            'smtp.test',
            587,
            'web@estudiocandame.com.ar',
            'password',
            true,
            true,
            $estudioToAddress,
            function (PHPMailer $mailer) use (&$capturado): void {
                $capturado = $mailer;
            },
            'prueba@example.com',
        );

        $mailer->enviarAcuseAlRemitente($this->formValido(), $casoPrueba);

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
