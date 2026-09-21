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
- **MySQL/MariaDB vía PDO** (sin ORM), sólo para el portal de seguimiento de trámites.
  Con `SEGUIMIENTO_ENABLED=false` el sitio no abre ninguna conexión.

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

El trámite de constitución (`/tramites/constitucion`) está detrás del flag
`CONFIGURADOR_ENABLED` en `app/.env`. En `false` (default) esa ruta devuelve 404.

### Base de datos (sólo para el portal de seguimiento)

Todo el sitio funciona sin base. Sólo hace falta si vas a trabajar en el portal de
seguimiento (`SEGUIMIENTO_ENABLED=true`). Con un MySQL o MariaDB local:

```sql
CREATE DATABASE candame_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE candame_test  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'candame_app'@'localhost' IDENTIFIED BY 'la-que-elijas';
GRANT ALL PRIVILEGES ON candame_local.* TO 'candame_app'@'localhost';
GRANT ALL PRIVILEGES ON candame_test.*  TO 'candame_app'@'localhost';
```

Las migraciones se aplican **en orden y a mano** (no hay framework de migraciones ni
SSH al hosting), las dos bases por igual:

```bash
for m in database/migrations/*.sql; do
  mysql -u candame_app -p candame_local < "$m"
  mysql -u candame_app -p candame_test  < "$m"
done
```

`candame_test` es la que usan los tests, y **la truncan en cada corrida** — por eso va
aparte de `candame_local`. Si no hay base configurada, los tests que la necesitan se
saltean (`skipped`) en vez de fallar, y el resto de la suite corre igual.

El hash de `ADMIN_PASS_HASH` se genera con:

```bash
php -r 'echo password_hash("la-password", PASSWORD_DEFAULT), PHP_EOL;'
```

y va **siempre entre comillas simples** en el `.env`: bcrypt arranca con `$2y$` y sin
comillas el parser expande esos `$` como variables y rompe el hash.

## Deploy a producción (cPanel de Neolo)

Sitio en https://estudiocandame.com.ar.

**El deploy se dispara con un push a la rama `production`** — eso corre
`.github/workflows/deploy.yml`, que construye el `vendor/` con Composer, sincroniza por
FTPS con `deploy.sh` y valida con un smoke test que el sitio responda `200`. Rama de
trabajo: `development`.

📖 **La guía completa está en [DEPLOY.md](DEPLOY.md)**: el paso a paso del flujo por CI,
cómo redeployar sin un commit nuevo, el `deploy.sh` manual (y por qué es peligroso
correrlo con el `vendor/` de desarrollo), y qué mirar si el sitio queda en 500.

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

### Trampas del entorno

Las trampas del *procedimiento* de deploy (autoloader inconsistente, permisos de
suEXEC, bloque `AddHandler` borrado) están en [DEPLOY.md](DEPLOY.md#si-el-sitio-queda-en-500).
Las del entorno en sí:

- **El `error_log` no se puede leer por HTTP** — `https://dominio/error_log` da 403
  (`authz_core: client denied`). Hay que bajarlo por FTP o abrirlo desde el
  administrador de archivos.
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
| `SEGUIMIENTO_ENABLED` | Feature flag del portal de seguimiento (`/seguimiento`, `/admin/tramites`). En `false` no se registra ninguna ruta ni se abre conexión a la base |
| `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` / `DB_CHARSET` | Base del portal. Sólo se usan con `SEGUIMIENTO_ENABLED=true` |
| `ADMIN_USER` / `ADMIN_PASS_HASH` | Credenciales HTTP Basic del panel. El hash va entre comillas simples; con cualquiera de las dos vacía el panel queda cerrado |
| `APP_URL` | Origen del sitio, para armar el link completo del token al emitirlo |

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
