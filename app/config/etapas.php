<?php

declare(strict_types=1);

/**
 * Labels visibles, descripcion y pedido al cliente de cada etapa del portal.
 *
 * Las claves son los values de EstudioCandame\Seguimiento\Etapa y son identificadores
 * estables: NO cambiarlas (estan guardadas en tramite.etapa_actual y
 * tramite_evento.etapa de todos los tramites ya cargados). Lo que si se puede editar
 * libremente es el texto -- renombrar una etapa es tocar solo este archivo, sin deploy
 * de codigo ni migracion de datos.
 *
 * Campos:
 *   label      nombre corto, siempre visible en la linea de tiempo.
 *   detalle    texto largo. Se muestra entero en la etapa actual y colapsado en el
 *              resto (<details> nativo, sin JS).
 *   accion     opcional. Cuando existe, se muestra destacado y sin colapsar porque le
 *              pide algo concreto al cliente. Usarlo solo si hay algo que hacer.
 *   opcional   true en las etapas que pueden no ocurrir nunca (las de la vista: un
 *              tramite puede terminar sin ninguna). No se anuncian de antemano -- solo
 *              aparecen en la linea si el tramite efectivamente paso por ahi. Anunciar
 *              "Tramite con vista - Pendiente" le diria al cliente que le espera una
 *              vista, que es exactamente lo que no se sabe.
 *   repeticion opcional. Formato para la 2a vez y siguientes que el tramite pasa por la
 *              etapa; %s es el numero. Solo tiene sentido en las etapas que son un loop
 *              (la vista, que el inspector puede despachar varias veces). El ordinal va
 *              escrito aca y no en el codigo para que concuerde en genero con el label.
 *
 * El texto lo lee el cliente. Registro sobrio: describe en que consiste la etapa, sin
 * prometer resultados ni plazos. El portal es informativo: no anuncia descargas ni
 * links a documentos, aunque la etapa mencione un borrador o un instrumento.
 */
return [
    'REUNIENDO_DOCUMENTACION' => [
        'label' => 'Reuniendo documentación',
        'detalle' => 'Para iniciar el trámite se necesita toda la documentación completa.',
    ],
    'PROCESANDO_DOCUMENTACION' => [
        'label' => 'Procesando documentación',
        'detalle' => 'Se revisa y organiza la documentación, se copian las actas a los libros, entre otros pasos.',
    ],
    'ESPERANDO_CONFIRMACION' => [
        'label' => 'Esperando confirmación',
        'detalle' => 'Se envió el borrador para su confirmación.',
        'accion' => 'Ante cualquier duda o consulta, póngase en contacto con el Estudio.',
    ],
    'HABILITADO_ESCRIBANIA' => [
        'label' => 'Habilitado en escribanía para su firma',
        'detalle' => 'La documentación ya se encuentra disponible para la firma.',
    ],
    'ESPERANDO_ESCRIBANIA' => [
        'label' => 'Esperando escribanía',
        'detalle' => 'La escribana protocoliza y legaliza las actas. Esto puede llevar unos días.',
    ],
    'EDICTO_PUBLICADO' => [
        'label' => 'Edicto publicado',
        'detalle' => 'El edicto fue publicado en el Boletín Oficial.',
    ],
    'DICTAMENES' => [
        'label' => 'Dictámenes',
        'detalle' => 'A la espera de la certificación de dictámenes. Se generan los formularios y se abonan las tasas.',
    ],
    'TRAMITE_INICIADO' => [
        'label' => 'Trámite iniciado',
        'detalle' => 'Presentado en IGJ. Puede haber demora hasta el primer despacho.',
    ],
    'VISTA' => [
        'label' => 'Trámite con vista',
        'detalle' => 'El inspector designado corrió una vista para contestar.',
        'opcional' => true,
        'repeticion' => '%sª vista',
    ],
    'VISTA_CONTESTADA' => [
        'label' => 'Vista contestada',
        'detalle' => 'Esperando que el inspector se expida. Puede despachar otra vista.',
        'opcional' => true,
    ],
    'TERMINADO' => [
        'label' => 'Trámite terminado',
        'detalle' => 'Según el tipo de expediente, IGJ lo entrega en papel o lo envía digitalmente.',
    ],
    'PARA_RETIRAR' => [
        'label' => 'Trámite para retirar',
        'detalle' => 'El trámite está listo para retirar del Estudio.',
        'accion' => 'Contáctese con el Estudio para combinar día y hora.',
    ],
];
