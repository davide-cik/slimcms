#!/usr/bin/env bash
#
# Importa le visite annotate dai siti e le aggrega per giorno.
#
# Il sito pubblico e' statico: una visita non tocca Laravel e non lascia
# traccia da nessuna parte. Ogni pagina cita pero' un piccolo contatore PHP
# servito dal dominio del sito, che annota indirizzo, user-agent e provenienza
# in una cartella privata. Questo script porta quelle righe nel database.
#
# NON si leggono i log di Apache: sono di root, la loro rotazione e' fuori dal
# nostro controllo, e un monitor che dipende da un file che qualcun altro
# ruota e' un monitor che un giorno smette di funzionare senza dirlo.
#
# Ogni cinque minuti: le statistiche non sono in tempo reale ma nemmeno di
# ieri, e il file intanto si accumula ed e' consumato per intero a ogni
# passata, quindi non si perde niente.
#
# Cron (ogni cinque minuti, sfasato per non accavallarsi con le altre passate):
#   */5 * * * * /home/claudio/dev/slimcms/scripts/importa-viste.sh >> /home/claudio/backups/slimcms/viste.log 2>&1

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
exec php artisan slimcms:importa-viste
