#!/usr/bin/env bash
#
# Verifica lo stato del deploy in produzione. Da lanciare dopo aver creato
# vhost e certificati, per sapere cosa manca ancora invece di indovinare.

set -uo pipefail

APP_DIR="${SLIMCMS_APP_DIR:-/home/claudio/web/manage.slimcms.it/private/slimcms-app}"

ok()   { printf '  \033[32mOK\033[0m    %s\n' "$*"; }
ko()   { printf '  \033[31mMANCA\033[0m %s\n' "$*"; }
info() { printf '\n\033[1m%s\033[0m\n' "$*"; }

controlla_dns() {
  local host="$1" atteso="49.13.157.237"
  local ip; ip=$(dig +short "$host" A 2>/dev/null | head -1)
  if [[ "$ip" == "$atteso" ]]; then ok "DNS $host -> $ip"
  elif [[ -z "$ip" ]]; then ko "DNS $host non risolve"
  else ko "DNS $host -> $ip (atteso $atteso: proxy Cloudflare attivo?)"; fi
}

controlla_tls() {
  local host="$1"

  # ATTENZIONE: `openssl x509 -checkend` verifica SOLO la scadenza, non che il
  # certificato sia emesso per questo dominio. Con un vhost mancante il server
  # presenta il certificato di un altro host, che e' perfettamente valido e
  # farebbe passare il controllo. Serve verificare anche il nome, altrimenti
  # il controllo dice OK proprio quando c'e' il problema.
  local cert
  cert=$(echo | timeout 8 openssl s_client -servername "$host" -connect "$host:443" 2>/dev/null)

  if [[ -z "$cert" ]]; then
    ko "TLS $host: nessuna risposta"
    return
  fi

  if ! echo "$cert" | openssl x509 -noout -checkend 0 >/dev/null 2>&1; then
    ko "TLS $host: certificato scaduto"
    return
  fi

  # -verify_hostname fallisce se il nome non e' nel CN o nei SAN.
  if ! echo | timeout 8 openssl s_client -servername "$host" -connect "$host:443" \
       -verify_hostname "$host" 2>/dev/null | grep -q "Verify return code: 0"; then
    local presentato
    presentato=$(echo "$cert" | openssl x509 -noout -subject 2>/dev/null | sed 's/.*CN *= *//')
    ko "TLS $host: il certificato presentato e per \"$presentato\", non per questo host"
    return
  fi

  local scad
  scad=$(echo "$cert" | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)
  ok "TLS $host valido (fino al $scad)"
}

controlla_http() {
  local url="$1" atteso="$2"
  local codice; codice=$(curl -sS -m 15 -o /dev/null -w '%{http_code}' "$url" 2>/dev/null || echo 000)
  if [[ "$codice" == "$atteso" ]]; then ok "$url -> $codice"
  else ko "$url -> $codice (atteso $atteso)"; fi
}

info "DNS"
controlla_dns manage.slimcms.it
controlla_dns sites.slimcms.it
controlla_dns slimcms.it

info "Certificati"
controlla_tls slimcms.it
controlla_tls manage.slimcms.it

info "Applicazione"
if [[ -d "$APP_DIR" ]]; then ok "applicazione presente in $APP_DIR"
else ko "applicazione non deployata ($APP_DIR non esiste)"; fi

controlla_http https://slimcms.it/            200
controlla_http https://manage.slimcms.it/login 200
controlla_http https://slimcms.it/admin/login  200

info "Fine. Le voci MANCA sono i passi ancora da fare (vedi scripts/DEPLOY.md)."
