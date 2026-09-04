#!/usr/bin/env bash
# Backup notturno dei DB SlimCMS (MariaDB su 10.0.0.3).
# Dump compresso gz, naming slimcms_<db>_YYYY-MM-DD.sql.gz, in /home/claudio/backups/slimcms.
#
# Le credenziali NON stanno qui: vivono in ~/.slimcms/<ambiente>.cnf (chmod 600),
# fuori dal repository. A differenza di zuzai non si leggono dal .env perche'
# il .env di produzione di SlimCMS non esiste su questa macchina, e le
# credenziali prod non devono finire nel repo.
#
# Rotation: 30 daily + 4 weekly + 2 monthly (vedi backup-rotate.py), applicata
# separatamente per ciascun database.
#
# Cron consigliato:
#   30 3 * * *  /home/claudio/dev/slimcms/scripts/backup-db.sh >> /home/claudio/backups/slimcms/backup.log 2>&1
#
# Uso:
#   scripts/backup-db.sh          tutti gli ambienti
#   scripts/backup-db.sh dev      solo dev

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${SLIMCMS_BACKUP_DIR:-/home/claudio/backups/slimcms}"
CONF_DIR="${SLIMCMS_CONF_DIR:-$HOME/.slimcms}"

# ambiente:database
AMBIENTI=(
  "prod:claudio_slimcms_prod"
  "dev:claudio_slimcms"
)

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

filtro="${1:-}"
falliti=0
riusciti=0

for voce in "${AMBIENTI[@]}"; do
  ambiente="${voce%%:*}"
  database="${voce##*:}"

  [[ -n "$filtro" && "$filtro" != "$ambiente" ]] && continue

  conf="$CONF_DIR/$ambiente.cnf"

  if [[ ! -r "$conf" ]]; then
    echo "[$(date -Is)] ERROR [$ambiente]: manca $conf" >&2
    falliti=$((falliti + 1))
    continue
  fi

  DUMP="$BACKUP_DIR/slimcms_${database}_$(date +%Y-%m-%d).sql.gz"
  echo "[$(date -Is)] starting dump [$ambiente] -> $DUMP"

  # Si scrive su .tmp e si rinomina solo a fine riuscita: un dump interrotto
  # non deve mai essere scambiato per uno valido dalla rotazione.
  if ! mysqldump \
        --defaults-file="$conf" \
        --single-transaction --quick --routines --triggers --events \
        --default-character-set=utf8mb4 \
        --skip-lock-tables --no-tablespaces \
        "$database" 2>/dev/null | gzip -9 > "${DUMP}.tmp"; then
    echo "[$(date -Is)] ERROR [$ambiente]: mysqldump fallito" >&2
    rm -f "${DUMP}.tmp"
    falliti=$((falliti + 1))
    continue
  fi

  if [[ ! -s "${DUMP}.tmp" ]]; then
    echo "[$(date -Is)] ERROR [$ambiente]: dump vuoto" >&2
    rm -f "${DUMP}.tmp"
    falliti=$((falliti + 1))
    continue
  fi

  # Un gz troncato non si dichiara tale: va verificato esplicitamente.
  if ! gzip -t "${DUMP}.tmp" 2>/dev/null; then
    echo "[$(date -Is)] ERROR [$ambiente]: archivio corrotto" >&2
    rm -f "${DUMP}.tmp"
    falliti=$((falliti + 1))
    continue
  fi

  mv "${DUMP}.tmp" "$DUMP"
  chmod 600 "$DUMP"
  echo "[$(date -Is)] dump OK [$ambiente] ($(du -h "$DUMP" | cut -f1))"
  riusciti=$((riusciti + 1))
done

# La rotazione gira solo se almeno un dump e' riuscito: se i backup falliscono,
# i vecchi restano invece di essere cancellati mentre non ne arrivano di nuovi.
if (( riusciti > 0 )); then
  python3 "$REPO_ROOT/scripts/backup-rotate.py" "$BACKUP_DIR"
else
  echo "[$(date -Is)] rotazione saltata: nessun dump riuscito" >&2
fi

echo "[$(date -Is)] riepilogo: $riusciti riusciti, $falliti falliti"
(( falliti > 0 )) && exit 1
exit 0
