<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

/**
 * Volver a copiar un enlace de acceso desde el detalle del tramite, pasando por
 * routes.php de verdad. En la practica el mismo enlace se le manda al cliente mas de
 * una vez, asi que tiene que estar disponible siempre, no solo al emitirlo.
 */
final class AdminCopiarEnlaceTest extends BaseDeDatosTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_SESSION['seguimientoTokenNuevo']);
    }

    public function testElEnlaceSePuedeCopiarEnCadaVisitaAlDetalle(): void
    {
        $id = $this->crearTramite('Copia SRL');
        $token = $this->accesos->emitir($id, 'cliente');

        // Dos visitas: no se consume como el aviso de recien emitido.
        foreach ([1, 2] as $visita) {
            $html = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id)->getBody();

            self::assertStringContainsString(
                'data-copiar="/seguimiento/' . $token . '"',
                $html,
                sprintf('En la visita %d el enlace no se puede copiar.', $visita),
            );
        }
    }

    public function testAlEmitirVuelveALaSeccionDeEnlacesSinAviso(): void
    {
        $id = $this->crearTramite('Aviso SRL');

        $response = $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/accesos', ['etiqueta' => 'cliente'], csrf: true);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/tramites/' . $id . '#enlaces', $response->getHeaderLine('Location'));

        $html = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id)->getBody();
        $token = $this->accesos->porTramite($id)[0]->token;

        self::assertNotNull($token);
        // Con el token guardado no hay cuadro: solo el boton de la fila de la tabla.
        self::assertStringNotContainsString('adminTokenNuevo', $html);
        self::assertSame(1, substr_count($html, 'data-copiar="/seguimiento/' . $token . '"'));
    }

    public function testUnEnlaceRevocadoNoSeOfreceParaCopiar(): void
    {
        $id = $this->crearTramite('Revocado SRL');
        $token = $this->accesos->emitir($id, 'cliente');
        $acceso = $this->accesos->buscarPorToken($token);
        self::assertNotNull($acceso);
        $this->accesos->revocar($acceso->accesoId);

        $html = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id)->getBody();

        self::assertStringNotContainsString($token, $html);
        self::assertStringNotContainsString('data-copiar="', $html);
    }

    public function testUnEnlaceSinCopiaGuardadaSeMarcaYNoSeOfrece(): void
    {
        // Lo que queda de los enlaces emitidos antes de la migracion 005.
        $id = $this->crearTramite('Viejo SRL');
        $token = $this->accesos->emitir($id, 'cliente');
        $this->conexion->pdo()->exec('UPDATE tramite_acceso SET token_cifrado = NULL');

        $html = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id)->getBody();

        self::assertStringNotContainsString($token, $html);
        self::assertStringNotContainsString('data-copiar="', $html);
        self::assertStringContainsString('sin copia', $html);
        // Sigue sirviendo para el cliente.
        self::assertNotNull($this->accesos->buscarPorToken($token));
    }

    public function testSinClaveElAvisoAdvierteQueEsLaUnicaVez(): void
    {
        $id = $this->crearTramite('Sin clave SRL');
        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/accesos', ['etiqueta' => 'cliente'], csrf: true, claveToken: '');

        $html = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id, claveToken: '')->getBody();

        self::assertStringContainsString('&uacute;nica vez', $html);
        self::assertStringContainsString('sin copia', $html);
        // Solo el boton del aviso.
        self::assertSame(1, substr_count($html, 'data-copiar='));
    }

    public function testLosEnlacesDeOtroTramiteNoAparecen(): void
    {
        $id = $this->crearTramite('Propio SRL');
        $otro = $this->crearTramite('Ajeno SRL');
        $tokenAjeno = $this->accesos->emitir($otro, 'otro cliente');

        $html = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id)->getBody();

        self::assertStringNotContainsString($tokenAjeno, $html);
    }
}
