<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

/**
 * Tokens de acceso al portal de seguimiento.
 *
 * El token en claro existe UNA sola vez: se genera, se muestra al emitirlo en el panel
 * y se descarta. En base solo queda el sha256. No loguearlo, no guardarlo en una
 * nota_interna, no mandarlo por un canal que quede registrado del lado del estudio.
 *
 * 16 bytes = 128 bits de entropia, 32 caracteres hex. Alcanza de sobra para que no sea
 * enumerable, y entra comodo en una URL corta que se pueda pasar por mail.
 *
 * hash() se usa tambien para BUSCAR: la consulta va por token_hash, nunca por el token
 * en claro, asi que el valor sensible no llega ni al log de queries lentas.
 */
final class TokenGenerator
{
    /** Largo del token en claro, en caracteres hex. */
    public const LARGO = 32;

    public function generar(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Valida el FORMATO, no la existencia. Se chequea antes de tocar la base para no
     * gastar una consulta con cualquier basura que venga en la URL.
     */
    public static function formatoValido(string $token): bool
    {
        return strlen($token) === self::LARGO && ctype_xdigit($token);
    }
}
