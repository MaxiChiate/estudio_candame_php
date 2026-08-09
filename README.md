# Estudio Candame — sitio web (PHP)

Port a PHP 8.3 / Slim 4 / Twig 3 del sitio de Estudio Candame, pensado para correr en
hosting compartido cPanel (CloudLinux + Apache) sin JVM y sin acceso a Composer en
el servidor. Andando en producción en https://estudiocandame.com.ar (cPanel de Neolo).

## Stack

- **Slim 4** (routing) + **Twig 3** (templates)
- **PhpSpreadsheet** / **PhpWord** para generar la planilla de datos (.xlsx) y el
  estatuto (.docx) del trámite de constitución de SAS
- **PHPMailer** (SMTP) para el formulario de contacto
- **vlucas/phpdotenv** para configuración por variables de entorno
- Sin base de datos.

## Estructura

```
app/            código fuente, templates y vendor/ — NUNCA se sirve por HTTP directamente
public/         docroot — esto es lo que va a public_html/
```

`public/index.php` es el front controller. La ruta a `app/` sale de la constante
`APP_PATH`, definida en una sola línea al principio de ese archivo — si algún día hay
que mover `app/` adentro de `public_html` (restricción de FTP), alcanza con cambiar esa
línea. `app/.htaccess` (con `Require all denied`) ya está preparado para ese caso, pero
no se usa mientras `app/` viva fuera del docroot.

## Correr en local

Requisitos: PHP 8.3 con las extensiones zip, gd, mbstring, dom, xmlwriter, xmlreader,
SimpleXML, iconv, fileinfo, intl, bcmath, xsl, curl, openssl (las mismas que ofrece el
hosting) y Composer.

```bash
composer install
cp app/.env.example app/.env   # completar SMTP y, si hace falta, CONFIGURADOR_ENABLED
php -S localhost:8000 -t public
```

Abrir `http://localhost:8000`.

El trámite de constitución de SAS (`/tramites/sas/constitucion`) está detrás del flag
`CONFIGURADOR_ENABLED` en `app/.env`. En `false` (default) esa ruta devuelve 404.

## Deploy a producción (cPanel de Neolo)

Sitio en https://estudiocandame.com.ar. Dos formas de deployar:

### Automático (CI)

Push (o merge) a la rama `production` dispara `.github/workflows/deploy.yml`: instala
dependencias con Composer, corre `./deploy.sh --live --env` contra el servidor y valida
con un smoke test que el sitio responda `200`. Rama de trabajo: `development`.

Para redeployar sin un commit nuevo, correr el workflow a mano desde la pestaña
**Actions** (`workflow_dispatch`) — al hacerlo, elegir explícitamente la rama
`production` en el dropdown: por default toma la rama default del repo, que es
`development`, no `production`.

### Manual, en local (`deploy.sh`)

No hay SSH útil ni Composer en el servidor: las dependencias se instalan **en local**
y se sube `app/vendor/` ya generado.

```bash
composer install --no-dev --optimize-autoloader
./deploy.sh              # dry-run: muestra el diff, no toca nada
./deploy.sh --live        # sincroniza por FTPS con lftp
./deploy.sh --live --env  # además sube app/.env.production como /app/.env
```

`deploy.sh` sincroniza `app/` → `/app` y `public/` → `/public_html` por FTPS (`lftp
mirror`) contra `homero.lineadns.com` — el hostname real del servidor, no el dominio: el
certificado FTP está emitido para la máquina de Neolo, no para
`estudiocandame.com.ar`. Requiere `lftp` instalado y un archivo `.ftp.env` en la raíz
del repo (no versionado, gitignored) con `FTP_HOST`/`FTP_USER`/`FTP_PASS`.

Puntos importantes:

- Sincroniza el **working tree local**, no el HEAD de git — cualquier cambio sin
  commitear en `app/` o `public/` se sube igual. Si hay cambios en curso que no se
  quieren deployear, `git stash` antes de `--live`.
- El mirror de `app/` usa `--delete` (el servidor queda como espejo exacto del repo)
  pero excluye `.env`, `.env.*`, `.ftp.env`, `.git*` y `var/cache/` — así no borra el
  `.env` de producción ni el cache en runtime (`SmvmService` lo recrea solo si falta el
  directorio).
- El mirror de `public/` sincroniza `.htaccess` — el bloque `AddHandler` de MultiPHP
  Manager está versionado ahí (ver "PHP en el servidor" abajo), así que ya no hace
  falta revisarlo a mano después de cada deploy. **Ojo:** si alguna vez se cambia la
  versión de PHP a mano desde MultiPHP Manager en cPanel, ese cambio queda sólo en el
  servidor — el próximo deploy lo pisa con lo que diga el repo, hay que reflejar el
  cambio en `public/.htaccess` también.
- Corrige permisos de `index.php` y `.htaccess` a `0644` al final de un `--live` (FTP
  ya sube en `0644` en este hosting, así que normalmente es un no-op) — mismo motivo
  que la trampa de suEXEC de abajo.

Si en algún momento el hosting obliga a mover `app/` adentro de `public_html` (por
restricciones del cliente FTP), cambiar la constante `APP_PATH` en `public/index.php`
y confirmar que `app/.htaccess` (`Require all denied`) esté presente para bloquear el
acceso HTTP directo al código fuente.

### PHP en el servidor

La cuenta usa **MultiPHP Manager**, no el PHP Selector de CloudLinux (el aislamiento
CageFS está deshabilitado a nivel servidor, así que el Selector sólo ofrece la 8.1
nativa). El cambio de versión a **8.3.32 (ea-php83)** se aplica con este bloque, ahora
versionado al principio de `public/.htaccess` (antes de las reglas de rewrite de
Slim) — **tiene que quedar ahí**, si se borra el sitio cae a PHP 8.1 y Composer aborta
con `Your Composer dependencies require a PHP version ">= 8.3.0"`:

```apache
# php -- BEGIN cPanel-generated handler, do not edit
<IfModule mime_module>
  AddHandler application/x-httpd-ea-php83 .php .php8 .phtml
</IfModule>
# php -- END cPanel-generated handler, do not edit
```

Las reglas de Slim (`DirectoryIndex` + rewrite a `index.php`) van agregadas *después*
de ese bloque en el mismo archivo. El servidor web es **Apache** (no LiteSpeed, pese a
lo que sugiere el nombre del build de PHP) — los errores de permisos en el log
aparecen como `authz_core` / `AH01630`, y `mod_rewrite`/`mod_headers` funcionan con
sintaxis estándar.

`disable_functions` está vacío en este hosting (no hay nada deshabilitado a nivel
servidor) — pero el proyecto igual evita `exec`/`shell_exec`/`proc_open` y similares
por política propia, no hay que depender de una config que puede cambiar. Lo que sí es
una restricción dura de sistema (`PHP_INI_SYSTEM`) es `allow_url_fopen = Off`: no se
puede activar ni por `.user.ini` ni por `.htaccess`, así que cualquier request HTTP
saliente (p. ej. `SmvmService` contra la API de datos.gob.ar) tiene que ir por cURL,
nunca `file_get_contents()` con una URL — si usara eso, fallaría siempre en producción
y caería al fallback en silencio.

### Límites de PHP (`.user.ini`)

Los defaults de `ea-php83` son más bajos que los de `ea-php81`. Hay un
`/home/estudicn/public_html/.user.ini` (no viaja en el repo, hay que crearlo a mano en
el servidor) con:

```ini
memory_limit = 192M
post_max_size = 64M
upload_max_filesize = 32M
max_input_vars = 5000
max_execution_time = 60
```

Tarda ~5 minutos en tomar efecto tras subirlo. Sin este archivo, `upload_max_filesize`
queda en 2M — insuficiente el día que se implemente subida de documentos (fotos de
DNI, etc.).

### Trampas del deploy

- El método viejo (zip vía administrador de archivos de cPanel) tenía un bug de
  permisos — archivos en `0664`, directorios en `0775`, rotos por suEXEC (PHP se niega
  a ejecutar un script con permiso de escritura de grupo → 500 sin nada en el log) —
  que había que corregir a mano en cada deploy. Es la razón por la que se abandonó ese
  método a favor de `deploy.sh` por FTPS, que sube directo en `0644`.
- **El `error_log` no se puede leer por HTTP** — `https://dominio/error_log` da 403
  (`authz_core: client denied`). Hay que abrirlo desde el administrador de archivos.
- Cualquier script de diagnóstico temporal (con `display_errors` activado) expone
  rutas absolutas del servidor en los stack traces — borrarlo del servidor apenas se
  termina de usar, no dejarlo "por las dudas".
- `MAIL_HOST=localhost` **no funciona** (falla el handshake TLS) — usar el hostname
  real del dominio (`mail.estudiocandame.com.ar`).
- `date.timezone` del servidor queda en UTC; no importa, `app/bootstrap.php` fuerza
  `America/Argentina/Buenos_Aires` al arrancar sin depender de la config del panel.

## Variables de entorno

Ver `app/.env.example`, documentado inline. Resumen:

| Variable | Uso |
|---|---|
| `APP_DEBUG` | Muestra detalle de errores (`false` en producción) |
| `APP_BASE_PATH` | Sólo si el sitio se sirve desde un subdirectorio |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` / `MAIL_SMTP_AUTH` / `MAIL_SMTP_STARTTLS` | SMTP del formulario de contacto |
| `CONTACT_TO_ADDRESS` | Casilla que recibe las consultas del formulario |
| `CONFIGURADOR_ENABLED` | Feature flag del trámite SAS (`/tramites/sas/constitucion`) |
| `SAS_CAPITAL_MULTIPLO_SMVM` | Múltiplo de SMVM para el capital mínimo de una SAS (art. 40, Ley 27.349) |
| `SAS_SMVM_API_URL` | API de datos.gob.ar consultada para el SMVM vigente |
| `SMVM_FALLBACK_VALOR` / `SMVM_FALLBACK_FECHA` | Valor de respaldo si la API no responde |

## Bugs conocidos de la versión anterior (Kotlin/Thymeleaf) — corregidos en este port

1. **Formato de moneda inconsistente.** Todo el formateo de pesos pasa ahora por un
   único helper (`app/src/Support/Ars.php`, basado en `NumberFormatter` con locale
   `es_AR`).
2. **Tildes y ñ faltantes** en labels fijos de la planilla generada (Correo
   electrónico, Número de documento, Duración de la sociedad (años), Cónyuge,
   Profesión, Jurisdicción, etc.).
3. **Timezone.** Se fuerza `America/Argentina/Buenos_Aires` al arrancar la app
   (`app/bootstrap.php`), sin depender de la configuración del servidor.
4. **Email de socios/administradores** ahora es obligatorio en la validación del
   formulario de constitución de SAS (antes era opcional).

## Redirects heredados del sitio viejo

Además de las rutas ya indexadas del sitio Kotlin (`/servicios`,
`/sindicatura-concursal`, `/concursos-preventivos`, `/sociedades-comerciales`,
`/atencion-contadores-publicos`, `/trayectoria`, `/links`), se agregaron dos redirects
para URLs que quedaron indexadas del sitio estático 2013-2014
(ver `app/config/routes.php`):

- `/sidicatura-concursal` (sin la primera "n" — así está el archivo real en el sitio
  viejo) → `/#sindicatura`
- `/contacto.php` → `/#contacto`

## Sitemap

`public/sitemap.xml` es estático y lista sólo `https://estudiocandame.com.ar/`: el
sitio es una página única con anchors, y las rutas legacy de arriba son redirects
302, no contenido propio — no van en el sitemap. El `sitemap.xml` viejo (heredado de
`webcandame.old`, con URLs `.html` que ya no existen) no se portó.
