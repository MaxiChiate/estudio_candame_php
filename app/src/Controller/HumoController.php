<?php

declare(strict_types=1);

namespace EstudioCandame\Controller;

use EstudioCandame\Pruebas\EnvioPruebaService;
use EstudioCandame\Support\AntiAbuso\RateLimiter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Ruta de diagnostico para verificar, desde produccion, que el hosting deja salir SMTP
 * (eso no se puede probar desde la maquina de desarrollo). Dispara el mismo flujo que
 * bin/consulta-test.php, con el caso fijo self::CASO y el destinatario fijo que trae
 * $envioPrueba ya armado -- este controller no lee ningun dato del request salvo el
 * token, asi que no hay forma de elegir el destinatario desde afuera.
 */
final class HumoController
{
    private const CASO = 'sas-dos-socios';

    public function __construct(
        private readonly EnvioPruebaService $envioPrueba,
        private readonly RateLimiter $rateLimiter,
        private readonly string $tokenEsperado,
        private readonly string $mailHost,
        private readonly int $mailPort,
        private readonly string $remitente,
        private readonly string $destinatario,
    ) {
    }

    public function humo(Request $request, Response $response): Response
    {
        $tokenRecibido = (string) ($request->getQueryParams()['token'] ?? '');
        if ($this->tokenEsperado === '' || !hash_equals($this->tokenEsperado, $tokenRecibido)) {
            return $this->texto($response, 'No encontrado', 404);
        }

        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        if ($ip !== '' && !$this->rateLimiter->permitir($ip)) {
            return $this->texto($response, 'Demasiadas pruebas, esperá un minuto.', 429);
        }

        $resultado = $this->envioPrueba->ejecutar(self::CASO);
        if ($ip !== '') {
            $this->rateLimiter->registrar($ip);
        }

        $lineas = $resultado->lineasResumen($this->mailHost, $this->mailPort, $this->remitente, $this->destinatario);

        return $this->texto($response, implode("\n", $lineas) . "\n", $resultado->ok() ? 200 : 502);
    }

    private function texto(Response $response, string $cuerpo, int $status): Response
    {
        $response->getBody()->write($cuerpo);

        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withStatus($status);
    }
}
