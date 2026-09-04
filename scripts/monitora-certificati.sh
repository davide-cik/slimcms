#!/usr/bin/env bash
#
# Controllo giornaliero di DNS e certificati di tutti i siti.
#
# Le specifiche (sezione 11) chiedono un alert se un certificato non si
# rinnova: il rinnovo lo fa HestiaCP col suo cron di Let's Encrypt, questo
# verifica che l'abbia FATTO. E' la differenza fra avere il rinnovo automatico
# e sapere che sta funzionando.
#
# Cron (una volta al giorno, alle 6:15):
#   15 6 * * * /home/claudio/dev/slimcms/scripts/monitora-certificati.sh >> /home/claudio/backups/slimcms/certificati.log 2>&1

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT/backend"

# Il comando esce con codice diverso da zero se trova problemi: lo lasciamo
# propagare, cosi' il cron lo segnala anche a chi non legge il log.
exec php artisan slimcms:monitora-certificati --silenzioso
