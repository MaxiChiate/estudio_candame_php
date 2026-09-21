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
    public function testLasCeldasDeDatosLinkeanAlDetalleYElFormDeAvanzarQuedaAfuera(): void
    {
        $id = $this->crearTramite('Listado SAS');
        $this->tramites->avanzar($id, Etapa::PROCESANDO_DOCUMENTACION, null, null);

        $response = $this->listado();
        self::assertSame(200, $response->getStatusCode());

        $dom = new \DOMDocument();
        @$dom->loadHTML((string) $response->getBody());
        $xpath = new \DOMXPath($dom);

        $fila = $xpath->query('//tbody/tr')->item(0);
        self::assertNotNull($fila);

        $celdas = $xpath->query('td', $fila);
        $destino = '/admin/tramites/' . $id;
        // Todas menos la ultima (Avanzar) son un link al detalle.
        for ($i = 0; $i < $celdas->length - 1; $i++) {
            $link = $xpath->query('a', $celdas->item($i))->item(0);
            self::assertInstanceOf(\DOMElement::class, $link, sprintf('La celda %d no tiene link.', $i));
            self::assertSame($destino, $link->getAttribute('href'));
        }

        self::assertSame(0, $xpath->query('.//a//form | .//a//button', $fila)->length, 'Hay un form/boton dentro de un link.');
        self::assertSame(1, $xpath->query('td[last()]//form', $fila)->length, 'El form de avanzar no esta en la celda de accion.');
        self::assertSame(0, $xpath->query('td[last()]//a', $fila)->length, 'La celda de accion no puede navegar.');
    }

    private function listado(): ResponseInterface
    {
        return $this->pedirAlPanel('GET', '/admin/tramites');
    }
}
