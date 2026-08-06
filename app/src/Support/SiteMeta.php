<?php

declare(strict_types=1);

namespace EstudioCandame\Support;

final class SiteMeta
{
    public const TITLE = 'Estudio Juridico Comercial, Civil, Pedido de Quiebra - Abogados Estudio Candame';

    public const DESCRIPTION = 'El Estudio de Abogados brinda asesoramiento en todas las ramas del Derecho, '
        . 'principalmente Comercial, Civil, Laboral y Familia, Pedido de Quiebra y otras funciones, con actuacion '
        . 'en Capital Federal y en Provincia de Buenos Aires en Argentina.';

    public const KEYWORDS = 'estudio juridico, estudio juridico comercial, estudio laboral, pedido de quiebra, '
        . 'sindicatura concursal, concurso preventivo, sociedades comerciales';

    /**
     * @return array{pageTitle: string, metaDescription: string, metaKeywords: string}
     */
    public static function defaults(): array
    {
        return [
            'pageTitle' => self::TITLE,
            'metaDescription' => self::DESCRIPTION,
            'metaKeywords' => self::KEYWORDS,
        ];
    }
}
