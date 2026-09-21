<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use EstudioCandame\Seguimiento\Etapa;
use Psr\Http\Message\ResponseInterface;

/**
 * Listado del panel: cada fila tiene que llevar al detalle con links reales, y el form
 * de "avanzar" no puede quedar anidado dentro de ninguno.
 */
final class AdminListadoTest extends BaseDeDatosTestCase
{
    /**
     * Toda la fila lleva al detalle, y el listado no tiene ningun boton de avanzar: se
     * confundia con el de entrar al tramite. Avanzar se hace desde adentro.
     */
    public function testTodasLasCeldasLinkeanAlDetalleYNoHayBotonDeAvanzar(): void
    {
        $id = $this->crearTramite('Listado SAS');
        $this->tramites->avanzar($id, Etapa::PROCESANDO_DOCUMENTACION, null, null);

        $response = $this->listado();
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();

        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $fila = $xpath->query('//tbody/tr')->item(0);
        self::assertNotNull($fila);

        $celdas = $xpath->query('td', $fila);
        $destino = '/admin/tramites/' . $id;
        for ($i = 0; $i < $celdas->length; $i++) {
            $link = $xpath->query('a', $celdas->item($i))->item(0);
            self::assertInstanceOf(\DOMElement::class, $link, sprintf('La celda %d no tiene link.', $i));
            self::assertSame($destino, $link->getAttribute('href'));
        }

        self::assertSame(0, $xpath->query('//tbody//form | //tbody//button', $dom)->length, 'El listado no puede tener botones de avanzar.');
        self::assertStringNotContainsString('/avanzar', $html);
    }

    private function listado(): ResponseInterface
    {
        return $this->pedirAlPanel('GET', '/admin/tramites');
    }
}
