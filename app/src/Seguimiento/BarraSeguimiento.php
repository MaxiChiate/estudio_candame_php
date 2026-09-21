<?php

declare(strict_types=1);

namespace EstudioCandame\Seguimiento;

use Throwable;

/**
 * Resuelve la cookie ec_seg a los datos de la barrita que aparece arriba en la home
 * ("Tramite PRES-2026-0001 - Firma - Ver estado").
 *
 * Regla dura: ESTO NO PUEDE TIRAR ABAJO LA HOME. La home es la pagina principal del
 * estudio y no tiene nada que ver con el portal; si la base esta caida, si la cookie
 * trae basura o si el tramite desaparecio, la barra simplemente no se muestra. Por eso
 * el catch es de Throwable y no de una excepcion puntual: cualquier fallo aca es
 * cosmetico y se traga, dejando rastro en el log del servidor.
 */
final class BarraSeguimiento
{
    public function __construct(
        private readonly AccesoRepository $accesos,
        private readonly TramiteRepository $tramites,
        private readonly CatalogoFlujos $catalogo,
    ) {
    }

    /**
     * @return array{referencia: string, etapaLabel: string, token: string, observado: bool}|null
     *         null si no hay cookie, si el token ya no sirve, o si algo fallo
     */
    public function paraToken(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            // Solo lectura: mostrar la barra no es una visita al portal.
            $acceso = $this->accesos->buscarPorToken($token);
            if ($acceso === null) {
                return null;
            }

            $tramite = $this->tramites->porId($acceso->tramiteId);
            if ($tramite === null) {
                return null;
            }

            return [
                'referencia' => $tramite->referencia,
                'etapaLabel' => $this->catalogo->label($tramite->flujo, $tramite->etapaActual),
                'token' => $token,
                'observado' => $tramite->observado,
            ];
        } catch (Throwable $e) {
            error_log('Seguimiento: no se pudo resolver la barra de la home: ' . $e->getMessage());

            return null;
        }
    }
}
