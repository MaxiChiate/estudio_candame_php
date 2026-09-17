<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

/**
 * Etapas del pipeline de IGJ, en orden. El valor del case es lo que se guarda en
 * tramite.etapa_actual y tramite_evento.etapa.
 *
 * El pipeline es GENERICO para todo tipo de tramite: no hay secuencias por tipo de
 * sociedad ni por ruta de estatuto. Hay etapas que no aplican a un tramite dado (p. ej.
 * DICTAMENES en una SAS por estatuto modelo) y simplemente se saltean.
 *
 * El orden define como se dibuja la linea de tiempo y cual es la etapa siguiente por
 * default, pero NO restringe a cuales se puede ir: recorrer el pipeline no es lineal.
 * El caso claro es la vista, que es un loop -- el inspector puede despachar mas de una,
 * asi que un tramite vuelve de VISTA_CONTESTADA a VISTA tantas veces como haga falta.
 * Por eso el "cumplida" de la linea sale de los eventos registrados y no de comparar
 * posiciones contra la etapa actual (ver LineaEtapas).
 *
 * A diferencia de los otros enums del proyecto (ver Model/RolContacto), este NO tiene
 * un metodo etiqueta(): los labels visibles y las descripciones viven en
 * app/config/etapas.php. La doctora va a renombrar etapas, y renombrar no tiene que
 * requerir tocar codigo ni migrar datos -- por eso el label es configuracion y el
 * value del enum es un identificador estable que nunca se muestra.
 *
 * "Observado" NO es una etapa: es un flag ortogonal (tramite.observado) que puede
 * convivir con cualquiera de estas, y que cubre observaciones de FUERA de IGJ. Las
 * vistas de IGJ son etapas propias. No agregar observado aca.
 */
enum Etapa: string
{
    case REUNIENDO_DOCUMENTACION = 'REUNIENDO_DOCUMENTACION';
    case PROCESANDO_DOCUMENTACION = 'PROCESANDO_DOCUMENTACION';
    case ESPERANDO_CONFIRMACION = 'ESPERANDO_CONFIRMACION';
    case HABILITADO_ESCRIBANIA = 'HABILITADO_ESCRIBANIA';
    case ESPERANDO_ESCRIBANIA = 'ESPERANDO_ESCRIBANIA';
    case EDICTO_PUBLICADO = 'EDICTO_PUBLICADO';
    case DICTAMENES = 'DICTAMENES';
    case TRAMITE_INICIADO = 'TRAMITE_INICIADO';
    case VISTA = 'VISTA';
    case VISTA_CONTESTADA = 'VISTA_CONTESTADA';
    case TERMINADO = 'TERMINADO';
    case PARA_RETIRAR = 'PARA_RETIRAR';

    /** Primera etapa de todo tramite nuevo. */
    public static function inicial(): self
    {
        return self::REUNIENDO_DOCUMENTACION;
    }

    /**
     * Posicion en la linea de etapas, arrancando en 1. Es el orden en que se dibujan,
     * no una restriccion: se puede ir a cualquier etapa desde cualquier otra.
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

    /**
     * Etapas que el panel ofrece como "proximo paso" de un click.
     *
     * Normalmente es una sola: la siguiente del enum. Las excepciones son las dos etapas
     * donde la vista hace ambiguo el "siguiente", y en las dos se ofrece lo mismo --
     * que haya vista, o que el tramite termine:
     *
     * - TRAMITE_INICIADO: el inspector puede correr una vista o no. Un tramite puede
     *   terminar sin ninguna, asi que ofrecer solo VISTA daria por hecho que la hay.
     * - VISTA_CONTESTADA: por orden del enum daria TERMINADO, pero el caso frecuente es
     *   que el inspector despache otra vista y el tramite vuelva a VISTA.
     *
     * Para ir a cualquier otra etapa esta el select del detalle; esto es solo el atajo
     * del caso habitual.
     *
     * @return list<self>
     */
    public function siguientesSugeridas(): array
    {
        if ($this === self::TRAMITE_INICIADO || $this === self::VISTA_CONTESTADA) {
            return [self::VISTA, self::TERMINADO];
        }

        $siguiente = $this->siguiente();

        return $siguiente === null ? [] : [$siguiente];
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
