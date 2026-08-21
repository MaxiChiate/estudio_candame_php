<?php

declare(strict_types=1);

namespace EstudioCandame\Pruebas;

use EstudioCandame\Model\ValidationError;

/**
 * Resultado de un EnvioPruebaService::ejecutar(). lineasResumen() es el unico lugar que
 * formatea el resumen de texto -- lo consumen por igual bin/consulta-test.php y
 * HumoController, para que ninguno de los dos reinvente el formato.
 */
final class ResultadoEnvioPrueba
{
    public const VALIDACION_FALLIDA = 'validacion_fallida';
    public const SMTP_FALLIDO = 'smtp_fallido';
    public const COMPLETADO = 'completado';

    /** @param ValidationError[] $errores */
    private function __construct(
        public readonly string $caso,
        public readonly string $estado,
        public readonly array $errores = [],
        public readonly string $nombreArchivo = '',
        public readonly string $xlsxBytes = '',
        public readonly string $mensajeError = '',
    ) {
    }

    /** @param ValidationError[] $errores */
    public static function validacionFallida(string $caso, array $errores): self
    {
        return new self($caso, self::VALIDACION_FALLIDA, errores: $errores);
    }

    public static function smtpFallido(string $caso, string $mensajeError): self
    {
        return new self($caso, self::SMTP_FALLIDO, mensajeError: $mensajeError);
    }

    public static function completado(string $caso, string $nombreArchivo, string $xlsxBytes): self
    {
        return new self($caso, self::COMPLETADO, nombreArchivo: $nombreArchivo, xlsxBytes: $xlsxBytes);
    }

    public function ok(): bool
    {
        return $this->estado === self::COMPLETADO;
    }

    /** @return string[] */
    public function lineasResumen(string $mailHost, int $mailPort, string $remitente, string $destinatario): array
    {
        $lineas = [
            "Caso: {$this->caso}",
            "SMTP: {$mailHost}:{$mailPort}",
            "Remitente: {$remitente}",
            "Destinatario: {$destinatario}",
        ];

        if ($this->nombreArchivo !== '') {
            $lineas[] = "Archivo xlsx: {$this->nombreArchivo}";
        }

        $lineas[] = match ($this->estado) {
            self::COMPLETADO => 'Resultado: OK',
            self::VALIDACION_FALLIDA => 'Resultado: ERROR (validación)',
            self::SMTP_FALLIDO => "Resultado: ERROR (SMTP) - {$this->mensajeError}",
        };

        foreach ($this->errores as $error) {
            $lineas[] = sprintf('  - %s [%s]: %s', $error->campo, $error->codigo, $error->mensaje);
        }

        return $lineas;
    }
}
