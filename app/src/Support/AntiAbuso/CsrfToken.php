<?php

declare(strict_types=1);

namespace EstudioCandame\Support\AntiAbuso;

/**
 * Token CSRF por sesion, contra el envio del formulario desde otro sitio. Requiere que
 * bootstrap.php ya haya llamado session_start() -- ya lo hace para todo el sitio.
 */
final class CsrfToken
{
    private const SESSION_KEY = 'csrfTokenConsultaConstitucion';

    public static function generar(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function validar(?string $token): bool
    {
        $esperado = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($esperado) && is_string($token) && $token !== '' && hash_equals($esperado, $token);
    }
}
