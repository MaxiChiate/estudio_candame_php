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
- **Base de datos: MySQL/MariaDB, sólo para el portal de seguimiento** (`SEGUIMIENTO_ENABLED`).
  Todo el resto del sitio sigue sin tocar la base y funciona con el flag apagado. Acceso
  por PDO directo, sin ORM; migraciones a mano (`database/migrations/*.sql`). En prod la
  base se crea desde cPanel y el esquema se pega en phpMyAdmin.
- Extensiones disponibles: zip, gd, mbstring, dom, xmlwriter, xmlreader, SimpleXML,
  iconv, fileinfo, intl, bcmath, xsl, imagick, curl, openssl, pdo_mysql.

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
cp app/.env.example app/.env   # completar SMTP y los feature flags si hacen falta
php -S localhost:8000 -t public
app/vendor/bin/phpunit

# Sólo si vas a trabajar en el portal de seguimiento (SEGUIMIENTO_ENABLED=true):
mysql -u candame_app -p candame_local < database/migrations/001_seguimiento.sql
mysql -u candame_app -p candame_test  < database/migrations/001_seguimiento.sql
```

Los tests que necesitan base corren contra `candame_test` y **la truncan en cada
corrida**: nunca apuntarlos a `candame_local`. Sin base configurada se saltean
(`skipped`) y el resto de la suite corre igual — ver `tests/Seguimiento/`. Setup
completo de la base en el README.

No hay linter/formatter configurado todavía. Los tests viven en `tests/` (fuera de
`app/`, junto con `phpunit.xml` en la raíz) y cubren la consulta de constitución de
sociedad — ver más abajo. El resto del sitio no tiene tests todavía.

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
- **Consulta de constitución de sociedad** (`/tramites/constitucion`, GET+POST) —
  formulario único para **SAS/SRL/SA** (a diferencia del Kotlin original, que solo tenía
  SAS). Vive detrás del feature flag `CONFIGURADOR_ENABLED` en `.env`: en `false`
  (default) la ruta no se registra → 404. El flag ES el mecanismo, no dejar código
  comentado como alternativa.
  - Es una **consulta**, no un trámite: no genera estatuto, edicto, dictamen ni ningún
    otro instrumento. Solo arma una ficha `.xlsx` con los datos (misma estructura que la
    ficha en papel del estudio) y manda dos mails — el resumen con el xlsx adjunto a
    `info@estudiocandame.com.ar`, y un acuse de texto fijo al remitente. Ver
    `.claude/rules/configurador.md` para el detalle de qué quedó fuera y por qué.
  - `ConsultaConstitucionController` — GET arma el form con el capital mínimo de los 3
    tipos ya resuelto, token CSRF y timestamp de servido; POST valida JSON (todos los
    errores juntos, nunca corta en el primero) y responde `200 {ok:true}` o
    `400 {errores:[...]}` — nunca un archivo para descargar.
  - `FichaConstitucionXlsxBuilder` — genera la planilla. `buildRows()` devuelve las filas
    `[etiqueta, valor]` antes de tocar PhpSpreadsheet (lo que testea
    `tests/Ficha/GoldenTest.php`); acá viven todas las etiquetas de la ficha — si hay que
    corregir una tilde faltante o el orden de las secciones, es acá.
  - `CapitalMinimoResolver` — resuelve el capital sugerido según el tipo, siempre como
    aviso no bloqueante (nunca traba el envío): SAS delega en `SmvmService` (2×SMVM
    vigente), SRL y SA leen `app/config/capitales_minimos.php` (SRL $500.000, SA
    $1.000.000 sugeridos). El campo `bloqueante` de `CapitalMinimoInfo` sigue existiendo
    como mecanismo genérico pero ningún tipo real lo activa.
  - `SmvmService` — consulta la API de datos.gob.ar para el SMVM vigente (capital mínimo
    SAS, art. 40 Ley 27.349), con fallback si la API no responde. Sin cambios respecto al
    port original.
  - `ConsultaConstitucionMailer` — arma y manda los dos mails (PHPMailer, mismo wiring
    SMTP que `ContactController`).
  - `Support/AntiAbuso/` — CSRF por sesión, honeypot, mínimo de 3s entre servido y
    envío, y rate limit por IP en archivo (mismo patrón de cache por archivo que
    `SmvmService`, ver el punto de "Cache de archivo" más abajo).
- **Portal de seguimiento de trámites** (`/seguimiento`, `/admin/tramites`) — detrás del
  flag `SEGUIMIENTO_ENABLED`. Es la **única** parte del sitio que usa base de datos.
  Módulo propio en `app/src/Seguimiento/` (namespace `EstudioCandame\Seguimiento`,
  siguiendo el precedente de `Pruebas/`).
  - Le muestra al cliente en qué etapa está su trámite y **nada más**: ni socios, ni
    documentos, ni identificaciones fiscales, ni descargas. Ver "Fuera de alcance".
  - `tramite.referencia` (`EC-2026-0001`) es el **único identificador** y lo genera
    `TramiteRepository::proximaReferencia()` al crear: correlativo por año, que reinicia
    cada enero para no revelar cuántos trámites lleva el estudio. El prefijo es la
    constante `PREFIJO_REFERENCIA`. **El número de expediente de IGJ no se guarda**: el
    cliente no tiene que verlo y, al dar de alta, todavía no existe — la doctora lo
    maneja por fuera. No reintroducirlo sin instrucción explícita.
  - `Etapa` — enum de las 12 etapas del pipeline de IGJ, en orden. **No tiene
    `etiqueta()` a propósito**: los labels visibles viven en `app/config/etapas.php`
    porque la doctora los va a renombrar, y renombrar no debe requerir tocar código ni
    migrar datos. El `value` del enum es un identificador estable que nunca se muestra y
    que está guardado en la base. Cada entrada de la config tiene `label`, `detalle`,
    y opcionalmente `accion` (pedido concreto al cliente, se muestra destacado y sin
    colapsar), `repeticion` (formato del contador, sólo en las etapas que son un loop) y
    `opcional` (las de la vista: pueden no ocurrir nunca, así que no se anuncian de
    antemano — no aparecen en la línea hasta que el trámite pasa por ahí).
  - **El pipeline es genérico y no se recorre linealmente.** El orden define cómo se
    dibuja la línea y cuál es la etapa siguiente por default, pero se puede ir a
    cualquiera: hay etapas que no aplican a un trámite (Dictámenes en una SAS por
    estatuto modelo) y se saltean, y la vista es un loop — el inspector puede despachar
    más de una, así que un trámite vuelve de `VISTA_CONTESTADA` a `VISTA`. No
    implementar secuencias por tipo de trámite.
  - Por eso **una etapa está cumplida si tiene al menos un evento en `tramite_evento`**,
    no por comparar posiciones contra `etapa_actual` (que sólo marca la que está en
    curso). Con el criterio viejo, volver atrás des-completaba las etapas previas. Como
    efecto, una etapa posterior a la actual puede figurar cumplida: es correcto.
  - Una etapa **salteada** (sin evento, pero anterior a la actual) es un estado propio:
    se pinta como recorrida para que la línea se lea como avance, pero no lleva fecha ni
    texto de estado, porque el trámite nunca pasó por ahí.
  - `observado` es un **flag ortogonal**, no una etapa: un trámite observado sigue
    perteneciendo a su etapa. No meterlo en el enum. Cubre sólo observaciones de **fuera
    de IGJ** (escribanía, documentación incompleta); las vistas de IGJ son etapas.
  - `Conexion` — PDO perezoso (abre recién en el primer `pdo()`). No hay container de DI
    en este proyecto: el "singleton lazy" es esta clase, instanciada en `routes.php`.
  - `TramiteRepository::eventosPublicos()` **no trae `nota_interna` en el SELECT**, y
    devuelve `EventoPublico`, que no tiene esa propiedad. La separación entre lo que ve
    el cliente y lo que ve la doctora se decide en el SQL y en el tipo, no en el
    template. Hay tests que lo verifican por reflexión; no reemplazar `EventoPublico`
    por `TramiteEvento` en la vista pública.
  - `TokenGenerator` — 16 bytes → 32 hex. En base queda **sólo el sha256**; el token en
    claro se muestra una única vez al emitirlo y no se puede recuperar. Nunca loguearlo
    ni guardarlo en una `nota_interna`.
  - `GET /seguimiento/{token}` devuelve **la misma respuesta byte a byte** para token
    inexistente, revocado y malformado. Es deliberado: distinguirlos convierte la ruta
    en un oráculo. Si se toca esa ruta, `PortalPublicoTest` compara los tres cuerpos.
  - Panel bajo HTTP Basic (`AutenticacionBasica`, `ADMIN_USER` + `ADMIN_PASS_HASH`), sin
    sistema de usuarios ni sesiones. Con cualquiera de las dos variables vacía niega
    todo. Requiere la regla de `public/.htaccess` que propaga `Authorization`: con PHP en
    CGI/FastCGI, sin esa regla `PHP_AUTH_USER` llega vacío y el login nunca entra.
  - La barra de la home (`BarraSeguimiento`) **se traga cualquier fallo** (`Throwable`) y
    devuelve `null`: la home no puede caerse porque la base esté caída.
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
- Generación de la ficha (Excel) + envío de los dos mails tiene que entrar en los 60s de
  `max_execution_time` del hosting — ya validado que entra sin necesidad de partir el
  flujo en dos requests.
- Es un **port**, no un rediseño: preservar contenido y estructura del sitio Kotlin
  original salvo los bugs listados abajo, que sí se corrigieron a propósito. (La
  consulta de constitución es la excepción: dejó de ser un port cuando pasó a cubrir
  SAS/SRL/SA y a no generar instrumentos — ver `.claude/rules/configurador.md`.)
- **Cuidado con interpolación de strings de PHP en doble comilla**: sólo funciona un
  nivel de `->` (`"$obj->prop"` OK, `"$obj->prop->prop2"` rompe con "Object could not be
  converted to string"). Ya pasó una vez en el builder de la ficha — usar concatenación
  explícita (`.`) cuando se encadena más de un nivel.

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
   `Persona::validate()`.

## Decisiones pendientes de revisión del usuario (no resolver sin que el usuario decida)

- **"Publicaciones"**: `webcandame.old` tenía un link de nav a un blog
  (`Publicaciones/index.php`) en todas las páginas. No existe ni en el sitio Kotlin ni
  en este port — no hay contenido fuente para portar. No construir nada acá sin
  instrucción explícita del usuario.

## Fuera de alcance del portal de seguimiento (confirmado)

El portal informa la etapa y nada más. NO hace, y no es un olvido: mails automáticos al
cambiar de etapa (es el siguiente paso, no está hecho), descarga de documentos desde el
portal, buscador público por número de trámite (sería enumerable, por eso `/seguimiento`
no tiene ningún campo de ingreso), cuentas para profesionales que derivan, y SA/SRL (por
ahora sólo SAS). La vista pública tampoco muestra socios, DNI, CUIT ni domicilios.

## Fuera de alcance (confirmado, no es un olvido)

La consulta de constitución (SAS/SRL/SA) no genera ningún instrumento: ni estatuto, ni
edicto, ni dictamen, ni presupuesto, ni numeración de expedientes, ni carpeta de Drive,
ni recordatorios anuales. **Tampoco alimenta el portal de seguimiento**: son dos
features separadas y no hay ningún vínculo entre ellas — un trámite del portal se carga
a mano desde `/admin/tramites`, no sale de una consulta enviada. Tampoco pide, valida, guarda ni
escribe en ningún archivo la clave fiscal ni el apellido materno de nadie — para eso
alcanza con el checkbox de trámite urgente, la doctora junta esos datos por su cuenta.
Ese pipeline aspiracional (el que describía `.claude/rules/configurador.md` del proyecto
Kotlin) nunca se implementó ahí tampoco — no hay nada que portar. Si se retoma ese
trabajo, es una implementación nueva, no un port. Ver `.claude/rules/configurador.md`
de este repo para el detalle completo de qué se sacó y por qué.

## Convenciones de git

- Commits chicos y temáticos, uno por unidad de trabajo coherente (scaffold, una
  feature, un fix), para que el historial sea fácil de seguir — no un único commit
  gigante por sesión de trabajo.
- Sin coauthor / sin línea `Co-Authored-By` en los mensajes de commit.
- Sólo commitear cuando el usuario lo pide explícitamente (regla general de Claude
  Code, vale también acá).

## Estado del repo

Remoto en GitHub (`origin` → `MaxiChiate/estudio_candame_php`, privado). `development`
es la rama default (antes era `master`; se renombró, se empujó y se borró `master` del
remoto el 2026-08-09). `production` dispara el deploy automático por CI al recibir un
push — ver "Deploy por FTP" abajo.

## Deploy (`deploy.sh` + CI)

Dos caminos, mismo mecanismo de fondo:

- **CI:** push a `production` dispara `.github/workflows/deploy.yml` (Environment
  `production` en GitHub, secrets a nivel repo: `FTP_HOST`, `FTP_USER`, `FTP_PASS`,
  `ENV_PRODUCTION` — sin reviewers obligatorios, deploy directo). El job instala
  dependencias, corre `deploy.sh --live --env` y valida con un smoke test (`curl` a
  `/` esperando `200`). `workflow_dispatch` para redeployar sin commit nuevo — ojo, por
  default toma la rama default del repo (`development`), hay que elegir `production` a
  mano en el dropdown de Actions.
- **Manual, en local:** `./deploy.sh [--live] [--env]` sincroniza por FTPS con `lftp
  mirror` contra el cPanel de Neolo. Usa `.ftp.env` (gitignored, credenciales reales).

En ambos casos: **nunca** correr `lftp mirror` en modo `--verbose`/`-d` sin el pipe de
redacción (`$REDACT_SED` en el script) — lftp loguea cada acción (`get`, `mkdir`,
`chmod`, no sólo `chmod`) como una URL completa con usuario y contraseña en texto
plano. Ya está arreglado en el script (pipe `sed` en las tres invocaciones de `lftp`),
pero si se toca ese archivo, no sacar ese pipe de ninguna.

`public/.htaccess` ahora incluye, versionado, el bloque `AddHandler` de MultiPHP
Manager (antes de las reglas de rewrite de Slim) y `deploy.sh` lo sincroniza sin
excluirlo (decisión del usuario 2026-08-09, invierte la exclusión original). Si algún
día se cambia la versión de PHP a mano desde MultiPHP Manager en cPanel, ese cambio
vive sólo en el servidor hasta que se refleje en `public/.htaccess` — si no, el
próximo deploy lo revierte silenciosamente.
