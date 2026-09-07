#!/usr/bin/env bash
#
# DA LANCIARE COME ROOT, una volta sola.
#
#   sudo /home/claudio/dev/slimcms/scripts/root-abilita-proxy.sh
#
# Abilita mod_proxy_http in Apache. Serve per far servire /api/* dal dominio
# di ogni sito cliente invece che da manage.slimcms.it: una volta che il
# modulo c'e', la regola di proxy la scrive `GeneratoreHtaccess` nell'.htaccess
# che gia' depositiamo a ogni build, quindi ogni sito nuovo e' coperto senza
# altro lavoro da root.
#
# Cosa cambia dopo: la chiamata del visitatore diventa same-origin, e
# spariscono `config/cors.php` e il dominio dentro la URL — due pezzi che
# esistono solo per aggirare l'assenza di questo modulo.
#
# Abilitare il modulo NON attiva nessun proxy da solo: rende disponibile la
# direttiva. `ProxyRequests` resta Off (Apache non diventa un proxy aperto):
# lo script lo verifica prima e si ferma se non e' cosi'.
#
# Idempotente: se il modulo c'e' gia', non fa niente.

set -Eeuo pipefail

blu()    { printf '\033[1m==>\033[0m %s\n' "$*"; }
ok()     { printf '    \033[32mok\033[0m  %s\n' "$*"; }
errore() { printf '\033[31mERRORE:\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || errore "va lanciato come root: sudo $0"
command -v a2enmod  >/dev/null || errore "a2enmod non trovato: non sembra un Apache Debian/Ubuntu."
command -v apache2ctl >/dev/null || errore "apache2ctl non trovato."

blu "Prima di toccare niente"

if apache2ctl -M 2>/dev/null | grep -q "proxy_http_module"; then
    ok "mod_proxy_http e' gia' abilitato: non c'e' niente da fare."
    exit 0
fi
ok "mod_proxy_http non e' abilitato (atteso)"

# Un Apache con ProxyRequests On e' un proxy aperto: chiunque potrebbe usarlo
# per raggiungere altri host. Il default e' Off e qui deve restare Off.
if grep -rhiE '^[[:space:]]*ProxyRequests[[:space:]]+On' /etc/apache2/ 2>/dev/null | grep -q .; then
    errore "Da qualche parte c'e' 'ProxyRequests On': non abilito niente finche' non e' Off."
fi
ok "ProxyRequests non e' attivo da nessuna parte"

# Uno stato di partenza sano: se Apache e' gia' rotto, non voglio che sembri
# colpa di questo script.
apache2ctl configtest 2>&1 | grep -q "Syntax OK" \
    || errore "la configurazione di Apache NON e' valida gia' adesso: fermati e guarda 'apache2ctl configtest'."
ok "la configurazione di Apache e' valida ora"

blu "Abilito mod_proxy_http"
a2enmod proxy_http

blu "Ricontrollo la configurazione PRIMA di ricaricare"
if ! apache2ctl configtest 2>&1 | grep -q "Syntax OK"; then
    printf '\033[31mLa configurazione non e\140 valida dopo a2enmod: torno indietro.\033[0m\n' >&2
    a2dismod proxy_http || true
    errore "annullato, Apache non e' stato ricaricato."
fi
ok "configurazione valida"

# reload e non restart: le connessioni in corso non cadono.
blu "Ricarico Apache (reload, non restart)"
systemctl reload apache2
ok "ricaricato"

blu "Verifica"
apache2ctl -M 2>/dev/null | grep -q "proxy_http_module" \
    || errore "il modulo risulta ancora assente dopo il reload."
ok "mod_proxy_http attivo"

for u in https://slimcms.it/ https://manage.slimcms.it/admin/login; do
    codice=$(curl -s -o /dev/null -m 15 -w '%{http_code}' "$u" || echo 000)
    [[ "$codice" == "200" ]] || errore "$u risponde $codice dopo il reload."
    ok "$u risponde 200"
done

blu "Fatto. Il modulo c'e' e i siti rispondono."
echo
echo "Il resto (regola di proxy nell'.htaccess generato, via CORS e dominio"
echo "nella URL) lo fa Claude dal codice: nessun altro comando da root."
