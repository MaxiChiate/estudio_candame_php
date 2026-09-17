<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

use DateTimeImmutable;
use RuntimeException;

/**
 * Un tramite de constitucion en curso. Todo lo que hay aca es apto para mostrarle al
 * cliente: no guarda socios, documentos ni identificaciones fiscales -- el portal
 * informa en que etapa esta el tramite y nada mas.
 */
final class Tramite
{
    public function __construct(
        public readonly int $id,
        /**
         * Id nuestro (EC-2026-0001), generado al crear el tramite. Es el unico
         * identificador que existe: el numero de expediente de IGJ no se guarda -- el
         * cliente no tiene que verlo, y al dar de alta todavia no existe.
         */
        public readonly string $referencia,
        public readonly string $tipo,
        public readonly string $denominacion,
        public readonly Etapa $etapaActual,
        public readonly bool $observado,
        public readonly ?string $notaObservacion,
        public readonly DateTimeImmutable $creadoEl,
        public readonly DateTimeImmutable $actualizadoEl,
    ) {
    }

    /** @param array<string, mixed> $fila */
    public static function desdeFila(array $fila): self
    {
        $etapa = Etapa::tryFrom((string) $fila['etapa_actual']);
        if ($etapa === null) {
            // Pasa si alguien edito etapa_actual a mano en la base con un valor que no
            // existe en el enum. Preferimos romper fuerte y visible antes que mostrarle
            // al cliente una linea de etapas incoherente.
            throw new RuntimeException(sprintf(
                'Etapa desconocida "%s" en el tramite %s.',
                (string) $fila['etapa_actual'],
                (string) $fila['referencia'],
            ));
        }

        return new self(
            (int) $fila['id'],
            (string) $fila['referencia'],
            (string) $fila['tipo'],
            (string) $fila['denominacion'],
            $etapa,
            (bool) $fila['observado'],
            $fila['nota_observacion'] !== null ? (string) $fila['nota_observacion'] : null,
            new DateTimeImmutable((string) $fila['creado_el']),
            new DateTimeImmutable((string) $fila['actualizado_el']),
        );
    }
}
