# Deploy a producción

Sitio en https://estudiocandame.com.ar (cPanel compartido de Neolo).

**Regla de oro: se deploya con un push a la rama `production`. Nada más.**
El `deploy.sh` manual es para emergencias con el CI caído — ver
[Deploy manual](#deploy-manual-sólo-emergencias) y por qué es peligroso.

---

## El camino normal (CI)

```bash
# 1. Trabajás en development (es la rama default del repo)
git checkout development
# ...editás, commiteás...

# 2. Probás en local ANTES de publicar (el CI no corre tests)
app/vendor/bin/phpunit
php -S localhost:8000 -t public      # y mirás http://localhost:8000

# 3. Publicás: merge development -> production
git checkout production
git merge development

# 4. Esto dispara el deploy
git push origin production

# 5. Volvés a development para seguir laburando
git checkout development
```

Tarda ~3 minutos.

### Ver cómo va

```bash
gh run watch --exit-status        # sigue el deploy en vivo
gh run list --branch production   # historial de deploys
gh run view <ID> --log-failed     # el log de un deploy que falló
```

En el navegador: repo → pestaña **Actions** → **"Deploy to production"**.

### Redeployar sin un commit nuevo

Actions → **"Deploy to production"** → botón **Run workflow**.

> ⚠️ El desplegable viene con `development` por default. **Cambialo a `production`
> a mano**, o deployás la rama equivocada.

---

## Qué hace el CI exactamente

`.github/workflows/deploy.yml`, disparado por push a `production` o por
`workflow_dispatch`. Corre bajo el Environment `production` (sin reviewers
obligatorios: el deploy sale directo).

1. Checkout del repo.
2. Instala PHP 8.3 con el mismo set de extensiones que el hosting.
3. `composer install --no-dev --optimize-autoloader` → **construye un `app/vendor/`
   limpio y coherente**. Esta es la razón por la que este camino no rompe el sitio.
4. Escribe `.ftp.env` y `app/.env.production` desde los secrets.
5. `bash deploy.sh` (dry-run, queda registrado en el log: muestra qué iba a cambiar).
6. `bash deploy.sh --live --env` (sincroniza por FTPS + sube el `.env` productivo).
7. **Smoke test**: `curl` a `/` esperando `200`. Si no da 200, el job falla en rojo.

Dos deploys nunca corren en paralelo (`concurrency: deploy-production`): si pusheás
dos veces seguidas, el segundo espera al primero.

### Secrets que necesita

A nivel repo (Settings → Secrets and variables → Actions):

| Secret | Qué es |
|---|---|
| `FTP_HOST` | hostname real del servidor de Neolo, **no** el dominio |
| `FTP_USER` | usuario FTP de la cuenta cPanel |
| `FTP_PASS` | contraseña FTP |
| `ENV_PRODUCTION` | contenido completo del `.env` productivo (se sube como `/app/.env`) |

---

## Lo que el CI **no** hace

- **No corre los tests.** Deploya lo que le mandes, aunque esté roto. Por eso el
  `phpunit` del paso 2 es tuyo.
- **No crea el `.user.ini`** de `public_html` (límites de PHP). Ese archivo no viaja
  en el repo, se crea a mano en el servidor una sola vez. Ver README.
- **No toca el `error_log`** ni los archivos de `public_html` que no están en el repo.

---

## Deploy manual (sólo emergencias)

```bash
composer install --no-dev --optimize-autoloader   # ← IMPRESCINDIBLE, ver abajo
./deploy.sh              # dry-run: muestra el diff, no toca nada
./deploy.sh --live       # sincroniza por FTPS con lftp
./deploy.sh --live --env # además sube app/.env.production como /app/.env
```

Requiere `lftp` instalado y un `.ftp.env` en la raíz del repo (gitignored) con
`FTP_HOST` / `FTP_USER` / `FTP_PASS`.

`FTP_HOST` es `homero.lineadns.com` — el **hostname real del servidor**, no el dominio:
el certificado FTP está emitido para la máquina de Neolo, no para
`estudiocandame.com.ar`, así que usar el dominio hace fallar la validación TLS.

### Por qué es peligroso (incidente del 6/9/2026)

Un `./deploy.sh --live` corrido con el `vendor/` de desarrollo **tiró el sitio tres
días**. Así fue:

1. El `vendor/` local tenía dependencias de dev (`phpunit`, `sebastian`, …) y un
   autoloader con un hash distinto al que había construido el CI.
2. `lftp mirror` compara por **tamaño + fecha**. Los archivos del autoloader de
   Composer **cambian de contenido sin cambiar de tamaño** (`autoload_real.php` pesa
   1672 bytes siempre; sólo cambian 32 caracteres de hash adentro).
3. Resultado: subió el `autoload.php` local pero **salteó `autoload_real.php`**, que
   en el server era más nuevo y del mismo tamaño.
4. El autoloader quedó pidiendo una clase que ya no existía → `PHP Fatal error:
   Uncaught Error: Class "ComposerAutoloaderInit…" not found` → **500 en todo el
   sitio**, sin que `deploy.sh` reportara un solo error.

**Antes de cualquier `--live` manual, corré siempre
`composer install --no-dev --optimize-autoloader`** para que tu `vendor/` sea
equivalente al que arma el CI.

### Otras cosas a saber del script

- Sincroniza el **working tree local**, no el HEAD de git — cualquier cambio sin
  commitear en `app/` o `public/` se sube igual. `git stash` antes si hace falta.
- El mirror de `app/` usa `--delete` (el servidor queda como espejo exacto) pero
  excluye `.env`, `.env.*`, `.ftp.env`, `.git*`, `var/cache/` y `var/tmp/`.
- El mirror de `public/` **no** usa `--delete` y excluye `.user.ini` y `error_log`.
- Sincroniza `public/.htaccess`, que tiene versionado el bloque `AddHandler` de
  MultiPHP Manager. Si alguna vez cambiás la versión de PHP a mano desde cPanel, ese
  cambio vive sólo en el servidor hasta que lo reflejes en `public/.htaccess` — si no,
  el próximo deploy lo revierte en silencio.
- Corrige los permisos de `index.php` y `.htaccess` a `0644` al final de un `--live`
  (FTP ya sube en `0644` en este hosting, así que normalmente es un no-op) — es la
  defensa contra la trampa de suEXEC de más abajo.
- **Nunca** saques el pipe `| sed -E "$REDACT_SED"` de las invocaciones de `lftp`:
  `mirror --verbose` loguea cada acción (`get`, `mkdir`, `chmod`) como una URL
  completa con usuario y contraseña en texto plano.

---

## Si el sitio queda en 500

El `error_log` **no se puede leer por HTTP** (`403 authz_core: client denied`). Se baja
por FTP o se abre desde el administrador de archivos de cPanel:

```
/public_html/error_log
```

Diagnóstico rápido — si los estáticos responden pero todo lo demás da 500, el problema
es PHP, no Apache:

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://estudiocandame.com.ar/favicon.ico  # 200 = Apache OK
curl -s -o /dev/null -w "%{http_code}\n" https://estudiocandame.com.ar/             # 500 = PHP roto
```

Los tres modos de falla ya vistos en este proyecto:

| En el `error_log` | Causa | Arreglo |
|---|---|---|
| `Class "ComposerAutoloaderInit…" not found` | autoloader inconsistente por deploy manual | redeploy por CI |
| `Composer detected issues in your platform: … require a PHP version ">= 8.3.0"` | se borró el bloque `AddHandler` de `.htaccess` → cayó a PHP 8.1 | restaurar el bloque en `public/.htaccess` y redeployar |
| *(nada en el log)* + 500 | permisos `0664` en `index.php` → suEXEC le niega la ejecución | `chmod 644`; sólo pasa con el método viejo de zip + cPanel |

**El arreglo casi siempre es el mismo: redeployar por CI** (Actions → Run workflow →
rama `production`), porque reconstruye el `vendor/` desde cero.

---

## Checklist antes de pushear a `production`

- [ ] `app/vendor/bin/phpunit` en verde
- [ ] Probado en `php -S localhost:8000 -t public`
- [ ] Sin cambios sin commitear que no quieras publicar
- [ ] `production` está al día con `development` (`git merge development`)
- [ ] Si tocaste `public/.htaccess`, el bloque `AddHandler` de ea-php83 sigue ahí
