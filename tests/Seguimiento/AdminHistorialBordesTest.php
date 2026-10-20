<?php

declare(strict_types=1);

namespace EstudioCandame\Tests\Seguimiento;

use EstudioCandame\Seguimiento\Etapa;
use EstudioCandame\Support\AntiAbuso\CsrfToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

/**
 * Casos borde del editor de historial y del borrado de tramites: fechas raras, ids
 * raros, XSS, CSRF en cada ruta, tramites ya borrados y lo que ve el cliente despues.
 */
final class AdminHistorialBordesTest extends BaseDeDatosTestCase
{
    private const XSS = '<script>alert("x")</script><img src=x onerror=alert(1)>';

    // --- XSS -----------------------------------------------------------------------

    public function testNotasConHtmlSeEscapanEnPanelYVistaPublica(): void
    {
        $id = $this->crearTramite('Xss SRL');
        $evento = $this->tramites->eventos($id)[0];

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $evento->id, [
            'ocurrido_el' => '2025-01-01T10:00',
            'nota_publica' => 'PUB' . self::XSS,
            'nota_interna' => 'INT' . self::XSS,
        ], csrf: true);

        $editor = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id . '/historial')->getBody();
        $detalle = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id)->getBody();
        $token = $this->accesos->emitir($id, 'cliente');
        $publica = (string) $this->publico('/seguimiento/' . $token)->getBody();

        foreach (['editor' => $editor, 'detalle' => $detalle, 'publica' => $publica] as $donde => $html) {
            self::assertStringNotContainsString('<script>alert', $html, $donde);
            self::assertStringNotContainsString('<img src=x', $html, $donde);
        }
        self::assertStringContainsString('PUB&lt;script&gt;', $editor);
        self::assertStringContainsString('INT&lt;script&gt;', $editor);
        self::assertStringContainsString('PUB&lt;script&gt;', $publica);
        self::assertStringNotContainsString('INT', $publica);
    }

    public function testDenominacionConHtmlSeEscapaEnDialogoYFlashDeBorrado(): void
    {
        $id = $this->crearTramite('Evil' . self::XSS . ' SRL');

        $detalle = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id)->getBody();
        self::assertStringNotContainsString('<script>alert', $detalle);
        self::assertStringContainsString('<dialog', $detalle);

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/eliminar', csrf: true);
        $listado = (string) $this->pedirAlPanel('GET', '/admin/tramites')->getBody();
        self::assertStringContainsString('Se eliminó el trámite', $listado);
        self::assertStringNotContainsString('<script>alert', $listado);
    }

    /** El confirm() del borrado de evento: el label va escapado para JS dentro de un atributo. */
    public function testElOnsubmitDelBorradoEsJsValidoParaLabelsConTildes(): void
    {
        $id = $this->crearTramite('Tilde SRL');
        $this->tramites->agregarEvento($id, Etapa::TRAMITE_INICIADO, new \DateTimeImmutable('2025-05-05 05:05'), null, null);

        $html = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id . '/historial')->getBody();
        self::assertSame(1, preg_match_all('/onsubmit="return confirm\(\'¿Borrar ([^\']*) del 05\/05\/2025 05:05\?\'\);"/u', $html, $m));
        // Twig js-escape de "Trámite iniciado": no debe quedar ni comilla ni < sin escapar.
        self::assertStringNotContainsString('"', $m[1][0]);
    }

    // --- Notas largas ----------------------------------------------------------------

    /** Tope de 5000 caracteres: justo en el tope entra, uno mas se rechaza sin escribir. */
    public function testNotaEnElTopeSeGuardaYUnaMasLargaSeRechaza(): void
    {
        $id = $this->crearTramite('Larga SRL');
        $evento = $this->tramites->eventos($id)[0];
        $enElTope = str_repeat('ñ', 5000);

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $evento->id, [
            'ocurrido_el' => '2025-01-01T10:00',
            'nota_publica' => $enElTope,
        ], csrf: true);
        self::assertSame($enElTope, $this->tramites->evento($id, $evento->id)?->notaPublica);

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $evento->id, [
            'ocurrido_el' => '2025-01-01T10:00',
            'nota_interna' => str_repeat('ñ', 5001),
        ], csrf: true);
        self::assertSame($enElTope, $this->tramites->evento($id, $evento->id)?->notaPublica);
        self::assertNull($this->tramites->evento($id, $evento->id)?->notaInterna);
    }

    /** Mas de 65535 bytes: TEXT no alcanza. ¿500 o truncado silencioso? */
    public function testNotaQueNoEntraEnTextNoRompeNiTrunca(): void
    {
        $id = $this->crearTramite('Enorme SRL');
        $evento = $this->tramites->eventos($id)[0];
        $nota = str_repeat('a', 70000);

        try {
            $r = $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $evento->id, [
                'ocurrido_el' => '2025-01-01T10:00',
                'nota_publica' => $nota,
            ], csrf: true);
        } catch (\Throwable $e) {
            self::fail('Nota de 70000 bytes revienta con ' . $e::class . ': ' . mb_substr($e->getMessage(), 0, 160));
        }

        self::assertNotSame(500, $r->getStatusCode(), 'Nota de 70000 bytes: el UPDATE revienta (Data too long) y el panel da 500');
        $guardada = $this->tramites->evento($id, $evento->id)?->notaPublica;
        self::assertTrue(
            $guardada === null || $guardada === $nota,
            'Se trunco en silencio a ' . strlen((string) $guardada) . ' bytes (status ' . $r->getStatusCode() . ')',
        );
    }

    // --- Fechas ------------------------------------------------------------------------

    public function testFechaConSegundosSeAceptaYConservaLosSegundos(): void
    {
        $id = $this->crearTramite('Segundos SRL');
        $evento = $this->tramites->eventos($id)[0];

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $evento->id, [
            'ocurrido_el' => '2025-09-15T10:30:45',
        ], csrf: true);

        self::assertSame('2025-09-15 10:30:45', $this->tramites->evento($id, $evento->id)?->ocurridoEl->format('Y-m-d H:i:s'));
    }

    /** @return array<string, array{0: mixed}> */
    public static function fechasInvalidas(): array
    {
        return [
            'vacia' => [''],
            'basura' => ['garbage'],
            'espacio en vez de T' => ['2025-09-15 10:30'],
            'formato argentino' => ['15/09/2025 10:30'],
            'sin ceros' => ['2025-9-5T1:2'],
            'con Z' => ['2025-09-15T10:30Z'],
            'con offset' => ['2025-09-15T10:30-03:00'],
            'con milisegundos' => ['2025-09-15T10:30:00.000'],
            'hora 24' => ['2025-09-15T24:00'],
            'minuto 60' => ['2025-09-15T10:60'],
            'mes 13' => ['2025-13-01T10:00'],
            '31 de febrero' => ['2025-02-31T10:00'],
            '29 feb no bisiesto' => ['2025-02-29T10:00'],
            'solo fecha' => ['2025-09-15'],
            'anio de 5 digitos' => ['20250-09-15T10:30'],
            'anio negativo' => ['-2025-09-15T10:30'],
            'array' => [['2025-09-15T10:30']],
            'unix timestamp' => ['1757940600'],
            'relativa' => ['now'],
        ];
    }

    #[DataProvider('fechasInvalidas')]
    public function testFechasInvalidasNoEscribenAlEditar(mixed $fecha): void
    {
        $id = $this->crearTramite('Fechas SRL');
        $antes = $this->tramites->eventos($id)[0];

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $antes->id, [
            'ocurrido_el' => $fecha,
            'nota_publica' => 'no deberia guardarse',
        ], csrf: true);

        self::assertEquals($antes, $this->tramites->evento($id, $antes->id));
    }

    #[DataProvider('fechasInvalidas')]
    public function testFechasInvalidasNoEscribenAlAgregar(mixed $fecha): void
    {
        $id = $this->crearTramite('Fechas Agregar SRL');

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial', [
            'etapa' => Etapa::DICTAMENES->value,
            'ocurrido_el' => $fecha,
        ], csrf: true);

        self::assertCount(1, $this->tramites->eventos($id));
    }

    public function testFechasBordeDelRangoDeDatetime(): void
    {
        $id = $this->crearTramite('Borde SRL');
        $resultados = [];
        foreach (['0001-01-01T00:00', '0999-12-31T23:59', '1000-01-01T00:00', '9999-12-31T23:59', '2025-02-29T00:00', '2024-02-29T00:00'] as $f) {
            try {
                $r = $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial', [
                    'etapa' => Etapa::DICTAMENES->value,
                    'ocurrido_el' => $f,
                ], csrf: true);
                $resultados[$f] = 'status ' . $r->getStatusCode();
            } catch (\Throwable $e) {
                $resultados[$f] = 'EXCEPTION ' . $e::class . ': ' . mb_substr($e->getMessage(), 0, 120);
            }
        }
        $guardadas = array_values(array_map(
            static fn ($e): string => $e->ocurridoEl->format('Y-m-d H:i'),
            array_filter($this->tramites->eventos($id), static fn ($e): bool => $e->etapa === Etapa::DICTAMENES),
        ));
        foreach ($resultados as $f => $res) {
            self::assertStringNotContainsString('EXCEPTION', $res, $f);
        }

        // Solo entra la fecha real y dentro del rango (1990 a un año desde hoy): los
        // años absurdos son typos que el cliente veria tal cual.
        self::assertSame(['2024-02-29 00:00'], $guardadas);
    }

    /**
     * El input muestra la fecha sin segundos. Guardar solo una nota re-envia ese valor
     * y los segundos se pierden: dos eventos del mismo minuto pueden invertir su orden.
     */
    public function testEditarSoloLaNotaNoCambiaLaFechaGuardada(): void
    {
        $id = $this->crearTramite('Orden SRL');
        $this->tramites->agregarEvento($id, Etapa::PROCESANDO_DOCUMENTACION, new \DateTimeImmutable('2025-03-03 10:00:10'), null, null);
        $this->tramites->agregarEvento($id, Etapa::ESPERANDO_CONFIRMACION, new \DateTimeImmutable('2025-03-03 10:00:50'), null, null);
        $ultimo = null;
        foreach ($this->tramites->eventos($id) as $e) {
            if ($e->etapa === Etapa::ESPERANDO_CONFIRMACION) {
                $ultimo = $e;
            }
        }
        self::assertNotNull($ultimo);

        $html = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id . '/historial')->getBody();
        preg_match('/id="ocurrido_el_' . $ultimo->id . '"[^>]*value="([^"]+)"/s', $html, $m);
        self::assertNotEmpty($m);

        // Lo que manda el browser: el value tal como lo pinto el form, mas una nota.
        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $ultimo->id, [
            'ocurrido_el' => $m[1],
            'nota_publica' => 'solo agrego una nota',
        ], csrf: true);

        self::assertSame(
            '2025-03-03 10:00:50',
            $this->tramites->evento($id, $ultimo->id)?->ocurridoEl->format('Y-m-d H:i:s'),
            'Guardar solo la nota le borro los segundos a la fecha',
        );
    }

    // --- Ids raros -----------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function rutasConIdsRaros(): array
    {
        return [
            'tramite negativo' => ['/admin/tramites/-1/historial'],
            'tramite no numerico' => ['/admin/tramites/abc/historial'],
            'tramite enorme' => ['/admin/tramites/99999999999999999999999/historial'],
            'tramite 0' => ['/admin/tramites/0/historial'],
            'tramite con punto' => ['/admin/tramites/1.5/historial'],
        ];
    }

    #[DataProvider('rutasConIdsRaros')]
    public function testGetDeHistorialConIdRaroNoRevienta(string $ruta): void
    {
        $this->crearTramite('Base SRL');
        $r = $this->pedirAlPanel('GET', $ruta);
        self::assertContains($r->getStatusCode(), [302, 404], 'status ' . $r->getStatusCode());
    }

    public function testEventoIdRaroEsNoOp(): void
    {
        $id = $this->crearTramite('Evento Raro SRL');
        $antes = $this->tramites->eventos($id);

        foreach (['0', '99999999999999999999999', '4294967296'] as $ev) {
            $r1 = $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $ev, ['ocurrido_el' => '2020-01-01T00:00'], csrf: true);
            $r2 = $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $ev . '/eliminar', csrf: true);
            self::assertSame(302, $r1->getStatusCode());
            self::assertSame(302, $r2->getStatusCode());
        }
        foreach (['-1', 'abc'] as $ev) {
            self::assertSame(404, $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $ev, ['ocurrido_el' => '2020-01-01T00:00'], csrf: true)->getStatusCode());
        }

        self::assertEquals($antes, $this->tramites->eventos($id));
    }

    public function testTramiteEnormeEnEliminarYAgregarEsNoOp(): void
    {
        $id = $this->crearTramite('Vivo SRL');
        $r = $this->pedirAlPanel('POST', '/admin/tramites/99999999999999999999999/eliminar', csrf: true);
        self::assertSame(302, $r->getStatusCode());
        $r = $this->pedirAlPanel('POST', '/admin/tramites/99999999999999999999999/historial', [
            'etapa' => Etapa::DICTAMENES->value, 'ocurrido_el' => '2020-01-01T00:00',
        ], csrf: true);
        self::assertSame(302, $r->getStatusCode());
        self::assertNotNull($this->tramites->porId($id));
        self::assertCount(1, $this->tramites->eventos($id));
    }

    // --- Tramite borrado ------------------------------------------------------------------

    public function testTodoSobreUnTramiteBorradoEsNoOp(): void
    {
        $id = $this->crearTramite('Borrado SRL');
        $evento = $this->tramites->eventos($id)[0];
        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/eliminar', csrf: true);

        $respuestas = [
            $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/eliminar', csrf: true),
            $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $evento->id, ['ocurrido_el' => '2020-01-01T00:00'], csrf: true),
            $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $evento->id . '/eliminar', csrf: true),
            $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial', ['etapa' => Etapa::DICTAMENES->value, 'ocurrido_el' => '2020-01-01T00:00'], csrf: true),
            $this->pedirAlPanel('GET', '/admin/tramites/' . $id . '/historial'),
        ];
        foreach ($respuestas as $r) {
            self::assertSame(302, $r->getStatusCode());
            self::assertSame('/admin/tramites', $r->getHeaderLine('Location'));
        }
        // No quedaron eventos huerfanos (la FK igual lo impediria).
        $n = (int) $this->conexion->pdo()->query('SELECT COUNT(*) FROM tramite_evento WHERE tramite_id = ' . $id)->fetchColumn();
        self::assertSame(0, $n);
    }

    public function testBorrarUnTramiteNoTocaAOtro(): void
    {
        $a = $this->crearTramite('A SRL');
        $b = $this->crearTramite('B SRL');
        $tokenB = $this->accesos->emitir($b, 'b');
        $this->pedirAlPanel('POST', '/admin/tramites/' . $a . '/eliminar', csrf: true);

        self::assertNotNull($this->tramites->porId($b));
        self::assertCount(1, $this->tramites->eventos($b));
        self::assertSame(200, $this->publico('/seguimiento/' . $tokenB)->getStatusCode());
    }

    public function testTokenDeTramiteBorradoEsIdenticoAUnoInexistente(): void
    {
        $id = $this->crearTramite('Token SRL');
        $token = $this->accesos->emitir($id, 'cliente');
        self::assertSame(200, $this->publico('/seguimiento/' . $token)->getStatusCode());

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/eliminar', csrf: true);

        $borrado = $this->publico('/seguimiento/' . $token);
        $inexistente = $this->publico('/seguimiento/' . bin2hex(random_bytes(16)));

        self::assertSame(404, $borrado->getStatusCode());
        self::assertSame((string) $inexistente->getBody(), (string) $borrado->getBody());
        $sinFecha = static fn (ResponseInterface $r): array => array_diff_key($r->getHeaders(), ['Date' => 1]);
        self::assertEquals($sinFecha($inexistente), $sinFecha($borrado));
    }

    // --- Linea publica ----------------------------------------------------------------------

    public function testEtapaFueraDeFlujoAgregadaApareceCumplidaEnLaLineaPublica(): void
    {
        $id = $this->crearTramite('Fuera SRL');
        $this->tramites->avanzar($id, Etapa::DICTAMENES, null, null);

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial', [
            'etapa' => Etapa::TRAMITE_SUBIDO_TAD->value,
            'ocurrido_el' => '2025-04-10T12:00',
            'nota_publica' => 'Subimos a TAD.',
            'nota_interna' => 'SECRETO-INTERNO',
        ], csrf: true);

        self::assertSame(Etapa::DICTAMENES, $this->tramites->porId($id)?->etapaActual);
        $token = $this->accesos->emitir($id, 'cliente');
        $html = (string) $this->publico('/seguimiento/' . $token)->getBody();

        self::assertStringContainsString('Trámite subido a la plataforma TAD', $html);
        self::assertStringContainsString('Cumplida el 10/04/2025', $html);
        self::assertStringContainsString('Subimos a TAD.', $html);
        self::assertStringNotContainsString('SECRETO-INTERNO', $html);
        self::assertMatchesRegularExpression('/is-actual"[^>]*aria-current="step"[^<]*<span[^>]*><\/span>\s*<div[^>]*>\s*<p class="seguimientoEtapaLabel">Dictámenes/u', $html);
    }

    public function testBorrarElEventoDeLaEtapaActualLaDejaActualSinFecha(): void
    {
        $id = $this->crearTramite('Actual SRL');
        $this->tramites->avanzar($id, Etapa::PROCESANDO_DOCUMENTACION, null, null);
        $actual = null;
        foreach ($this->tramites->eventos($id) as $e) {
            if ($e->etapa === Etapa::PROCESANDO_DOCUMENTACION) {
                $actual = $e;
            }
        }
        self::assertNotNull($actual);

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $actual->id . '/eliminar', csrf: true);

        $token = $this->accesos->emitir($id, 'cliente');
        $r = $this->publico('/seguimiento/' . $token);
        $html = (string) $r->getBody();
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('aria-current="step"', $html);
        self::assertStringContainsString('Etapa actual', $html);
        self::assertStringNotContainsString('Etapa actual desde el', $html);
    }

    public function testBorrarTodosLosEventosNoRompeNadaYLaActualSigue(): void
    {
        $id = $this->crearTramite('Vacio SRL');
        foreach ($this->tramites->eventos($id) as $e) {
            $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $e->id . '/eliminar', csrf: true);
        }
        self::assertSame([], $this->tramites->eventos($id));

        $token = $this->accesos->emitir($id, 'cliente');
        self::assertSame(200, $this->publico('/seguimiento/' . $token)->getStatusCode());
        $editor = $this->pedirAlPanel('GET', '/admin/tramites/' . $id . '/historial');
        self::assertSame(200, $editor->getStatusCode());
        self::assertStringContainsString('Sin eventos.', (string) $editor->getBody());
        self::assertSame(200, $this->pedirAlPanel('GET', '/admin/tramites/' . $id)->getStatusCode());
        self::assertSame(Etapa::REUNIENDO_DOCUMENTACION, $this->tramites->porId($id)?->etapaActual);
    }

    public function testNotaInternaEditadaNuncaLlegaALaVistaPublica(): void
    {
        $id = $this->crearTramite('Interna SRL');
        $evento = $this->tramites->eventos($id)[0];
        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $evento->id, [
            'ocurrido_el' => '2025-01-01T10:00',
            'nota_interna' => 'ZZ-INTERNA-EDITADA',
        ], csrf: true);
        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial', [
            'etapa' => Etapa::VISTA->value,
            'ocurrido_el' => '2025-02-01T10:00',
            'nota_interna' => 'ZZ-INTERNA-AGREGADA',
        ], csrf: true);

        $token = $this->accesos->emitir($id, 'cliente');
        $html = (string) $this->publico('/seguimiento/' . $token)->getBody();
        self::assertStringNotContainsString('ZZ-INTERNA', $html);
        $editor = (string) $this->pedirAlPanel('GET', '/admin/tramites/' . $id . '/historial')->getBody();
        self::assertStringContainsString('ZZ-INTERNA-EDITADA', $editor);
        self::assertStringContainsString('ZZ-INTERNA-AGREGADA', $editor);
    }

    // --- CSRF / metodos / auth ------------------------------------------------------------------

    /** @return array<string, array{0: ?string}> */
    public static function csrfMalos(): array
    {
        return ['sin token' => [null], 'vacio' => [''], 'incorrecto' => [str_repeat('a', 64)]];
    }

    #[DataProvider('csrfMalos')]
    public function testLasCuatroRutasNuevasExigenCsrf(?string $csrf): void
    {
        $id = $this->crearTramite('Csrf SRL');
        $this->accesos->emitir($id, 'cliente');
        $evento = $this->tramites->eventos($id)[0];
        // Que exista un token en sesion, para que "incorrecto" compare contra algo.
        CsrfToken::generar(CsrfToken::CLAVE_ADMIN_SEGUIMIENTO);
        $extra = $csrf === null ? [] : ['_csrf' => $csrf];

        $rutas = [
            ['/admin/tramites/' . $id . '/historial', ['etapa' => Etapa::DICTAMENES->value, 'ocurrido_el' => '2020-01-01T00:00']],
            ['/admin/tramites/' . $id . '/historial/' . $evento->id, ['ocurrido_el' => '2020-01-01T00:00', 'nota_publica' => 'x']],
            ['/admin/tramites/' . $id . '/historial/' . $evento->id . '/eliminar', []],
            ['/admin/tramites/' . $id . '/eliminar', []],
        ];
        foreach ($rutas as [$ruta, $cuerpo]) {
            $r = $this->pedirAlPanel('POST', $ruta, $cuerpo + $extra);
            self::assertSame(302, $r->getStatusCode(), $ruta);
        }

        self::assertNotNull($this->tramites->porId($id));
        self::assertEquals([$evento], $this->tramites->eventos($id));
        self::assertCount(1, $this->accesos->porTramite($id));
    }

    public function testGetSobreRutasSoloPostDa405(): void
    {
        $id = $this->crearTramite('Metodo SRL');
        $evento = $this->tramites->eventos($id)[0];

        foreach ([
            '/admin/tramites/' . $id . '/eliminar',
            '/admin/tramites/' . $id . '/historial/' . $evento->id,
            '/admin/tramites/' . $id . '/historial/' . $evento->id . '/eliminar',
        ] as $ruta) {
            self::assertSame(405, $this->pedirAlPanel('GET', $ruta)->getStatusCode(), $ruta);
        }
        self::assertNotNull($this->tramites->porId($id));
        self::assertCount(1, $this->tramites->eventos($id));
    }

    public function testSinAutenticacionLasRutasNuevasDan401(): void
    {
        $id = $this->crearTramite('Auth SRL');
        $evento = $this->tramites->eventos($id)[0];
        $csrf = CsrfToken::generar(CsrfToken::CLAVE_ADMIN_SEGUIMIENTO);

        $casos = [
            ['GET', '/admin/tramites/' . $id . '/historial', []],
            ['POST', '/admin/tramites/' . $id . '/historial', ['etapa' => 'DICTAMENES', 'ocurrido_el' => '2020-01-01T00:00', '_csrf' => $csrf]],
            ['POST', '/admin/tramites/' . $id . '/historial/' . $evento->id, ['ocurrido_el' => '2020-01-01T00:00', '_csrf' => $csrf]],
            ['POST', '/admin/tramites/' . $id . '/historial/' . $evento->id . '/eliminar', ['_csrf' => $csrf]],
            ['POST', '/admin/tramites/' . $id . '/eliminar', ['_csrf' => $csrf]],
        ];
        foreach ($casos as [$metodo, $ruta, $cuerpo]) {
            self::assertSame(401, $this->panelSinAuth($metodo, $ruta, $cuerpo)->getStatusCode(), $ruta);
        }
        self::assertNotNull($this->tramites->porId($id));
        self::assertEquals([$evento], $this->tramites->eventos($id));
    }

    public function testEditarUnEventoDeOtroTramiteConMismoIdDeEventoNoFiltraNiModifica(): void
    {
        $a = $this->crearTramite('A SRL');
        $b = $this->crearTramite('B SRL');
        $evB = $this->tramites->eventos($b)[0];
        $this->tramites->editarEvento($evB->id, $evB->ocurridoEl, null, 'NOTA-INTERNA-DE-B');

        $r = $this->pedirAlPanel('GET', '/admin/tramites/' . $a . '/historial');
        self::assertStringNotContainsString('NOTA-INTERNA-DE-B', (string) $r->getBody());

        $r = $this->pedirAlPanel('POST', '/admin/tramites/' . $a . '/historial/' . $evB->id, [
            'ocurrido_el' => '2020-01-01T00:00',
            'nota_interna' => 'pisada',
        ], csrf: true);
        self::assertSame('/admin/tramites', $r->getHeaderLine('Location'));
        self::assertSame('NOTA-INTERNA-DE-B', $this->tramites->evento($b, $evB->id)?->notaInterna);
    }

    public function testAgregarNoMueveEtapaActualNiActualizadoEl(): void
    {
        $id = $this->crearTramite('Quieto SRL');
        $this->tramites->avanzar($id, Etapa::ESPERANDO_CONFIRMACION, null, null);
        $antes = $this->tramites->porId($id);

        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial', [
            'etapa' => Etapa::PARA_RETIRAR->value,
            'ocurrido_el' => '2025-01-01T00:00',
        ], csrf: true);
        $evs = $this->tramites->eventos($id);
        $this->pedirAlPanel('POST', '/admin/tramites/' . $id . '/historial/' . $evs[0]->id . '/eliminar', csrf: true);

        $despues = $this->tramites->porId($id);
        self::assertSame(Etapa::ESPERANDO_CONFIRMACION, $despues?->etapaActual);
        self::assertEquals($antes, $despues);
    }

    // --- helpers ---------------------------------------------------------------------------------

    private function publico(string $ruta): ResponseInterface
    {
        return $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', $ruta));
    }

    /** @param array<string, string> $cuerpo */
    private function panelSinAuth(string $metodo, string $ruta, array $cuerpo): ResponseInterface
    {
        return $this->app()->handle((new ServerRequestFactory())->createServerRequest($metodo, $ruta)->withParsedBody($cuerpo));
    }

    private function app(): \Slim\App
    {
        self::definirAppPath();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $config = self::configuracion();
        self::assertNotNull($config);

        $_ENV['SEGUIMIENTO_ENABLED'] = 'true';
        $_ENV['CONFIGURADOR_ENABLED'] = 'false';
        $_ENV['ADMIN_USER'] = 'candame';
        $_ENV['ADMIN_PASS_HASH'] = password_hash('la-correcta', PASSWORD_DEFAULT);
        $_ENV['DB_HOST'] = $config['host'];
        $_ENV['DB_NAME'] = $config['name'];
        $_ENV['DB_USER'] = $config['user'];
        $_ENV['DB_PASS'] = $config['pass'];
        $_ENV['DB_CHARSET'] = $config['charset'];

        $app = AppFactory::create();
        $twig = Twig::create(APP_PATH . '/templates', ['cache' => false, 'charset' => 'utf-8']);
        $twig->getEnvironment()->addGlobal('basePath', '');
        $twig->getEnvironment()->addGlobal('configuradorEnabled', false);
        $app->add(TwigMiddleware::create($app, $twig));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);
        (require APP_PATH . '/config/routes.php')($app, $twig);

        return $app;
    }
}
