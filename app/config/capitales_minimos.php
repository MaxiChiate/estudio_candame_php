<?php

declare(strict_types=1);

/**
 * Piso legal de capital social por tipo societario que NO sale del SMVM (el de la SAS
 * es en vivo via SmvmService, art. 40 Ley 27.349 -- nunca duplicarlo aca).
 *
 * SA: Decreto 209/2024 (B.O. 01/03/2024), vigente desde el 01/03/2024.
 * Ultima verificacion de este valor: 2026-08-20.
 *
 * SRL no tiene piso legal: se omite a proposito. CapitalMinimoResolver trata la
 * ausencia de la clave como "sin piso, sin aviso".
 */
return [
    'SA' => [
        'capitalMinimo' => 30_000_000.0,
        'norma' => 'Decreto 209/2024',
        'vigenciaDesde' => '2024-03-01',
        'ultimaVerificacion' => '2026-08-20',
    ],
];
