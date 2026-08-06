<?php

declare(strict_types=1);

namespace EstudioCandame\Controller;

use EstudioCandame\Support\SiteMeta;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ContactController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly string $basePath,
        private readonly string $mailHost,
        private readonly int $mailPort,
        private readonly string $mailUsername,
        private readonly string $mailPassword,
        private readonly bool $mailSmtpAuth,
        private readonly bool $mailSmtpStarttls,
        private readonly string $toAddress,
    ) {
    }

    public function redirectToAnchor(Request $request, Response $response): Response
    {
        return $response
            ->withHeader('Location', $this->basePath . '/#contacto')
            ->withStatus(302);
    }

    public function submit(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $form = [
            'name' => trim((string) ($body['name'] ?? '')),
            'email' => trim((string) ($body['email'] ?? '')),
            'phone' => trim((string) ($body['phone'] ?? '')),
            'message' => trim((string) ($body['message'] ?? '')),
        ];

        $errors = $this->validate($form);

        if ($errors !== []) {
            $data = SiteMeta::defaults();
            $data['contactForm'] = $form;
            $data['errors'] = $errors;
            return $this->twig->render($response, 'index.html.twig', $data);
        }

        try {
            $this->sendMail($form);
        } catch (PHPMailerException $exception) {
            error_log('Failed to send contact email: ' . $exception->getMessage());
            $data = SiteMeta::defaults();
            $data['contactForm'] = $form;
            $data['sendError'] = true;
            return $this->twig->render($response, 'index.html.twig', $data);
        }

        $_SESSION['contactSent'] = true;
        return $response
            ->withHeader('Location', $this->basePath . '/#contacto')
            ->withStatus(302);
    }

    /**
     * @param array{name: string, email: string, phone: string, message: string} $form
     * @return array<string, string>
     */
    private function validate(array $form): array
    {
        $errors = [];

        if ($form['name'] === '') {
            $errors['name'] = 'Por favor escriba su nombre';
        }

        if ($form['email'] === '') {
            $errors['email'] = 'Por favor escriba su email';
        } elseif (filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Por favor ingrese una direccion de email valida';
        }

        if ($form['message'] === '') {
            $errors['message'] = 'Por favor escriba su consulta';
        }

        return $errors;
    }

    /**
     * @param array{name: string, email: string, phone: string, message: string} $form
     */
    private function sendMail(array $form): void
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

        $mailer->setFrom($this->mailUsername !== '' ? $this->mailUsername : $this->toAddress, 'Estudio Candame - Sitio web');
        $mailer->addAddress($this->toAddress);
        if ($form['email'] !== '') {
            $mailer->addReplyTo($form['email']);
        }

        $mailer->Subject = 'Contacto desde el sitio de Estudio Candame';
        $mailer->Body = sprintf(
            "Mensaje enviado por %s\nEmail: %s\nTelefono: %s\nMensaje: %s\nEnviado el %s",
            $form['name'],
            $form['email'],
            $form['phone'],
            $form['message'],
            date('d/m/Y'),
        );

        $mailer->send();
    }
}
