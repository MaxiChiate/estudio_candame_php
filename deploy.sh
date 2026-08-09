#!/usr/bin/env bash
#
# deploy.sh — sincroniza el proyecto contra el cPanel de Neolo por FTPS.
#
#   ./deploy.sh              dry-run: muestra qué haría, no toca nada
#   ./deploy.sh --live       ejecuta de verdad
#   ./deploy.sh --live --env además sube app/.env.production como /app/.env
#
# Requiere lftp y un archivo .ftp.env en la raíz del repo (no versionado) con
# las credenciales de la cuenta FTP de cPanel:
#
#   FTP_HOST=homero.lineadns.com
#   FTP_USER=cfbeaf76@estudiocandame.com.ar
#   FTP_PASS=...
#
# FTP_HOST es el hostname real del servidor, NO el dominio: el certificado del
# demonio FTP está emitido para la máquina de Neolo, no para estudiocandame.com.ar.
#
# IMPORTANTE: este script sincroniza el WORKING TREE local, no el HEAD de git.
# Cualquier cambio sin commitear en app/ o public/ se sube igual. Si hay
# cambios en curso que no querés deployear, hacé `git stash` antes de
# --live (o commiteá/descartá lo que corresponda).

set -euo pipefail

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# --- configuración -----------------------------------------------------------

FTP_ENV=".ftp.env"              # credenciales del servidor (no las lee la app)
PROD_ENV="app/.env.production"  # config de la app para producción

REMOTE_APP="/app"
REMOTE_PUB="/public_html"

# lftp arma internamente cada acción (get/mkdir/rm/chmod) de "mirror --verbose"
# como una URL completa "ftp://usuario:contraseña@host/...", así que TODA
# invocación de lftp de este script va con este filtro en el medio — sin él,
# la contraseña real queda impresa en la terminal (y en el scrollback/historial)
# en cada corrida, incluso en dry-run. No sacar este pipe de ningún `lftp <<LFTP`.
REDACT_SED='s#(://[^:/@]+):[^@/]+@#\1:REDACTED@#g'

# --- flags -------------------------------------------------------------------

DRY="--dry-run"
PUSH_ENV=0

for arg in "$@"; do
  case "$arg" in
    --live) DRY="" ;;
    --env)  PUSH_ENV=1 ;;
    *) echo "Flag desconocido: $arg" >&2; exit 1 ;;
  esac
done

# --- validaciones ------------------------------------------------------------

command -v lftp >/dev/null || { echo "Falta lftp (sudo apt install lftp)" >&2; exit 1; }

[[ -f "$FTP_ENV" ]] || { echo "Falta $FTP_ENV en la raíz del repo" >&2; exit 1; }
set -a; source "$FTP_ENV"; set +a
: "${FTP_HOST:?FTP_HOST no definido en $FTP_ENV}"
: "${FTP_USER:?FTP_USER no definido en $FTP_ENV}"
: "${FTP_PASS:?FTP_PASS no definido en $FTP_ENV}"

[[ -d app && -d public ]] || { echo "Ejecutá esto desde la raíz del repo" >&2; exit 1; }

# vendor/ tiene que estar armado: en el servidor no hay Composer.
[[ -d app/vendor ]] || {
  echo "Falta app/vendor — corré primero:" >&2
  echo "  composer install --no-dev --optimize-autoloader" >&2
  exit 1
}

if [[ $PUSH_ENV -eq 1 && ! -f "$PROD_ENV" ]]; then
  echo "Pediste --env pero no existe $PROD_ENV" >&2
  exit 1
fi

if [[ -n "$DRY" ]]; then
  echo "=== DRY-RUN — no se modifica nada. Usá --live para ejecutar. ==="
else
  echo "=== DEPLOY EN VIVO a ${FTP_HOST} ==="
fi
echo

# --- sincronización ----------------------------------------------------------

lftp <<LFTP 2>&1 | sed -E "$REDACT_SED"
set ftp:ssl-force true
set ftp:ssl-protect-data true
set ssl:verify-certificate yes
set net:max-retries 3
set net:timeout 20
set mirror:parallel-transfer-count 8
set cmd:fail-exit true

open -u '${FTP_USER}','${FTP_PASS}' '${FTP_HOST}'

# --- código fuente (fuera del docroot) ---
# --delete: el servidor queda como espejo exacto del repo.
# Los patrones excluidos NO se borran del servidor: protegen el .env y el cache.
# ".env.*" cubre .env.production y .env.example de una: ninguno de los dos tiene
# que viajar con este nombre (el productivo se sube aparte, con --env).
# --no-perms: sin esto, mirror manda un chmod por cada archivo/directorio cuyo
# permiso local difiera del remoto (ruido extra en el log). FTP ya sube en 0644
# en este hosting (ver README), así que no hace falta que mirror ande
# igualando permisos. La contraseña en el log de todas formas se filtra con
# $REDACT_SED más arriba, no dependas sólo de --no-perms para eso.
mirror --reverse --delete --no-perms ${DRY} --verbose \
  --exclude-glob .env \
  --exclude-glob .env.* \
  --exclude-glob .ftp.env \
  --exclude-glob .git* \
  --exclude 'var/cache/' \
  ./app ${REMOTE_APP}

# --- docroot ---
# Sin --delete: en public_html viven archivos que no están en el repo
# (.user.ini, error_log, cgi-bin) y no queremos tocarlos.
# .htaccess EXCLUIDO a propósito: el de producción tiene el bloque AddHandler
# de MultiPHP Manager (fuerza ea-php83) seguido de estas mismas reglas de
# Slim agregadas a mano. El .htaccess de este repo NO tiene ese bloque — si
# se sincronizara, cada deploy pisaría el .htaccess del servidor y el sitio
# caería a la 8.1 nativa (Composer con dependencias >=8.3 rompe en el acto,
# ver README). Si algún día cambian las reglas de rewrite de Slim, hay que
# aplicarlas a mano en public_html/.htaccess (después del bloque AddHandler).
mirror --reverse --no-perms ${DRY} --verbose \
  --exclude-glob .user.ini \
  --exclude-glob .htaccess \
  --exclude-glob .git* \
  --exclude-glob error_log \
  ./public ${REMOTE_PUB}
LFTP

# --- .env de producción (opcional) -------------------------------------------
# Se sube renombrado: en el servidor la app espera /app/.env, no .env.production.

if [[ $PUSH_ENV -eq 1 && -z "$DRY" ]]; then
  echo
  echo "--- subiendo $PROD_ENV -> ${REMOTE_APP}/.env ---"
  lftp <<LFTP 2>&1 | sed -E "$REDACT_SED"
set ftp:ssl-force true
set ftp:ssl-protect-data true
set ssl:verify-certificate yes
set cmd:fail-exit true
open -u '${FTP_USER}','${FTP_PASS}' '${FTP_HOST}'
put "${PROD_ENV}" -o ${REMOTE_APP}/.env
chmod 600 ${REMOTE_APP}/.env
LFTP
fi

# --- permisos defensivos ------------------------------------------------------
# FTP crea con 0644, así que normalmente esto no hace falta. Va igual porque un
# index.php con permiso de escritura de grupo hace que suEXEC le niegue la
# ejecución a PHP -> 500 de Apache sin ninguna línea en el log.

if [[ -z "$DRY" ]]; then
  echo
  echo "--- normalizando permisos ---"
  if ! lftp <<LFTP 2>&1 | sed -E "$REDACT_SED"
set ftp:ssl-force true
set ssl:verify-certificate yes
open -u '${FTP_USER}','${FTP_PASS}' '${FTP_HOST}'
chmod 644 ${REMOTE_PUB}/index.php
chmod 644 ${REMOTE_PUB}/.htaccess
LFTP
  then
    echo "(chmod falló — verificá a mano desde el administrador de archivos)"
  fi
fi

echo
if [[ -n "$DRY" ]]; then
  echo "Dry-run terminado. Revisá la lista de arriba — sobre todo las líneas de borrado."
else
  echo "Deploy terminado. Verificá https://estudiocandame.com.ar antes de cerrar."
fi
