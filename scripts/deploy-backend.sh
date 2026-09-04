#!/usr/bin/env bash
#
# Deploy dell'applicazione Laravel in produzione.
#
# Idempotente: si puo' rilanciare a ogni rilascio. Non tocca il .env di
# produzione, che vive solo sul server e non passa mai dal repository.
#
# Uso:
#   scripts/deploy-backend.sh
#   scripts/deploy-backend.sh --no-migrate    salta le migrazioni

set -Eeuo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# L'applicazione vive nella cartella "private" del dominio, non in una cartella
# qualsiasi sotto web/. Non e' una preferenza: il pool PHP-FPM che Hestia crea
# per ogni dominio impone un open_basedir che consente solo
#   <dominio>/public_html, <dominio>/private, <dominio>/public_shtml,
#   ~/.composer, ~/tmp, /tmp e le directory di sistema.
# Con l'app altrove, PHP non riesce nemmeno a leggere vendor/autoload.php e la
# richiesta muore con "Operation not permitted". Da CLI invece funziona,
# perche' open_basedir e' impostato sul pool FPM e non sul php.ini della CLI:
# e' il motivo per cui il sintomo sembrava incoerente.
DOCROOT_DOMINIO="${SLIMCMS_DOCROOT_DOMINIO:-manage.slimcms.it}"
APP_DIR="${SLIMCMS_APP_DIR:-/home/claudio/web/$DOCROOT_DOMINIO/private/slimcms-app}"

log() { printf '\033[1m==>\033[0m %s\n' "$*"; }
errore() { printf '\033[31mERRORE:\033[0m %s\n' "$*" >&2; exit 1; }

mkdir -p "$APP_DIR" 2>/dev/null || errore "impossibile creare $APP_DIR"
[[ -w "$APP_DIR" ]] || errore "$APP_DIR non e' scrivibile: sudo chown claudio:claudio $APP_DIR"

log "Sincronizzazione del codice"
# --delete tiene pulita la destinazione, ma .env, storage e le cartelle
# generate non vanno MAI toccate: contengono lo stato di produzione.
rsync -a --delete \
  --exclude='.env' \
  --exclude='.env.*' \
  --exclude='storage/' \
  --exclude='node_modules/' \
  --exclude='vendor/' \
  --exclude='.git/' \
  --exclude='public/storage' \
  "$REPO_ROOT/backend/" "$APP_DIR/"

# storage esiste ma non viene sovrascritto: log, cache, sessioni e media
# caricati vivono li'.
mkdir -p "$APP_DIR"/storage/{app/public,framework/{cache/data,sessions,testing,views},logs}
mkdir -p "$APP_DIR/bootstrap/cache"
chmod -R ug+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

if [[ ! -f "$APP_DIR/.env" ]]; then
  log "Primo deploy: creo .env da .env.example"
  cp "$REPO_ROOT/backend/.env.example" "$APP_DIR/.env"
  echo
  printf '\033[33mATTENZIONE\033[0m: %s e\x27 da compilare prima di proseguire.\n' "$APP_DIR/.env"
  echo "  APP_ENV=production, APP_DEBUG=false"
  echo "  APP_URL=https://manage.slimcms.it"
  echo "  SLIMCMS_DOMINIO_MANAGE=manage.slimcms.it"
  echo "  credenziali del DB di produzione (claudio_slimcms_prod)"
  echo
  errore "compila il .env e rilancia."
fi

log "Dipendenze"
cd "$APP_DIR"
composer install --no-dev --optimize-autoloader --no-interaction

grep -q '^APP_KEY=base64:' .env || { log "Genero APP_KEY"; php artisan key:generate --force; }

if [[ "${1:-}" != "--no-migrate" ]]; then
  log "Migrazioni"
  php artisan migrate --force
fi

log "Cache di produzione"
php artisan config:cache
php artisan route:cache
php artisan view:cache

[[ -L public/storage ]] || php artisan storage:link

# --- Pubblicazione del front controller nel docroot del dominio -------------
#
# Hestia vuole che la document root di un dominio sia il suo public_html, e
# v-change-web-domain-docroot accetta come target solo un ALTRO DOMINIO
# registrato, non una cartella qualsiasi. Invece di combattere Hestia (o di
# usare un symlink, che qui e' rischioso perche' Apache non dichiara
# FollowSymLinks), pubblichiamo i file statici di Laravel dentro public_html e
# ci mettiamo un index.php che carica l'applicazione da APP_DIR.
#
# Cosi' non serve nessun permesso di root e niente sopravvive-ai-rebuild da
# ricordarsi: public_html e' contenuto utente, Hestia non lo tocca.
if [[ -n "$DOCROOT_DOMINIO" ]]; then
  DOCROOT="/home/claudio/web/$DOCROOT_DOMINIO/public_html"

  if [[ -d "$DOCROOT" ]]; then
    log "Pubblicazione del front controller in $DOCROOT"

    # Gli asset di Filament e simili: si copiano, non si linkano.
    rsync -a --exclude='index.php' --exclude='storage' \
      "$APP_DIR/public/" "$DOCROOT/"

    # index.php che punta all'applicazione fuori dal docroot. Il codice
    # dell'app resta cosi' NON servibile via web, che e' il motivo per cui
    # Laravel separa public/ dal resto.
    #
    # Heredoc QUOTATO ('PHP'): senza le virgolette bash interpreterebbe le
    # variabili PHP come proprie. Il percorso si inietta dopo, con sed.
    cat > "$DOCROOT/index.php" <<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// L'applicazione vive FUORI dal document root: qui c'e' solo il front
// controller. Generato da scripts/deploy-backend.sh, non modificare a mano.
$app_dir = '@@APP_DIR@@';

if (file_exists($maintenance = $app_dir.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $app_dir.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $app_dir.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
PHP

    sed -i "s|@@APP_DIR@@|$APP_DIR|" "$DOCROOT/index.php"

    # Il link a storage deve puntare allo storage dell'APP, non a uno locale.
    rm -rf "$DOCROOT/storage"
    ln -sfn "$APP_DIR/storage/app/public" "$DOCROOT/storage"

    # La pagina segnaposto di Hestia, se c'e' ancora, coprirebbe index.php.
    rm -f "$DOCROOT/index.html"

    echo "    front controller -> $APP_DIR"
  else
    printf '\033[33mATTENZIONE\033[0m: %s non esiste, front controller non pubblicato.\n' "$DOCROOT"
  fi
fi

log "Verifica"
php artisan about --only=environment 2>/dev/null | head -6

echo
log "Fatto."
