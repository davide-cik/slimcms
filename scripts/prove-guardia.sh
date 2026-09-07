#!/usr/bin/env bash
# Prove della guardia. Sta fuori dal repo e costruisce i nomi a pezzi, così
# il file di prova non fa scattare la guardia su se stesso.
G=/home/claudio/dev/slimcms/scripts/guardia-deploy.sh
F="deploy-front""end.sh"
B="deploy-back""end.sh"

prova() {
  local etichetta="$1" comando="$2"
  local esito
  esito=$(jq -nc --arg c "$comando" '{tool_input:{command:$c}}' | "$G" \
    | jq -r '.hookSpecificOutput.permissionDecision // "consentito"')
  printf '  %-52s %s\n' "$etichetta" "$esito"
}

echo "── devono essere BLOCCATI:"
prova "percorso relativo + pipe"      "./scripts/$F | grep Fatto"
prova "percorso relativo"             "./scripts/$B"
prova "scripts/ senza ./"             "scripts/$F --dry-run"
prova "percorso assoluto ma in pipe"  "/home/claudio/dev/slimcms/scripts/$F | head"

echo "── devono PASSARE:"
prova "percorso assoluto, niente pipe" "/home/claudio/dev/slimcms/scripts/$B"
prova "il comando giusto"              "slimcms deploy"
prova "il comando giusto, in pipe"     "slimcms deploy-frontend | tail -3"
prova "un commit che NOMINA lo script" "$(printf 'git commit -F - <<MSG\nho corretto ./scripts/%s in pipe\nMSG\ngit push' "$F")"
prova "comando qualunque"              "git status | grep x"
