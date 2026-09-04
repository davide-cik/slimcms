#!/usr/bin/env bash
#
# Importa i 404 annotati dai siti e li aggrega nel pannello.
#
# Il sito pubblico e' statico e un 404 non tocca Laravel. La pagina d'errore e'
# pero' un piccolo file PHP che, prima di stampare la pagina, annota
# l'indirizzo richiesto in una cartella privata del sito. Questo script porta
# quelle righe nel database.
#
# Ogni ora e non ogni minuto: un collegamento rotto resta rotto, e importarlo
# sessanta volte piu' spesso non lo aggiusta prima. Il file intanto si accumula
# ed e' consumato per intero a ogni passata, quindi non si perde niente.
#
# Cron (ogni ora, al minuto 20 per non accavallarsi con le altre passate):
#   20 * * * * /home/claudio/dev/slimcms/scripts/importa-404.sh >> /home/claudio/backups/slimcms/404.log 2>&1

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# La produzione se c'e', altrimenti sviluppo: i siti veri sono in produzione, e
# importare nel database sbagliato vorrebbe dire non vedere mai niente.
APP_PROD="/home/claudio/web/manage.slimcms.it/private/slimcms-app"
if [[ -d "$APP_PROD" ]]; then
  APP="$APP_PROD"
else
  APP="$REPO_ROOT/backend"
fi

cd "$APP"

echo "== $(date '+%F %T') =="
exec php artisan slimcms:importa-404
