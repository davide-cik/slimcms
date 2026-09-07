#!/usr/bin/env bash
#
# Guardia sui comandi di pubblicazione, per un hook PreToolUse.
#
# Nasce da un guasto vero, due volte lo stesso giorno: lo script di
# pubblicazione lanciato per percorso relativo da una cartella dove quel
# percorso non esiste, con l'output dentro una `grep`. La shell ha scritto
# "No such file or directory" su stderr, la grep se l'e' mangiato, e il
# comando e' sembrato riuscito. Il sito e' rimasto fermo piu' del necessario.
#
# Sono due cause indipendenti e le blocca entrambe:
#   1. percorso relativo — dipende dalla cartella corrente
#   2. output in pipe — nasconde errore ed exit code
#
# La seconda e' la stessa che il 2026-09-04 ha mandato offline il sito
# (CLAUDE.md, "Pubblicazione del frontend"): una build dentro una pipe con
# `grep`, l'exit code mascherato, e un rsync --delete partito lo stesso.
#
# Legge il JSON dell'hook su stdin e risponde in JSON.

set -Eeuo pipefail

# I nomi non si scrivono per intero in questo file: la guardia ispeziona
# comandi, e un comando che la modifica o la prova conterrebbe il proprio
# innesco. Con i nomi spezzati il file resta modificabile dalla shell.
FRONTE="deploy-front""end.sh"
RETRO="deploy-back""end.sh"

# I corpi degli heredoc si tolgono prima di guardare. Un messaggio di commit
# che NOMINA lo script non e' un'invocazione: e' il primo falso positivo che
# questa guardia ha prodotto, bloccando il commit che la introduceva.
comando="$(jq -r '.tool_input.command // ""' | awk '
  !dentro && match($0, /<<-?['"'"'"]?[A-Za-z_][A-Za-z0-9_]*['"'"'"]?/) {
    delim = substr($0, RSTART, RLENGTH)
    sub(/^<<-?/, "", delim)
    gsub(/['"'"'"]/, "", delim)
    dentro = 1
    print
    next
  }
  dentro {
    riga = $0
    sub(/^[ \t]+/, "", riga)
    sub(/[ \t]+$/, "", riga)
    if (riga == delim) dentro = 0
    next
  }
  { print }
')"

nega() {
  jq -nc --arg motivo "$1" '{
    hookSpecificOutput: {
      hookEventName: "PreToolUse",
      permissionDecision: "deny",
      permissionDecisionReason: $motivo
    }
  }'
  exit 0
}

# Riguarda solo gli script di pubblicazione chiamati per nome. Il comando
# `slimcms deploy-frontend` non li nomina, quindi passa: e' il modo giusto.
if [[ "$comando" != *"$FRONTE"* && "$comando" != *"$RETRO"* ]]; then
  exit 0
fi

if [[ "$comando" == *"|"* ]]; then
  nega "Il deploy non va messo in pipe: la pipe nasconde errore ed exit code, ed e' cosi' che il 2026-09-04 il sito e' andato offline e il 2026-09-07 e' rimasto sulla pagina di cortesia. Usa \`slimcms deploy-frontend\` (o deploy-backend) e leggi l'output intero."
fi

if [[ "$comando" =~ (^|[^/[:alnum:]_.])(\./)?scripts/deploy ]]; then
  nega "Percorso relativo: dalla cartella sbagliata il comando non parte e non te ne accorgi. Usa \`slimcms deploy-frontend\` (o deploy-backend), che risolve il repository da se' e funziona da ovunque."
fi

exit 0
