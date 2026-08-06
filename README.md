# Estudio Candame — sitio web (PHP)

Port a PHP 8.3 / Slim 4 / Twig 3 del sitio de Estudio Candame, pensado para correr en
hosting compartido cPanel (CloudLinux + LiteSpeed) sin JVM y sin acceso a Composer en
el servidor.

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

## Deploy por FTP (cPanel)

No hay SSH útil ni Composer en el servidor: las dependencias se instalan **en local**
y se sube `app/vendor/` ya generado.

1. En local: `composer install --no-dev --optimize-autoloader` (o sin `--no-dev` si se
   necesita depurar) y completar `app/.env` con los valores de producción a partir de
   `app/.env.example`.
2. Subir por FTP:
   - el **contenido** de `public/` → `/home/estudicn/public_html/`
   - la carpeta `app/` completa (incluido `vendor/`, excluido `.env` si se prefiere
     completarlo a mano en el servidor) → `/home/estudicn/app/`
3. Confirmar que `/home/estudicn/app/.env` exista en el servidor con los valores reales
   (SMTP, `CONTACT_TO_ADDRESS`, `CONFIGURADOR_ENABLED`, etc.) — no se commitea al repo.
4. En cPanel, configurar el dominio para que su docroot sea `public_html` (ya debería
   estarlo) y que la versión de PHP sea 8.3.
5. Verificar `date.timezone` en el `php.ini` de cPanel: da igual que esté en UTC, la
   aplicación fuerza `America/Argentina/Buenos_Aires` al arrancar
   (`app/bootstrap.php`), pero si se puede configurar en el panel es mejor dejarlo
   consistente.

Si en algún momento el hosting obliga a mover `app/` adentro de `public_html` (por
restricciones del cliente FTP), cambiar la constante `APP_PATH` en `public/index.php`
y confirmar que `app/.htaccess` (`Require all denied`) esté presente para bloquear el
acceso HTTP directo al código fuente.

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
