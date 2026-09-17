<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Cookie que recuerda el ultimo token con el que el visitante entro al portal, para
 * poder ofrecerle la barra de acceso desde la home.
 *
 * Guarda el token EN CLARO: es el mismo secreto que ya esta en la URL que el cliente
 * tiene en su mail, asi que la cookie no agrega exposicion. Por eso va httpOnly (que
 * ningun script de la pagina pueda leerla) y SameSite=Lax (que no viaje en requests
 * cross-site).
 *
 * Secure va condicionado a si la request es HTTPS: en produccion siempre lo es, pero
 * en http://localhost una cookie Secure directamente no se setea y la barra no
 * apareceria nunca en desarrollo. Se condiciona el flag, no se saca.
 */
final class CookieSeguimiento
{
    public const NOMBRE = 'ec_seg';

    private const UN_ANIO_EN_SEGUNDOS = 31536000;

    public static function leer(Request $request): ?string
    {
        $token = $request->getCookieParams()[self::NOMBRE] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public static function valorSet(string $token, bool $seguro): string
    {
        return self::armar($token, self::UN_ANIO_EN_SEGUNDOS, $seguro);
    }

    /** Max-Age=0 borra la cookie del browser. */
    public static function valorBorrar(bool $seguro): string
    {
        return self::armar('', 0, $seguro);
    }

    /**
     * Detras del Apache del hosting el esquema llega directo; X-Forwarded-Proto queda
     * como respaldo por si algun dia hay un proxy o CDN adelante.
     */
    public static function esHttps(Request $request): bool
    {
        if (strtolower($request->getUri()->getScheme()) === 'https') {
            return true;
        }

        return strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https';
    }

    private static function armar(string $valor, int $maxAge, bool $seguro): string
    {
        $partes = [
            self::NOMBRE . '=' . urlencode($valor),
            'Max-Age=' . $maxAge,
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
        ];

        if ($seguro) {
            $partes[] = 'Secure';
        }

        return implode('; ', $partes);
    }
}
