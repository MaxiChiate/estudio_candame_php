<?php

declare(strict_types=1);

namespace EstudioCandame\Model;

/**
 * Categorias de actividad tal como figuran, letra por letra, en el articulo tercero
 * del modelo oficial de estatuto SAS preaprobado por IGJ (RG IGJ 12/2024). El texto de
 * cada una se usa verbatim en el estatuto generado.
 */
enum ObjetoSocialCategoria: string
{
    case AGROPECUARIAS = 'Agropecuarias, avícolas, ganaderas, pesqueras, tamberas y vitivinícolas';
    case COMUNICACIONES = 'Comunicaciones, espectáculos, editoriales y gráficas en cualquier soporte';
    case CULTURALES = 'Culturales y educativas';
    case TECNOLOGIA = 'Desarrollo de tecnologías, investigación e innovación y software';
    case GASTRONOMIA = 'Gastronómicas, hoteleras y turísticas';
    case INMOBILIARIAS = 'Inmobiliarias y constructoras';
    case INVERSORAS = 'Inversoras, financieras y fideicomisos';
    case ENERGIA = 'Petroleras, gasíferas, forestales, mineras y energéticas en todas sus formas';
    case SALUD = 'Salud';
    case TRANSPORTE = 'Transporte';

    public function texto(): string
    {
        return $this->value;
    }

    public static function fromName(string $name): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return null;
    }
}
