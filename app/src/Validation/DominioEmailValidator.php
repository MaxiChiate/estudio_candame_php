<?php

declare(strict_types=1);

namespace EstudioCandame\Validation;

/**
 * Verificar si una casilla puntual existe requeriria un intercambio SMTP (RCPT TO) que la
 * mayoria de los servidores bloquea o responde con falsos positivos (catch-all, greylisting).
 * Como aproximacion best-effort, solo se confirma que el dominio tenga registros MX o A validos.
 * Si la resolucion DNS falla por un problema de red/infra, no se bloquea el envio (permisivo).
 */
final class DominioEmailValidator
{
    public static function esValido(string $email): bool
    {
        if (trim($email) === '') {
            return true;
        }

        $dominio = substr((string) strrchr($email, '@'), 1);
        if ($dominio === '' || $dominio === false) {
            return true;
        }

        // dns_get_record() devuelve false ante un fallo de resolucion (red/infra) y un
        // array vacio cuando la consulta funciono pero el dominio no tiene esos
        // registros: solo el segundo caso se considera invalido, igual que el
        // catch-all permisivo del validador original (JNDI) ante NamingException.
        $registros = @dns_get_record($dominio . '.', DNS_MX + DNS_A);
        if ($registros === false) {
            return true;
        }

        return count($registros) > 0;
    }
}
