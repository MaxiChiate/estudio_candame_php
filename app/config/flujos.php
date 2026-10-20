<?php

declare(strict_types=1);

/**
 * Los flujos: que etapas recorre cada tipo de tramite y en que orden.
 *
 * Las claves son los values de EstudioCandame\Seguimiento\Flujo y son identificadores
 * estables: NO cambiarlas (estan guardadas en tramite.flujo de todos los tramites ya
 * cargados). Lo que si se puede editar libremente es el texto y -- con cuidado -- la
 * secuencia.
 *
 * Campos de cada flujo:
 *   nombre      como se llama el tramite en el selector del alta y en el detalle.
 *   descripcion una linea, para que la doctora elija sin dudar en el alta.
 *   etapas      lista ORDENADA. El orden de este array ES el pipeline del flujo: define
 *               como se dibuja la linea que ve el cliente y cual es el proximo paso que
 *               ofrece el panel de un click.
 *
 * Cada entrada de 'etapas' es:
 *   etapa    value de EstudioCandame\Seguimiento\Etapa (obligatorio).
 *   label    opcional. Pisa el label de app/config/etapas.php SOLO en este flujo.
 *   detalle  opcional. Idem con el detalle.
 *
 * Los overrides existen porque la misma etapa significa cosas distintas segun el
 * tramite: en una SAS la escribania prepara la presentacion digital, en una reforma de
 * SRL certifica firmas. El texto generico de etapas.php es el que sirve para el flujo
 * mas comun; los demas lo pisan aca. No hay override de 'accion': si una etapa le pide
 * algo al cliente, se lo pide igual en todos los flujos.
 *
 * Una etapa NO puede repetirse dentro de un flujo (FlujoTest lo verifica): la linea se
 * dibuja una vez por etapa, y el loop de la vista se resuelve con eventos repetidos
 * sobre la misma, no con dos entradas.
 *
 * Todos los flujos terminan igual: el tramite iniciado, el loop opcional de la vista, y
 * el cierre. Esa cola se repite escrita en cada flujo en vez de armarse por codigo --
 * es config, y que se lea completa de arriba a abajo vale mas que ahorrar siete lineas.
 */

use EstudioCandame\Seguimiento\Etapa;

return [
    'constitucion_srl_sa' => [
        'nombre' => 'Constitución SRL / SA',
        'descripcion' => 'Constitución de una SRL o una SA, con escribanía, edictos y dictámenes.',
        'etapas' => [
            ['etapa' => Etapa::REUNIENDO_DOCUMENTACION->value],
            ['etapa' => Etapa::PROCESANDO_DOCUMENTACION->value],
            ['etapa' => Etapa::ESPERANDO_CONFIRMACION->value],
            ['etapa' => Etapa::HABILITADO_ESCRIBANIA->value],
            ['etapa' => Etapa::ESPERANDO_ESCRIBANIA->value],
            ['etapa' => Etapa::EDICTO_PUBLICADO->value],
            ['etapa' => Etapa::DICTAMENES->value],
            ['etapa' => Etapa::TRAMITE_INICIADO->value],
            ['etapa' => Etapa::VISTA->value],
            ['etapa' => Etapa::VISTA_CONTESTADA->value],
            ['etapa' => Etapa::TERMINADO->value],
            ['etapa' => Etapa::PARA_RETIRAR->value],
        ],
    ],

    'asoc_civil_designacion_reforma' => [
        'nombre' => 'Asociación Civil — Designación de autoridades / Reforma',
        'descripcion' => 'Designación de autoridades o reforma de una asociación civil: sin escribanía, edictos ni dictámenes.',
        'etapas' => [
            ['etapa' => Etapa::REUNIENDO_DOCUMENTACION->value],
            [
                'etapa' => Etapa::PROCESANDO_DOCUMENTACION->value,
                // El detalle generico habla del copiado de actas a los libros, que en
                // este flujo es una etapa propia y posterior.
                'detalle' => 'Se revisa y organiza la documentación recibida.',
            ],
            ['etapa' => Etapa::ACTA_ENVIADA_REVISION->value],
            ['etapa' => Etapa::PLANILLAS_NOMINAS_DDJJ_FIRMA->value],
            ['etapa' => Etapa::PROCESANDO_DOCUMENTACION_RECIBIDA->value],
            ['etapa' => Etapa::COPIANDO_ACTAS_AL_LIBRO->value],
            ['etapa' => Etapa::TRAMITE_INICIADO->value],
            ['etapa' => Etapa::VISTA->value],
            ['etapa' => Etapa::VISTA_CONTESTADA->value],
            ['etapa' => Etapa::TERMINADO->value],
            ['etapa' => Etapa::PARA_RETIRAR->value],
        ],
    ],

    'art_60' => [
        'nombre' => 'Art. 60',
        'descripcion' => 'Comunicación de cambio de autoridades (art. 60 LGS), con edictos y dictámenes.',
        'etapas' => [
            ['etapa' => Etapa::REUNIENDO_DOCUMENTACION->value],
            [
                'etapa' => Etapa::PROCESANDO_DOCUMENTACION->value,
                'detalle' => 'Se revisa y organiza la documentación recibida.',
            ],
            ['etapa' => Etapa::ACTA_ENVIADA_REVISION->value],
            ['etapa' => Etapa::COPIANDO_ACTAS_AL_LIBRO->value],
            ['etapa' => Etapa::EDICTO_PUBLICADO->value],
            ['etapa' => Etapa::DICTAMENES->value],
            ['etapa' => Etapa::TRAMITE_INICIADO->value],
            ['etapa' => Etapa::VISTA->value],
            ['etapa' => Etapa::VISTA_CONTESTADA->value],
            ['etapa' => Etapa::TERMINADO->value],
            ['etapa' => Etapa::PARA_RETIRAR->value],
        ],
    ],

    // Misma secuencia que art_60, a proposito: son dos tramites distintos que recorren
    // el mismo pipeline. No colapsarlos en uno -- la doctora los distingue en el panel y
    // cada uno puede evolucionar por su lado.
    'reforma_srl_sin_cambio_gerencia' => [
        'nombre' => 'Reforma SRL — sin cambio de gerencia',
        'descripcion' => 'Reforma del contrato social de una SRL que no cambia la gerencia.',
        'etapas' => [
            ['etapa' => Etapa::REUNIENDO_DOCUMENTACION->value],
            [
                'etapa' => Etapa::PROCESANDO_DOCUMENTACION->value,
                'detalle' => 'Se revisa y organiza la documentación recibida.',
            ],
            ['etapa' => Etapa::ACTA_ENVIADA_REVISION->value],
            ['etapa' => Etapa::COPIANDO_ACTAS_AL_LIBRO->value],
            ['etapa' => Etapa::EDICTO_PUBLICADO->value],
            ['etapa' => Etapa::DICTAMENES->value],
            ['etapa' => Etapa::TRAMITE_INICIADO->value],
            ['etapa' => Etapa::VISTA->value],
            ['etapa' => Etapa::VISTA_CONTESTADA->value],
            ['etapa' => Etapa::TERMINADO->value],
            ['etapa' => Etapa::PARA_RETIRAR->value],
        ],
    ],

    'reforma_srl_con_cambio_gerencia' => [
        'nombre' => 'Reforma SRL — con cambio de gerencia',
        'descripcion' => 'Reforma del contrato social de una SRL que además cambia la gerencia: suma el paso por escribanía.',
        'etapas' => [
            ['etapa' => Etapa::REUNIENDO_DOCUMENTACION->value],
            [
                'etapa' => Etapa::PROCESANDO_DOCUMENTACION->value,
                'detalle' => 'Se revisa y organiza la documentación recibida.',
            ],
            ['etapa' => Etapa::ACTA_ENVIADA_REVISION->value],
            ['etapa' => Etapa::COPIANDO_ACTAS_AL_LIBRO->value],
            ['etapa' => Etapa::HABILITADO_ESCRIBANIA->value],
            [
                'etapa' => Etapa::ESPERANDO_ESCRIBANIA->value,
                'label' => 'Escribanía legalizando firmas',
                'detalle' => 'La escribana certifica las firmas del acta. Esto puede llevar unos días.',
            ],
            ['etapa' => Etapa::EDICTO_PUBLICADO->value],
            ['etapa' => Etapa::DICTAMENES->value],
            ['etapa' => Etapa::TRAMITE_INICIADO->value],
            ['etapa' => Etapa::VISTA->value],
            ['etapa' => Etapa::VISTA_CONTESTADA->value],
            ['etapa' => Etapa::TERMINADO->value],
            ['etapa' => Etapa::PARA_RETIRAR->value],
        ],
    ],

    // La SAS usa ESPERANDO_CONFIRMACION y no ACTA_ENVIADA_REVISION: lo que se manda a
    // revisar es el estatuto, no un acta.
    'sas' => [
        'nombre' => 'SAS',
        'descripcion' => 'Constitución de una SAS: trámite digital por TAD, con ratificación del gerente en escribanía.',
        'etapas' => [
            ['etapa' => Etapa::REUNIENDO_DOCUMENTACION->value],
            ['etapa' => Etapa::PROCESANDO_DOCUMENTACION->value],
            ['etapa' => Etapa::ESPERANDO_CONFIRMACION->value],
            ['etapa' => Etapa::HABILITADO_ESCRIBANIA->value],
            [
                'etapa' => Etapa::ESPERANDO_ESCRIBANIA->value,
                'label' => 'Escribanía procesando el trámite digital',
                'detalle' => 'La escribana prepara la presentación digital del trámite. Esto puede llevar unos días.',
            ],
            ['etapa' => Etapa::TRAMITE_SUBIDO_TAD->value],
            ['etapa' => Etapa::RATIFICACION_GERENTE_ESCRIBANIA->value],
            ['etapa' => Etapa::EDICTO_PUBLICADO->value],
            ['etapa' => Etapa::DICTAMENES->value],
            ['etapa' => Etapa::TRAMITE_INICIADO_DIGITALMENTE->value],
            ['etapa' => Etapa::VISTA->value],
            ['etapa' => Etapa::VISTA_CONTESTADA->value],
            ['etapa' => Etapa::TERMINADO->value],
            ['etapa' => Etapa::PARA_RETIRAR->value],
        ],
    ],

    'constitucion_asoc_civil' => [
        'nombre' => 'Constitución Asociación Civil',
        'descripcion' => 'Constitución de una asociación civil, con protocolización del estatuto. Sin edictos ni dictámenes.',
        'etapas' => [
            ['etapa' => Etapa::REUNIENDO_DOCUMENTACION->value],
            ['etapa' => Etapa::PROCESANDO_DOCUMENTACION->value],
            ['etapa' => Etapa::ACTA_ENVIADA_REVISION->value],
            ['etapa' => Etapa::HABILITADO_ESCRIBANIA->value],
            [
                'etapa' => Etapa::ESPERANDO_ESCRIBANIA->value,
                'label' => 'Protocolización del estatuto en escribanía',
                'detalle' => 'La escribana protocoliza el estatuto. Esto puede llevar unos días.',
            ],
            ['etapa' => Etapa::TRAMITE_INICIADO->value],
            ['etapa' => Etapa::VISTA->value],
            ['etapa' => Etapa::VISTA_CONTESTADA->value],
            ['etapa' => Etapa::TERMINADO->value],
            ['etapa' => Etapa::PARA_RETIRAR->value],
        ],
    ],
];
