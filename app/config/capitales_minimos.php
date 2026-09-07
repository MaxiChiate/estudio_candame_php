<?php

declare(strict_types=1);

/**
 * Capital social SUGERIDO (valor de referencia, NO bloqueante) por tipo societario
 * cuando no sale del SMVM. Es solo una orientacion para quien completa la consulta: el
 * formulario permite enviar con un capital menor y la ficha se limita a agregar un
 * aviso informativo, nunca un error.
 *
 * El sugerido de la SAS NO va aca: se calcula en vivo con SmvmService (2 x SMVM
 * vigente, art. 40 Ley 27.349) -- nunca duplicarlo en este archivo.
 *
 * SRL y SA: $500.000 sugeridos. No es un piso legal (la SRL no tiene; el de la SA por
 * decreto es otro numero) -- es el valor que el estudio recomienda usar como punto de
 * partida. Ultima verificacion de este valor: 2026-09-01.
 */
return [
    'SRL' => [
        'capitalSugerido' => 500_000.0,
        'detalle' => 'Es un valor de referencia sugerido, no un mínimo legal: puede enviar la consulta con un importe menor.',
        'ultimaVerificacion' => '2026-09-01',
    ],
    'SA' => [
        'capitalSugerido' => 500_000.0,
        'detalle' => 'Es un valor de referencia sugerido, no un mínimo legal: puede enviar la consulta con un importe menor.',
        'ultimaVerificacion' => '2026-09-01',
    ],
];
