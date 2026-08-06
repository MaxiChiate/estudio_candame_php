# CLAUDE.md

Guía para Claude Code al trabajar en este repo.

## Qué es esto

Port a **PHP 8.3 / Slim 4 / Twig 3** del sitio de Estudio Candame (estudio jurídico,
Buenos Aires — constitución de sociedades SRL/SA/SAS ante **IGJ, jurisdicción CABA
únicamente**), migrado desde un proyecto Spring Boot/Kotlin/Thymeleaf porque el hosting
contratado es **cPanel compartido (CloudLinux + LiteSpeed) sin JVM**. Toda la copy del
sitio está en español.

Este repo es standalone: no depende del proyecto Kotlin en runtime ni en build. El
proyecto Kotlin original sigue existiendo como referencia de solo lectura (ver abajo),
pero el desarrollo activo es acá.

## Referencias de solo lectura (no tocar, no son parte de este repo)

- `../estudio_candame_web` — proyecto Kotlin original, fuente de verdad de contenido y
  lógica de negocio para todo lo que ya fue portado. Sirve para resolver dudas de "¿cómo
  era esto en el original?", no para copiar y pegar código (la implementación en PHP no
  es 1:1, hay decisiones de arquitectura distintas — ver más abajo).
- `../webcandame.old` — sitio estático viejo (2013-2014). Sólo útil para URLs indexadas
  legacy y redirects; su contenido/lógica NO es fuente de verdad de nada.

## Stack y restricciones del hosting

- PHP 8.3, `max_execution_time=30s`, `memory_limit=192M`.
- Funciones deshabilitadas en el server: `exec`, `passthru`, `shell_exec`, `system`,
  `proc_open`, `popen`, `parse_ini_file`, `show_source`. No usar estas funciones ni
  ninguna librería que las use internamente (ni siquiera indirectamente).
- Sin SSH. **Composer no corre en el servidor** — se instala en local y se sube
  `app/vendor/` ya armado por FTP.
- Sin base de datos.
- Extensiones disponibles: zip, gd, mbstring, dom, xmlwriter, xmlreader, SimpleXML,
  iconv, fileinfo, intl, bcmath, xsl, imagick, curl, openssl, pdo_mysql (esta última sin
  uso actual, no hay DB).

## Estructura de directorios (importante, no es negociable)

```
app/            código fuente, templates, vendor/, .env — NUNCA se sirve por HTTP
public/         docroot real — esto es lo que va a public_html/ en cPanel
```

- `public/index.php` define `APP_PATH` en una sola línea (constante) — si el hosting
  alguna vez obliga a mover `app/` adentro de `public_html`, sólo hay que cambiar esa
  línea. `app/.htaccess` con `Require all denied` ya está preparado para ese escenario
  (bloquea acceso HTTP directo si `app/` termina quedando bajo el docroot), pero no se
  usa mientras `app/` viva fuera de `public_html`.
- `public/.htaccess` reescribe todo lo que no sea un archivo existente hacia
  `index.php` (front controller pattern, routing lo maneja Slim).
- `composer.json` tiene `"vendor-dir": "app/vendor"` en `config` — necesario para que
  Composer respete esta estructura (por default pondría `vendor/` al lado de
  `composer.json`, en la raíz).

## Comandos

```bash
composer install
cp app/.env.example app/.env   # completar SMTP y CONFIGURADOR_ENABLED si hace falta
php -S localhost:8000 -t public
```

No hay test suite todavía. No hay linter/formatter configurado todavía.

**Quirk del dev server:** `php -S` da manejo especial a URLs terminadas en `.php`
(las trata como ruta literal a un script, sin pasar por el router), a diferencia de
Apache+`.htaccess` en producción. Esto rompe específicamente el redirect
`/contacto.php` en local — no es un bug de la app, no intentar "arreglarlo" agregando
un `router.php`; ya está documentado en el README como limitación conocida sólo del
dev server built-in.

## Arquitectura

- **Slim 4** para routing (`app/config/routes.php`), **Twig 3** para templates
  (`app/templates/`, patrón extends/block, replica los fragments de Thymeleaf del
  original: `layout/base.html.twig` envuelve `header`/`footer`, `sections/*.html.twig`
  son los anchors de la página única).
- `SiteMeta` (`app/src/Support/SiteMeta.php`) centraliza título/descripción/keywords por
  página, igual que en el Kotlin original — actualizar copy ahí, no en cada template.
- **Formulario de contacto**: `ContactController` usa **PHPMailer** por SMTP (reemplaza
  `JavaMailSender` de Spring). Flash message vía `$_SESSION` (reemplaza
  `RedirectAttributes.addFlashAttribute`).
- **Trámite de constitución de SAS** (`/tramites/sas/constitucion`, GET+POST) — el
  único trámite implementado (no hay SA/SRL, tampoco los había en el Kotlin original).
  Vive detrás del feature flag `CONFIGURADOR_ENABLED` en `.env`: en `false` (default)
  la ruta no se registra → 404. El flag ES el mecanismo, no dejar código comentado como
  alternativa.
  - `SasConstitucionController` — GET arma el form + `capitalInfo`; POST valida JSON y
    devuelve un ZIP para descargar.
  - `SasDocumentService` (el archivo más grande del proyecto, ~560 líneas) — genera la
    planilla `.xlsx` (PhpSpreadsheet) y el estatuto `.docx` (PhpWord) dentro del ZIP.
    Acá vive el texto legal fijo de las cláusulas y todos los labels — si hay que tocar
    texto legal o corregir una tilde faltante, es acá.
  - `SmvmService` — consulta la API de datos.gob.ar para el SMVM vigente (capital mínimo
    SAS, art. 40 Ley 27.349), con fallback si la API no responde.
- **Cache de archivo** (`var/cache/*.json`, TTL por `filemtime()`) — reemplaza el cache
  en memoria (`@Volatile`) del proceso Kotlin, porque PHP-FPM/CGI no tiene un proceso
  long-lived. Es una decisión de arquitectura tomada durante el port, no dictada por el
  Kotlin original — si algún día hay más de una cosa para cachear, seguir este mismo
  patrón (JSON + TTL en `var/cache/`) en vez de inventar otro mecanismo.

## Reglas del proyecto

- `declare(strict_types=1)` en TODOS los archivos PHP.
- UTF-8 y funciones `mb_*` en todo el código que toque strings (nombres, direcciones,
  texto legal — el estudio opera 100% en español con tildes/ñ).
- Nunca usar `exec`/`shell_exec`/`proc_open` ni librerías que dependan de binarios
  externos (ej. no wkhtmltopdf) — estas funciones están deshabilitadas en el hosting.
- Generación de documentos (Excel+Word+zip) tiene que entrar en los 30s de
  `max_execution_time` del hosting — ya validado que entra sin necesidad de partir el
  flujo en dos requests.
- Es un **port**, no un rediseño: preservar contenido y estructura del sitio Kotlin
  original salvo los bugs listados abajo, que sí se corrigieron a propósito.
- **Cuidado con interpolación de strings de PHP en doble comilla**: sólo funciona un
  nivel de `->` (`"$obj->prop"` OK, `"$obj->prop->prop2"` rompe con "Object could not be
  converted to string"). Ya pasó una vez en `SasDocumentService.php` — usar
  concatenación explícita (`.`) cuando se encadena más de un nivel.

## Bugs del Kotlin original corregidos en este port (no reintroducir)

1. Formato de moneda inconsistente → único helper `Ars.php` (`NumberFormatter`, locale
   `es_AR`). Cualquier monto nuevo que se muestre en pantalla o documento debe pasar por
   `Ars::format()`/`Ars::formatEntero()`, no formatear a mano.
2. Tildes/ñ faltantes en labels fijos de la planilla generada (Correo electrónico,
   Número de documento, Duración..años, etc.) — se auditaron TODOS los labels fijos, no
   sólo los 3 casos que se habían detectado inicialmente.
3. Timezone no forzado → `America/Argentina/Buenos_Aires` se fuerza al arrancar en
   `app/bootstrap.php`, sin depender de la config del server.
4. Email de socio/administrador era opcional → ahora es obligatorio en
   `SasPersona::validate()`.

## Decisiones pendientes de revisión del usuario (no resolver sin que el usuario decida)

- **"Publicaciones"**: `webcandame.old` tenía un link de nav a un blog
  (`Publicaciones/index.php`) en todas las páginas. No existe ni en el sitio Kotlin ni
  en este port — no hay contenido fuente para portar. No construir nada acá sin
  instrucción explícita del usuario.

## Fuera de alcance (confirmado, no es un olvido)

SA y SRL no están implementados — tampoco lo estaban en el Kotlin original, sólo SAS.
El resto del pipeline aspiracional descripto en `.claude/rules/configurador.md` del
proyecto Kotlin (planilla unificada con presupuesto, numeración PRES, carpeta de Drive,
Edicto, Dictamen, portal de seguimiento, recordatorios anuales) es diseño a futuro que
tampoco estaba implementado ahí — no había nada que portar. Si se retoma ese trabajo,
es una implementación nueva, no un port.

## Convenciones de git

- Commits chicos y temáticos, uno por unidad de trabajo coherente (scaffold, una
  feature, un fix), para que el historial sea fácil de seguir — no un único commit
  gigante por sesión de trabajo.
- Sin coauthor / sin línea `Co-Authored-By` en los mensajes de commit.
- Sólo commitear cuando el usuario lo pide explícitamente (regla general de Claude
  Code, vale también acá).

## Estado del repo

`git init` propio, **sin remoto todavía**, cambios del port inicial hechos `git add -A`
pero **sin commitear** — el usuario no pidió explícitamente el primer commit. Antes de
crear nuevos commits, confirmar con el usuario si esto sigue así o si ya se resolvió.
