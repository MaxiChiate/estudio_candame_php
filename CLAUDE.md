# CLAUDE.md

Guía para Claude Code al trabajar en este repo.

## Qué es esto

Port a **PHP 8.3 / Slim 4 / Twig 3** del sitio de Estudio Candame (estudio jurídico,
Buenos Aires — constitución de sociedades SRL/SA/SAS ante **IGJ, jurisdicción CABA
únicamente**), migrado desde un proyecto Spring Boot/Kotlin/Thymeleaf porque el hosting
contratado es **cPanel compartido (CloudLinux + Apache, sin JVM)**. Toda la copy del
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

- PHP 8.3, `max_execution_time=60s`, `memory_limit=192M` (vía `.user.ini`, ver
  [Producción](#producción)).
- No usar `exec`/`passthru`/`shell_exec`/`system`/`proc_open`/`popen`/
  `parse_ini_file`/`show_source` ni ninguna librería que las use internamente (ni
  siquiera indirectamente). Esto es política del proyecto, no sólo una restricción del
  hosting: en el deploy verificado en Neolo `disable_functions` está vacío (nada
  deshabilitado a nivel servidor), pero no hay que depender de eso — es hosting
  compartido y esa config puede cambiar sin aviso.
- Sin SSH. **Composer no corre en el servidor** — se instala en local y se sube
  `app/vendor/` ya armado por FTP.
- Sin base de datos.
- Extensiones disponibles: zip, gd, mbstring, dom, xmlwriter, xmlreader, SimpleXML,
  iconv, fileinfo, intl, bcmath, xsl, imagick, curl, openssl, pdo_mysql (esta última sin
  uso actual, no hay DB).

## Producción

Sitio andando en https://estudiocandame.com.ar (cPanel compartido de Neolo).

- PHP productivo: **8.3.32 (ea-php83)**, gestionado por MultiPHP Manager (no por el
  PHP Selector de CloudLinux, que sólo ofrece la 8.1 nativa porque CageFS está
  deshabilitado a nivel servidor). El cambio de versión se aplica vía bloque
  `AddHandler` en `.htaccess` — si ese bloque se borra, el sitio cae a PHP 8.1 y
  Composer aborta con un error de versión mínima.
- `allow_url_fopen` está **Off** (`PHP_INI_SYSTEM`, no se puede cambiar por
  `.user.ini`/`.htaccess`): cualquier request HTTP saliente tiene que ir por cURL,
  nunca `file_get_contents()` con una URL.
- **Permisos al extraer un deploy: archivos 0644, directorios 0755, `.env` 0600.** Un
  `index.php` con permiso de escritura de grupo (0664, lo que deja un `unzip` en el
  administrador de archivos de cPanel) hace que suEXEC le niegue la ejecución a PHP →
  500 sin ninguna entrada en el log de PHP. Es la trampa más cara del deploy y la
  menos obvia.
- Límites de PHP se ajustan con `.user.ini` en `public_html` (no con `php_value` en
  `.htaccess`, y no con el PHP Selector): `memory_limit=192M`, `post_max_size=64M`,
  `upload_max_filesize=32M`, `max_input_vars=5000`, `max_execution_time=60`. Tarda
  ~5 minutos en tomar efecto tras subirlo.
- `MAIL_HOST` productivo es `mail.estudiocandame.com.ar` — `localhost` falla el
  handshake TLS.
- Deploy: `composer install --no-dev --optimize-autoloader` en local → zip de `app/`
  → Cargar + Extraer en el administrador de archivos de cPanel → corregir permisos →
  borrar el zip del servidor. Detalle completo en el README.

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
- **Cache de archivo** (`app/var/cache/*.json`, TTL por `filemtime()`) — reemplaza el
  cache en memoria (`@Volatile`) del proceso Kotlin, porque PHP-FPM/CGI no tiene un
  proceso long-lived. Es una decisión de arquitectura tomada durante el port, no
  dictada por el Kotlin original — si algún día hay más de una cosa para cachear,
  seguir este mismo patrón (JSON + TTL en `app/var/cache/`) en vez de inventar otro
  mecanismo. Tiene que vivir bajo `app/`, no un nivel arriba: en producción,
  `APP_PATH` es `/home/estudicn/app`, y un `../var/` ahí apunta a un directorio de
  sistema de cPanel, no del proyecto (bug real, corregido — ver `routes.php`).

## Reglas del proyecto

- `declare(strict_types=1)` en TODOS los archivos PHP.
- UTF-8 y funciones `mb_*` en todo el código que toque strings (nombres, direcciones,
  texto legal — el estudio opera 100% en español con tildes/ñ).
- Nunca usar `exec`/`shell_exec`/`proc_open` ni librerías que dependan de binarios
  externos (ej. no wkhtmltopdf) — ver política del proyecto en
  [Stack y restricciones del hosting](#stack-y-restricciones-del-hosting).
- Generación de documentos (Excel+Word+zip) tiene que entrar en los 60s de
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
