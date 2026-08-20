<?php

declare(strict_types=1);

namespace EstudioCandame\Support\AntiAbuso;

/**
 * Honeypot + tiempo minimo entre que se sirvio el formulario y que se envio. Ninguno de
 * los dos delata al bot: el controller responde con exito falso cuando cualquiera de
 * los dos dispara, en vez de un error que confirme que fue detectado.
 */
final class FormularioAntispam
{
    public const CAMPO_HONEYPOT = 'paginaWeb';
    private const MINIMO_SEGUNDOS_ENTRE_SERVIDO_Y_ENVIO = 3;

    /** @param array<string, mixed> $payload */
    public static function honeypotCompletado(array $payload): bool
    {
        $valor = $payload[self::CAMPO_HONEYPOT] ?? '';

        return is_string($valor) && trim($valor) !== '';
    }

    /** @param array<string, mixed> $payload */
    public static function enviadoDemasiadoRapido(array $payload): bool
    {
        $servidoEn = $payload['formularioServidoEn'] ?? null;
        if (!is_numeric($servidoEn)) {
            return true;
        }

        return (time() - (int) $servidoEn) < self::MINIMO_SEGUNDOS_ENTRE_SERVIDO_Y_ENVIO;
    }
}
