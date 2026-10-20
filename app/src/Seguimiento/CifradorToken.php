<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

/**
 * Cifra el token en claro de un enlace para que el panel lo pueda volver a mostrar.
 *
 * AES-256-GCM con la clave SEGUIMIENTO_TOKEN_KEY del .env: la clave vive fuera de la
 * base, asi que un dump de tramite_acceso sin el .env no alcanza para armar ningun
 * enlace. GCM autentica ademas de cifrar: un valor alterado en la base no descifra a
 * otro token, descifra a null.
 *
 * Sin clave (o con una invalida) el cifrador queda deshabilitado: cifrar() devuelve
 * null y el enlace se emite igual, solo que no se puede volver a copiar. Nunca rompe la
 * emision: el portal tiene que seguir andando aunque el .env este incompleto.
 *
 * La busqueda NO pasa por aca: sigue yendo por token_hash (ver TokenGenerator).
 */
final class CifradorToken
{
    private const ALGORITMO = 'aes-256-gcm';
    private const LARGO_CLAVE = 32;
    private const LARGO_NONCE = 12;
    private const LARGO_TAG = 16;

    private readonly ?string $clave;

    /**
     * @param string $claveBase64 32 bytes en base64. Se genera con
     *   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
     */
    public function __construct(string $claveBase64)
    {
        $clave = base64_decode(trim($claveBase64), true);
        $this->clave = is_string($clave) && strlen($clave) === self::LARGO_CLAVE ? $clave : null;
    }

    public function habilitado(): bool
    {
        return $this->clave !== null;
    }

    /** base64(nonce . tag . cifrado), o null si no hay clave configurada. */
    public function cifrar(string $token): ?string
    {
        if ($this->clave === null) {
            return null;
        }

        $nonce = random_bytes(self::LARGO_NONCE);
        $tag = '';
        $cifrado = openssl_encrypt($token, self::ALGORITMO, $this->clave, OPENSSL_RAW_DATA, $nonce, $tag, '', self::LARGO_TAG);
        if ($cifrado === false) {
            return null;
        }

        return base64_encode($nonce . $tag . $cifrado);
    }

    /**
     * El token en claro, o null si no hay clave, si el valor esta corrupto o si se cifro
     * con otra clave. Quien llama no distingue entre esos casos: en todos el enlace
     * simplemente no se puede volver a copiar.
     */
    public function descifrar(string $valor): ?string
    {
        if ($this->clave === null) {
            return null;
        }

        $crudo = base64_decode($valor, true);
        if (!is_string($crudo) || strlen($crudo) <= self::LARGO_NONCE + self::LARGO_TAG) {
            return null;
        }

        $nonce = substr($crudo, 0, self::LARGO_NONCE);
        $tag = substr($crudo, self::LARGO_NONCE, self::LARGO_TAG);
        $cifrado = substr($crudo, self::LARGO_NONCE + self::LARGO_TAG);

        $token = openssl_decrypt($cifrado, self::ALGORITMO, $this->clave, OPENSSL_RAW_DATA, $nonce, $tag);

        return is_string($token) && TokenGenerator::formatoValido($token) ? $token : null;
    }
}
