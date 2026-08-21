<?php

declare(strict_types=1);

namespace EstudioCandame\Service;

use Closure;
use EstudioCandame\Model\ConsultaConstitucionForm;
use EstudioCandame\Support\Ars;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Dos mails por consulta: el resumen completo con el xlsx adjunto al estudio, y un
 * acuse de texto fijo al remitente (sin eco de nada que haya escrito, mas alla de su
 * nombre). Mismo wiring SMTP que ContactController.
 *
 * $transporte es el seam de testing: por defecto llama a PHPMailer::send(), pero un
 * test (o el entorno de prueba de bin/consulta-test.php y la ruta de humo) puede
 * inyectar un closure que capture el mailer configurado en vez de mandarlo por red.
 *
 * $destinatarioPruebaOverride es el modo de prueba: si esta seteado, ambos mails van
 * ahi en vez de a $estudioToAddress / la direccion del formulario. Quien construye el
 * mailer decide el destinatario efectivo -- no hay ningun `if` de "estamos en modo
 * prueba" desperdigado en los metodos de envio, solo helpers que resuelven el valor ya
 * decidido en el constructor.
 */
final class ConsultaConstitucionMailer
{
    public function __construct(
        private readonly string $mailHost,
        private readonly int $mailPort,
        private readonly string $mailUsername,
        private readonly string $mailPassword,
        private readonly bool $mailSmtpAuth,
        private readonly bool $mailSmtpStarttls,
        private readonly string $estudioToAddress,
        private readonly ?Closure $transporte = null,
        private readonly ?string $destinatarioPruebaOverride = null,
    ) {
    }

    /** @throws PHPMailerException */
    public function enviarConsultaAlEstudio(ConsultaConstitucionForm $form, string $xlsxBytes, string $xlsxNombreArchivo, ?string $casoPrueba = null): void
    {
        $mailer = $this->crearMailer();
        $mailer->addAddress($this->destinatarioEfectivo($this->estudioToAddress));
        if ($form->contacto->email !== '') {
            $mailer->addReplyTo($form->contacto->email);
        }

        $mailer->Subject = $this->prefijoAsuntoPrueba() . sprintf('Consulta constitución %s — %s', $form->tipoSocietario?->value ?? '', $form->sociedad->nombreOpcion1);
        $mailer->Body = $this->prefijoCuerpoPrueba($casoPrueba) . $this->cuerpoParaEstudio($form);
        $mailer->addStringAttachment($xlsxBytes, $xlsxNombreArchivo);

        $this->enviar($mailer);
    }

    /** @throws PHPMailerException */
    public function enviarAcuseAlRemitente(ConsultaConstitucionForm $form, ?string $casoPrueba = null): void
    {
        $mailer = $this->crearMailer();
        $mailer->addAddress($this->destinatarioEfectivo($form->contacto->email));

        $mailer->Subject = $this->prefijoAsuntoPrueba() . 'Recibimos tu consulta de constitución - Estudio Candame';
        $mailer->Body = $this->prefijoCuerpoPrueba($casoPrueba) . sprintf(
            "Hola %s,\n\nRecibimos tu consulta de constitución de sociedad. En breve nos vamos a poner en " .
            "contacto para continuar con los siguientes pasos.\n\nEstudio Candame",
            $form->contacto->nombre,
        );

        $this->enviar($mailer);
    }

    private function modoPrueba(): bool
    {
        return $this->destinatarioPruebaOverride !== null;
    }

    private function destinatarioEfectivo(string $destinatarioNormal): string
    {
        return $this->destinatarioPruebaOverride ?? $destinatarioNormal;
    }

    private function prefijoAsuntoPrueba(): string
    {
        return $this->modoPrueba() ? '[PRUEBA] ' : '';
    }

    private function prefijoCuerpoPrueba(?string $casoPrueba): string
    {
        if (!$this->modoPrueba()) {
            return '';
        }

        return sprintf("Este es un envío de PRUEBA correspondiente al caso \"%s\". No es una consulta real.\n\n", $casoPrueba ?? '(sin especificar)');
    }

    private function crearMailer(): PHPMailer
    {
        $mailer = new PHPMailer(true);
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->isSMTP();
        $mailer->Host = $this->mailHost;
        $mailer->Port = $this->mailPort;
        $mailer->SMTPAuth = $this->mailSmtpAuth;
        if ($this->mailSmtpAuth) {
            $mailer->Username = $this->mailUsername;
            $mailer->Password = $this->mailPassword;
        }
        $mailer->SMTPSecure = $this->mailSmtpStarttls ? PHPMailer::ENCRYPTION_STARTTLS : '';
        $mailer->setFrom($this->mailUsername !== '' ? $this->mailUsername : $this->estudioToAddress, 'Estudio Candame - Sitio web');

        return $mailer;
    }

    private function cuerpoParaEstudio(ConsultaConstitucionForm $form): string
    {
        return sprintf(
            "Nueva consulta de constitución recibida desde el sitio.\n\n" .
            "Tipo societario: %s\n" .
            "Denominaciones propuestas: %s / %s / %s\n" .
            "Capital social: %s\n" .
            "Cantidad de socios: %d\n" .
            "Cantidad de administradores: %d\n" .
            "Trámite urgente: %s\n\n" .
            "Datos de contacto:\n" .
            "Nombre: %s\n" .
            "Rol: %s\n" .
            "Email: %s\n" .
            "Teléfono: %s\n\n" .
            'Se adjunta la ficha completa en formato Excel.',
            $form->tipoSocietario?->value ?? '',
            $form->sociedad->nombreOpcion1,
            $form->sociedad->nombreOpcion2,
            $form->sociedad->nombreOpcion3,
            Ars::formatEntero($form->sociedad->capitalSocial),
            count($form->socios),
            count($form->administradores),
            $form->sociedad->urgente ? 'Sí' : 'No',
            $form->contacto->nombre,
            $form->contacto->rol?->etiqueta() ?? '',
            $form->contacto->email,
            $form->contacto->telefono,
        );
    }

    /** @throws PHPMailerException */
    private function enviar(PHPMailer $mailer): void
    {
        if ($this->transporte !== null) {
            ($this->transporte)($mailer);

            return;
        }

        $mailer->send();
    }
}
