<?php

declare(strict_types=1);

/**
 * Labels visibles y descripcion de cada etapa del portal de seguimiento.
 *
 * Las claves son los values de EstudioCandame\Seguimiento\Etapa y son identificadores
 * estables: NO cambiarlas (estan guardadas en tramite.etapa_actual y
 * tramite_evento.etapa de todos los tramites ya cargados). Lo que si se puede editar
 * libremente es 'label' y 'detalle' -- renombrar una etapa es tocar solo este archivo,
 * sin deploy de codigo ni migracion de datos.
 *
 * El texto lo lee el cliente. Registro sobrio: describe en que consiste la etapa, sin
 * prometer resultados ni plazos.
 */
return [
    'DOCUMENTACION' => [
        'label' => 'Documentación',
        'detalle' => 'Reunimos y revisamos la documentación de los socios y los datos de la sociedad.',
    ],
    'FIRMA' => [
        'label' => 'Firma',
        'detalle' => 'El instrumento constitutivo se firma con las formalidades que corresponden a la sociedad.',
    ],
    'PRESENTACION' => [
        'label' => 'Presentación',
        'detalle' => 'El trámite se presenta ante la Inspección General de Justicia.',
    ],
    'INSCRIPCION' => [
        'label' => 'Inscripción',
        'detalle' => 'La Inspección General de Justicia analiza la presentación e inscribe la sociedad.',
    ],
    'CUIT' => [
        'label' => 'CUIT',
        'detalle' => 'Se tramita la clave única de identificación tributaria de la sociedad.',
    ],
    'LIBROS' => [
        'label' => 'Libros',
        'detalle' => 'Se rubrican los libros societarios y contables.',
    ],
];
