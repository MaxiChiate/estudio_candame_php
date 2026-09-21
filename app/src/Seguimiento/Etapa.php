<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

/**
 * Catalogo de etapas posibles. El valor del case es lo que se guarda en
 * tramite.etapa_actual y tramite_evento.etapa.
 *
 * ESTO NO ES UNA SECUENCIA. Hasta la version anterior el orden del enum ERA el
 * pipeline: habia una unica secuencia generica y el operador salteaba a mano las etapas
 * que no aplicaban. El cliente confirmo que eso confunde, asi que ahora cada tipo de
 * tramite tiene su propia secuencia y el orden lo define el flujo (ver Flujo y
 * app/config/flujos.php), elegido al crear el tramite. Aca solo vive el catalogo de lo
 * que puede pasar.
 *
 * El orden de declaracion sobrevive por un unico motivo, y es de desempate: ubicar en
 * la linea una etapa que NO pertenece al flujo del tramite pero que igual ocurrio
 * (el operador puede saltar a cualquier etapa del catalogo). Ver ordenCatalogo() y
 * LineaEtapas. No leerlo como "el pipeline".
 *
 * Los values de los casos preexistentes estan en MAYUSCULAS y los nuevos en minusculas:
 * es deliberado y no se arregla. Los viejos estan persistidos en tramite_evento y en
 * tramite.etapa_actual de todos los tramites cargados, y renombrarlos costaria una
 * migracion de datos a cambio de nada -- el value nunca se muestra.
 *
 * A diferencia de los otros enums del proyecto (ver Model/RolContacto), este NO tiene
 * un metodo etiqueta(): los labels visibles y las descripciones viven en
 * app/config/etapas.php, y un flujo puede pisarlos para sus propias etapas en
 * app/config/flujos.php. La doctora va a renombrar etapas, y renombrar no tiene que
 * requerir tocar codigo ni migrar datos.
 *
 * "Observado" NO es una etapa: es un flag ortogonal (tramite.observado) que puede
 * convivir con cualquiera de estas, y que cubre observaciones de FUERA de IGJ. Las
 * vistas de IGJ son etapas propias. No agregar observado aca.
 */
enum Etapa: string
{
    case REUNIENDO_DOCUMENTACION = 'REUNIENDO_DOCUMENTACION';
    case PROCESANDO_DOCUMENTACION = 'PROCESANDO_DOCUMENTACION';
    case ACTA_ENVIADA_REVISION = 'acta_enviada_revision';
    case ESPERANDO_CONFIRMACION = 'ESPERANDO_CONFIRMACION';
    case PLANILLAS_NOMINAS_DDJJ_FIRMA = 'planillas_nominas_ddjj_firma';
    case PROCESANDO_DOCUMENTACION_RECIBIDA = 'procesando_documentacion_recibida';
    case COPIANDO_ACTAS_AL_LIBRO = 'copiando_actas_al_libro';
    case HABILITADO_ESCRIBANIA = 'HABILITADO_ESCRIBANIA';
    case ESPERANDO_ESCRIBANIA = 'ESPERANDO_ESCRIBANIA';
    case TRAMITE_SUBIDO_TAD = 'tramite_subido_tad';
    case RATIFICACION_GERENTE_ESCRIBANIA = 'ratificacion_gerente_escribania';
    case EDICTO_PUBLICADO = 'EDICTO_PUBLICADO';
    case DICTAMENES = 'DICTAMENES';
    case TRAMITE_INICIADO = 'TRAMITE_INICIADO';
    case TRAMITE_INICIADO_DIGITALMENTE = 'tramite_iniciado_digitalmente';
    case VISTA = 'VISTA';
    case VISTA_CONTESTADA = 'VISTA_CONTESTADA';
    case TERMINADO = 'TERMINADO';
    case PARA_RETIRAR = 'PARA_RETIRAR';

    /**
     * Etapas donde "la siguiente" del flujo no alcanza, porque el inspector puede
     * despachar una vista o no. En las dos se ofrece lo mismo -- que haya vista, o que
     * el tramite termine:
     *
     * - TRAMITE_INICIADO / TRAMITE_INICIADO_DIGITALMENTE: un tramite puede terminar sin
     *   ninguna vista, asi que ofrecer solo VISTA daria por hecho que la hay.
     * - VISTA_CONTESTADA: por orden del flujo daria TERMINADO, pero el caso frecuente es
     *   que el inspector despache otra vista y el tramite vuelva a VISTA.
     *
     * Lo consume CatalogoFlujos::siguientesSugeridas(), que es quien conoce el orden.
     *
     * @return list<self>
     */
    public function sugerenciasDeVista(): array
    {
        return match ($this) {
            self::TRAMITE_INICIADO,
            self::TRAMITE_INICIADO_DIGITALMENTE,
            self::VISTA_CONTESTADA => [self::VISTA, self::TERMINADO],
            default => [],
        };
    }

    /**
     * Posicion en el catalogo, arrancando en 1. NO es la posicion en el pipeline: el
     * pipeline es el flujo del tramite. Sirve solo para ubicar una etapa fuera de flujo
     * entre sus vecinas cuando hay que dibujarla (ver LineaEtapas).
     */
    public function ordenCatalogo(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    /** @return string[] */
    public static function valores(): array
    {
        return array_map(static fn (self $etapa): string => $etapa->value, self::cases());
    }
}
