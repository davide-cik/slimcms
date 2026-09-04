#!/usr/bin/env bash
#
# Esegue le build in attesa. Pensato per cron ogni minuto.
#
# PERCHE' CRON E NON UN WORKER: php.ini su questa macchina disabilita pcntl_*,
# quindi `queue:work` in modalita' daemon e Horizon non partono affatto
# ("Call to undefined function pcntl_signal"). Il comando artisan e'
# idempotente e si limita a quello che trova, quindi girare ogni minuto e'
# sicuro anche se una passata si sovrappone: prendiProssima() marca le
# richieste dentro una transazione con lockForUpdate.
#
# Cron:
#   * * * * * /home/claudio/dev/slimcms/scripts/build-queue-run.sh >> /home/claudio/backups/slimcms/build-queue.log 2>&1

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOCK="/tmp/slimcms-build-queue.lock"

# flock evita che due passate lente si accavallino: se la precedente sta
# ancora costruendo, questa esce subito invece di accodarsi.
exec 9>"$LOCK"
if ! flock -n 9; then
  exit 0
fi

cd "$REPO_ROOT/backend"
exec php artisan slimcms:build-queue --max=3
