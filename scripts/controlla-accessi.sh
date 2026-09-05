#!/usr/bin/env bash
#
# Avvisa sui tentativi di accesso ripetuti ai pannelli e pota il registro.
#
# Il registro lo scrive un listener sugli eventi di autenticazione di Laravel,
# quindi copre qualunque percorso porti a un accesso. Questo script fa la
# domanda che rende utile il registro: qualcuno sta insistendo?
#
# Ogni quindici minuti: piu' spesso non aggiunge niente — la soglia guarda
# un'ora di tentativi — e un avviso per chiave non si ripete comunque prima di
# sei ore.
#
# Cron:
#   */15 * * * * /home/claudio/dev/slimcms/scripts/controlla-accessi.sh >> /home/claudio/backups/slimcms/accessi.log 2>&1

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
exec php artisan slimcms:controlla-accessi
