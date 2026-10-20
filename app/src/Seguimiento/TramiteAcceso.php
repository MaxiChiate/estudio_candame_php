<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

use DateTimeImmutable;

/**
 * Un link de acceso emitido para un tramite.
 *
 * $token es el token en claro, descifrado para que el panel pueda volver a copiar el
 * enlace. Es null si no se puede recuperar: enlace revocado, emitido antes de la
 * migracion 005 o sin SEGUIMIENTO_TOKEN_KEY, o cifrado con otra clave. En todos esos
 * casos el enlace (si no esta revocado) sigue funcionando para el cliente.
 */
final class TramiteAcceso
{
    public function __construct(
        public readonly int $id,
        public readonly int $tramiteId,
        public readonly string $etiqueta,
        public readonly DateTimeImmutable $creadoEl,
        public readonly ?DateTimeImmutable $revocadoEl,
        public readonly ?DateTimeImmutable $ultimoAccesoEl,
        public readonly int $accesos,
        public readonly ?string $token = null,
    ) {
    }

    public function estaRevocado(): bool
    {
        return $this->revocadoEl !== null;
    }

    public function sePuedeCopiar(): bool
    {
        return $this->token !== null;
    }
}
