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
APP_DIR="${SLIMCMS_APP_DIR:-/home/claudio/web/slimcms-app}"

log() { printf '\033[1m==>\033[0m %s\n' "$*"; }
errore() { printf '\033[31mERRORE:\033[0m %s\n' "$*" >&2; exit 1; }

[[ -d "$APP_DIR" ]] || errore "$APP_DIR non esiste. Crearla come root e assegnarla a claudio."
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

log "Verifica"
php artisan about --only=environment 2>/dev/null | head -6

echo
log "Fatto. Manca solo la config nginx (richiede root):"
echo "     scripts/nginx/manage.slimcms.it.conf.template"
