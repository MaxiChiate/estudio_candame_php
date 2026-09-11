<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

/**
 * Etapas del tramite de constitucion de SAS, en orden. El valor del case es lo que se
 * guarda en tramite.etapa_actual y tramite_evento.etapa.
 *
 * A diferencia de los otros enums del proyecto (ver Model/RolContacto), este NO tiene
 * un metodo etiqueta(): los labels visibles y las descripciones viven en
 * app/config/etapas.php. La doctora va a renombrar etapas, y renombrar no tiene que
 * requerir tocar codigo ni migrar datos -- por eso el label es configuracion y el
 * value del enum es un identificador estable que nunca se muestra.
 *
 * "Observado" NO es una etapa: es un flag ortogonal (tramite.observado) que puede
 * convivir con cualquiera de estas. No agregarlo aca.
 */
enum Etapa: string
{
    case DOCUMENTACION = 'DOCUMENTACION';
    case FIRMA = 'FIRMA';
    case PRESENTACION = 'PRESENTACION';
    case INSCRIPCION = 'INSCRIPCION';
    case CUIT = 'CUIT';
    case LIBROS = 'LIBROS';

    /** Primera etapa de todo tramite nuevo. */
    public static function inicial(): self
    {
        return self::DOCUMENTACION;
    }

    /**
     * Posicion en la linea de etapas, arrancando en 1. Sirve para comparar avance sin
     * depender del orden de declaracion en el codigo que consume.
     */
    public function orden(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    public function esAnteriorA(self $otra): bool
    {
        return $this->orden() < $otra->orden();
    }

    /** null si es la ultima: el tramite ya recorrio la linea completa. */
    public function siguiente(): ?self
    {
        return self::cases()[$this->orden()] ?? null;
    }

    public function esUltima(): bool
    {
        return $this->siguiente() === null;
    }

    /** @return string[] */
    public static function valores(): array
    {
        return array_map(static fn (self $etapa): string => $etapa->value, self::cases());
    }
}
