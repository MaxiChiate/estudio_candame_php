<?php

declare(strict_types=1);

namespace EstudioCandame\Support\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * HTTP Basic para /admin. No hay sistema de usuarios ni sesiones: son unas credenciales
 * en el .env (ADMIN_USER y ADMIN_PASS_HASH) y alcanza -- la usa una sola persona.
 *
 * La password se guarda hasheada con password_hash() y se verifica con
 * password_verify(), que ya compara en tiempo constante. El usuario se compara con
 * hash_equals() sobre el sha256 de cada lado: comparar los strings crudos con === o con
 * hash_equals() directo filtra el largo del usuario por timing.
 *
 * Si falta cualquiera de las dos variables de entorno, el middleware niega TODO en vez
 * de dejar pasar: un .env incompleto no puede terminar en un panel abierto.
 */
final class AutenticacionBasica implements MiddlewareInterface
{
    public function __construct(
        private readonly string $usuarioEsperado,
        private readonly string $passwordHash,
        private readonly string $realm = 'Estudio Candame',
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if ($this->credencialesValidas($request)) {
            return $handler->handle($request);
        }

        $response = new SlimResponse(401);
        $response->getBody()->write('Acceso restringido.');

        return $response
            ->withHeader('WWW-Authenticate', sprintf('Basic realm="%s", charset="UTF-8"', $this->realm))
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    private function credencialesValidas(Request $request): bool
    {
        // Config incompleta => panel cerrado, nunca abierto.
        if ($this->usuarioEsperado === '' || $this->passwordHash === '') {
            return false;
        }

        $params = $request->getServerParams();
        $usuario = (string) ($params['PHP_AUTH_USER'] ?? '');
        $password = (string) ($params['PHP_AUTH_PW'] ?? '');

        if ($usuario === '' || $password === '') {
            [$usuario, $password] = $this->desdeHeaderAuthorization($request);
        }

        if ($usuario === '' || $password === '') {
            return false;
        }

        // hash_equals sobre digests de largo fijo: no filtra el largo del usuario.
        $usuarioOk = hash_equals(hash('sha256', $this->usuarioEsperado), hash('sha256', $usuario));
        $passwordOk = password_verify($password, $this->passwordHash);

        // "&" en vez de "&&": el operador binario no cortocircuita, asi que las dos
        // verificaciones corren siempre. Con && un usuario incorrecto responderia sin
        // pagar el costo del bcrypt, y esa diferencia de tiempo es medible.
        $ambas = $usuarioOk & $passwordOk;

        return $ambas === 1;
    }

    /**
     * Fallback para cuando PHP no puebla PHP_AUTH_USER. Pasa con PHP en modo CGI/FastCGI
     * (el caso del hosting) si Apache no reenvia el header: por eso public/.htaccess
     * tiene la regla que lo propaga como HTTP_AUTHORIZATION.
     *
     * @return array{0: string, 1: string}
     */
    private function desdeHeaderAuthorization(Request $request): array
    {
        $params = $request->getServerParams();
        $header = (string) ($params['HTTP_AUTHORIZATION']
            ?? $params['REDIRECT_HTTP_AUTHORIZATION']
            ?? $request->getHeaderLine('Authorization'));

        if (stripos($header, 'Basic ') !== 0) {
            return ['', ''];
        }

        $decodificado = base64_decode(substr($header, 6), true);
        if ($decodificado === false || !str_contains($decodificado, ':')) {
            return ['', ''];
        }

        [$usuario, $password] = explode(':', $decodificado, 2);

        return [$usuario, $password];
    }
}
