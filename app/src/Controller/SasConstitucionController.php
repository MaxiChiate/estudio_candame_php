<?php

declare(strict_types=1);

namespace EstudioCandame\Controller;

use EstudioCandame\Model\ConstitucionSasForm;
use EstudioCandame\Service\SasDocumentService;
use EstudioCandame\Service\SmvmService;
use EstudioCandame\Support\Ars;
use EstudioCandame\Support\SiteMeta;
use Normalizer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class SasConstitucionController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly SmvmService $smvmService,
        private readonly SasDocumentService $documentService,
    ) {
    }

    public function form(Request $request, Response $response): Response
    {
        $data = SiteMeta::defaults();
        // El monto en letras (para el estatuto) lo arma SasDocumentService al generar el
        // documento; en el formulario alcanza con mostrar el numero. Todo formato de
        // moneda pasa por Ars (unico helper), tambien en el formulario.
        $capital = $this->smvmService->obtenerCapitalMinimo();
        $data['capitalInfo'] = $capital->toArray();
        $data['capitalInfo']['capitalFormateado'] = Ars::format($capital->capital);
        $data['capitalInfo']['smvmFormateado'] = Ars::formatEntero($capital->smvm);

        return $this->twig->render($response, 'tramites/constitucion-sas.html.twig', $data);
    }

    public function generar(Request $request, Response $response): Response
    {
        $raw = (string) $request->getBody();
        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            return $this->jsonError($response, ['El formulario enviado tiene un formato invalido.'], 400);
        }

        $form = ConstitucionSasForm::fromArray($payload);
        $errors = $form->validate();
        if ($errors !== []) {
            $mensajes = [];
            foreach ($errors as $campo => $mensaje) {
                $mensajes[] = "$campo: $mensaje";
            }

            return $this->jsonError($response, $mensajes, 400);
        }

        $slug = $this->slugify($form->nombreOpcion1 !== '' ? $form->nombreOpcion1 : 'sociedad-sas');
        $zipBytes = $this->documentService->buildZip($form, $slug);

        $response->getBody()->write($zipBytes);

        return $response
            ->withHeader('Content-Disposition', "attachment; filename=\"constitucion-sas-$slug.zip\"")
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withStatus(200);
    }

    /** @param string[] $errores */
    private function jsonError(Response $response, array $errores, int $status): Response
    {
        $response->getBody()->write(json_encode(['errores' => $errores]));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    private function slugify(string $text): string
    {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_D);
        $normalized = preg_replace('/\p{Mn}/u', '', $normalized) ?? $normalized;
        $slug = mb_strtolower($normalized, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'sociedad-sas';
    }
}
