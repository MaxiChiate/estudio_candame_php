<?php

declare(strict_types=1);

namespace EstudioCandame\Support\AntiAbuso;

/**
 * Token CSRF por sesion, contra el envio del formulario desde otro sitio. Requiere que
 * bootstrap.php ya haya llamado session_start() -- ya lo hace para todo el sitio.
 *
 * La clave de sesion es un parametro con default al valor historico (el formulario de
 * consulta de constitucion), asi cada formulario tiene su propio token y el del panel
 * de seguimiento no comparte secreto con el publico. Llamar sin argumento se comporta
 * exactamente como antes.
 */
final class CsrfToken
{
    public const CLAVE_CONSULTA_CONSTITUCION = 'csrfTokenConsultaConstitucion';
    public const CLAVE_ADMIN_SEGUIMIENTO = 'csrfTokenAdminSeguimiento';

    public static function generar(string $clave = self::CLAVE_CONSULTA_CONSTITUCION): string
    {
        if (!isset($_SESSION[$clave]) || !is_string($_SESSION[$clave])) {
            $_SESSION[$clave] = bin2hex(random_bytes(32));
        }

        return $_SESSION[$clave];
    }

    public static function validar(?string $token, string $clave = self::CLAVE_CONSULTA_CONSTITUCION): bool
    {
        $esperado = $_SESSION[$clave] ?? null;

        return is_string($esperado) && is_string($token) && $token !== '' && hash_equals($esperado, $token);
    }
}
