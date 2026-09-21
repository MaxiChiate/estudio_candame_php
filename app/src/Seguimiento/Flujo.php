<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

/**
 * Tipo de tramite. Discrimina que secuencia de etapas recorre: cada flujo define sus
 * propias etapas y en que orden, en app/config/flujos.php.
 *
 * Se elige al crear el tramite y NO se puede cambiar despues: cambiarlo reescribiria la
 * linea de etapas de un tramite en curso, dejando eventos ya registrados fuera del
 * flujo. Por eso el alta es en dos pasos y muestra la secuencia completa antes de
 * guardar (ver AdminTramitesController). No agregar ninguna ruta ni metodo que lo
 * modifique.
 *
 * El value se guarda en tramite.flujo (la columna era tramite.tipo, ver la migracion
 * 004): es un identificador estable que nunca se muestra. Los nombres visibles y las
 * descripciones viven en la config, igual que los labels de las etapas.
 *
 * Hay dos flujos con la MISMA secuencia de etapas (Art. 60 y Reforma SRL sin cambio de
 * gerencia). Es a proposito: son tramites distintos y la doctora los distingue en el
 * panel. No colapsarlos en uno.
 */
enum Flujo: string
{
    case CONSTITUCION_SRL_SA = 'constitucion_srl_sa';
    case ASOC_CIVIL_DESIGNACION_REFORMA = 'asoc_civil_designacion_reforma';
    case ART_60 = 'art_60';
    case REFORMA_SRL_SIN_CAMBIO_GERENCIA = 'reforma_srl_sin_cambio_gerencia';
    case REFORMA_SRL_CON_CAMBIO_GERENCIA = 'reforma_srl_con_cambio_gerencia';
    case SAS = 'sas';
    case CONSTITUCION_ASOC_CIVIL = 'constitucion_asoc_civil';

    /** @return string[] */
    public static function valores(): array
    {
        return array_map(static fn (self $flujo): string => $flujo->value, self::cases());
    }
}
