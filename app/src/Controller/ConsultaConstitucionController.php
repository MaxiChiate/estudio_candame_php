<?php

declare(strict_types=1);

namespace EstudioCandame\Controller;

use EstudioCandame\Model\ConsultaConstitucionForm;
use EstudioCandame\Model\TipoSocietario;
use EstudioCandame\Model\ValidationError;
use EstudioCandame\Service\CapitalMinimoInfo;
use EstudioCandame\Service\CapitalMinimoResolver;
use EstudioCandame\Service\ConsultaConstitucionMailer;
use EstudioCandame\Service\FichaConstitucionXlsxBuilder;
use EstudioCandame\Support\AntiAbuso\CsrfToken;
use EstudioCandame\Support\AntiAbuso\FormularioAntispam;
use EstudioCandame\Support\AntiAbuso\RateLimiter;
use EstudioCandame\Support\Reloj;
use EstudioCandame\Support\SiteMeta;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ConsultaConstitucionController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly CapitalMinimoResolver $capitalMinimoResolver,
        private readonly FichaConstitucionXlsxBuilder $xlsxBuilder,
        private readonly ConsultaConstitucionMailer $mailer,
        private readonly RateLimiter $rateLimiter,
        private readonly Reloj $reloj,
    ) {
    }

    public function form(Request $request, Response $response): Response
    {
        $data = SiteMeta::defaults();

        $capitalInfoPorTipo = [];
        foreach (TipoSocietario::cases() as $tipo) {
            $capitalInfoPorTipo[$tipo->value] = $this->capitalMinimoResolver->resolver($tipo)->toArray();
        }

        $data['capitalInfoPorTipo'] = $capitalInfoPorTipo;
        $data['csrfToken'] = CsrfToken::generar();
        $data['formularioServidoEn'] = time();
        $data['honeypotCampo'] = FormularioAntispam::CAMPO_HONEYPOT;

        return $this->twig->render($response, 'tramites/constitucion.html.twig', $data);
    }

    public function enviar(Request $request, Response $response): Response
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        if ($ip !== '' && !$this->rateLimiter->permitir($ip)) {
            return $this->jsonError($response, [$this->errorGeneral('RATE_LIMIT_EXCEDIDO', 'Demasiados envíos desde esta conexión, intente nuevamente más tarde.')], 429);
        }

        $raw = (string) $request->getBody();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return $this->jsonError($response, [$this->errorGeneral('PAYLOAD_INVALIDO', 'El formulario enviado tiene un formato inválido.')], 400);
        }

        $csrfToken = $payload['csrfToken'] ?? null;
        if (!CsrfToken::validar(is_string($csrfToken) ? $csrfToken : null)) {
            return $this->jsonError($response, [$this->errorGeneral('CSRF_INVALIDO', 'La sesión expiró, recargue la página e intente de nuevo.')], 400);
        }

        if (FormularioAntispam::honeypotCompletado($payload) || FormularioAntispam::enviadoDemasiadoRapido($payload)) {
            // Exito falso a proposito: no delatar al bot que fue detectado.
            return $this->jsonOk($response);
        }

        $form = ConsultaConstitucionForm::fromArray($payload);
        $ahora = $this->reloj->ahora();
        $capitalMinimo = $form->tipoSocietario !== null
            ? $this->capitalMinimoResolver->resolver($form->tipoSocietario)
            : new CapitalMinimoInfo(TipoSocietario::SAS, null, false, '', '');

        $errores = $form->validate($ahora, $capitalMinimo);
        if ($errores !== []) {
            return $this->jsonError(
                $response,
                array_map(static fn (ValidationError $error): array => $error->toArray(), $errores),
                400,
            );
        }

        $xlsxBytes = $this->xlsxBuilder->build($form, $capitalMinimo, $ahora);
        $nombreArchivo = $this->xlsxBuilder->nombreArchivo($form, $ahora);

        try {
            $this->mailer->enviarConsultaAlEstudio($form, $xlsxBytes, $nombreArchivo);
            $this->mailer->enviarAcuseAlRemitente($form);
        } catch (PHPMailerException $exception) {
            error_log('Failed to send consulta constitucion email: ' . $exception->getMessage());

            return $this->jsonError($response, [$this->errorGeneral('ENVIO_FALLIDO', 'No pudimos enviar la consulta, intente nuevamente en unos minutos.')], 502);
        }

        if ($ip !== '') {
            $this->rateLimiter->registrar($ip);
        }

        return $this->jsonOk($response);
    }

    /** @return array{campo: string, codigo: string, mensaje: string} */
    private function errorGeneral(string $codigo, string $mensaje): array
    {
        return ['campo' => '', 'codigo' => $codigo, 'mensaje' => $mensaje];
    }

    private function jsonOk(Response $response): Response
    {
        $response->getBody()->write((string) json_encode(['ok' => true]));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }

    /** @param array<int, array{campo: string, codigo: string, mensaje: string}> $errores */
    private function jsonError(Response $response, array $errores, int $status): Response
    {
        $response->getBody()->write((string) json_encode(['errores' => $errores]));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
