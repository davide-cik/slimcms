#!/usr/bin/env bash
#
# Build e pubblicazione del frontend Astro su slimcms.it.
#
# PERCHE' ESISTE: il 2026-09-04 ho pubblicato una build vuota e messo il sito
# offline. Astro si era comportato correttamente (exit 1, nessun index.html);
# l'errore era nella procedura: la build era dentro una pipe con grep, che
# maschera l'exit code, e il deploy girava in un comando separato senza
# controllare l'output. Qui build, verifica e deploy sono un blocco solo, e
# rsync --delete non parte se anche una sola verifica fallisce.
#
# Uso:
#   scripts/deploy-frontend.sh              build + verifica + pubblica
#   scripts/deploy-frontend.sh --dry-run    build + verifica, senza pubblicare

set -Eeuo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND="$REPO_ROOT/frontend"
DOCROOT="${SLIMCMS_DOCROOT:-/home/claudio/web/slimcms.it/public_html}"
SITO="${SLIMCMS_SITE_URL:-https://slimcms.it}"

# Frammenti che DEVONO comparire nell'HTML pubblicato. Se il database e' vuoto
# o l'API risponde male, la build puo' produrre una pagina scheletro che pesa
# poco e sembra valida: questi controlli la smascherano.
ATTESI=(
  "Venti siti, un pannello"
  "P.IVA IT11732600967"
  "application/ld+json"
)

DIMENSIONE_MINIMA=5000

log() { printf '\033[1m==>\033[0m %s\n' "$*"; }
errore() { printf '\033[31mERRORE:\033[0m %s\n' "$*" >&2; exit 1; }

dry_run=false
[[ "${1:-}" == "--dry-run" ]] && dry_run=true

cd "$FRONTEND"

# --- Node: risolverlo esplicitamente, non fidarsi del PATH ------------------
#
# Il cron gira con un PATH minimale e trova /usr/bin/node, che su questa
# macchina e' la v12: Astro 7 ne richiede almeno la 22.12 e muore con
# "SyntaxError: Unexpected token '.'", cioe' l'optional chaining che quel Node
# non sa leggere. E' un errore che non dice nulla su cosa sia davvero
# successo, e ha gia' fatto fallire tre volte le build accodate dal pannello
# mentre la stessa build lanciata a mano riusciva, perche' la shell
# interattiva ha nvm nel PATH.
MINIMO_NODE=22

trova_node() {
  # 1. la versione di default di nvm, se installata
  local nvm_default="$HOME/.nvm/alias/default"
  if [[ -r "$nvm_default" ]]; then
    local ver; ver=$(<"$nvm_default")
    for candidato in "$HOME/.nvm/versions/node/$ver/bin" "$HOME/.nvm/versions/node/v$ver"*/bin; do
      [[ -x "$candidato/node" ]] && { echo "$candidato"; return; }
    done
  fi

  # 2. la piu' recente fra quelle installate da nvm
  local ultima
  ultima=$(ls -d "$HOME"/.nvm/versions/node/v*/bin 2>/dev/null | sort -V | tail -1)
  [[ -n "$ultima" && -x "$ultima/node" ]] && { echo "$ultima"; return; }

  # 3. quello che c'e' nel PATH
  local dal_path; dal_path=$(command -v node 2>/dev/null)
  [[ -n "$dal_path" ]] && dirname "$dal_path"
}

NODE_BIN="$(trova_node)"
[[ -n "$NODE_BIN" ]] || errore "Node non trovato. Astro non puo' girare."
export PATH="$NODE_BIN:$PATH"

versione_node=$(node -v 2>/dev/null)
maggiore=${versione_node#v}; maggiore=${maggiore%%.*}

if [[ -z "$maggiore" ]] || (( maggiore < MINIMO_NODE )); then
  errore "Node ${versione_node:-non rilevato} da $NODE_BIN, ma Astro ne richiede almeno la $MINIMO_NODE.
         Senza questo controllo la build fallirebbe con un oscuro
         \"SyntaxError: Unexpected token '.'\" che non spiega la causa."
fi

echo "    node $versione_node ($NODE_BIN)"

log "Build"
# NIENTE pipe qui: una pipe maschera l'exit code ed e' esattamente l'errore
# che ha causato l'incidente.
if ! npm run build; then
  errore "la build e' fallita. Niente viene pubblicato."
fi

log "Verifica dell'output"

[[ -s dist/index.html ]] || errore "dist/index.html assente o vuoto."

dimensione=$(wc -c < dist/index.html)
if (( dimensione < DIMENSIONE_MINIMA )); then
  errore "dist/index.html e' solo $dimensione byte (minimo $DIMENSIONE_MINIMA): build probabilmente incompleta."
fi
echo "    index.html: $dimensione byte"

for atteso in "${ATTESI[@]}"; do
  grep -q "$atteso" dist/index.html || errore "manca dall'HTML: \"$atteso\". Il database e' popolato?"
  echo "    trovato: \"$atteso\""
done

# Una classe senza regole CSS non si vede da peso e contenuto: l'HTML resta
# valido e i controlli sopra passano tutti. E' successo davvero.
python3 "$REPO_ROOT/scripts/verifica-css.py" dist \
  || errore "ci sono classi senza stile: la pagina verrebbe pubblicata rotta."

# I font sono ospitati in proprio di proposito: caricarli da un terzo dominio
# rimette una catena di tre richieste davanti al primo rendering, e su queste
# pagine l'elemento LCP e' testo. E' anche una questione di privacy: servire
# Google Fonts dal CDN invia l'IP dei visitatori a Google, cosa contestata in
# UE. Se un riferimento esterno torna, e' una regressione.
if grep -rqE 'fonts\.(googleapis|gstatic)\.com' dist/*.html dist/**/*.html 2>/dev/null; then
  errore "l'HTML referenzia Google Fonts: i font devono essere di prima parte."
fi

# I riferimenti si raccolgono da TUTTI i fogli in una volta sola.
# Ciclare foglio per foglio si rompeva appena Astro ne emetteva un secondo
# senza font dentro: `grep` usciva 1, `pipefail` faceva uscire lo script, e il
# deploy si fermava senza stampare un motivo. Un gate che fallisce muto e'
# peggio di nessun gate, perche' si finisce per non fidarsene.
riferimenti=$(grep -ohE 'url\(/fonts/[a-z0-9-]+\.woff2\)' dist/_astro/*.css 2>/dev/null \
              | tr -d '()' | sed 's|url/|/|' | sort -u || true)

# E se non ne trovasse nessuno il controllo passerebbe in silenzio, che e'
# esattamente il caso da intercettare: i font sono di prima parte di proposito.
[[ -n "$riferimenti" ]] || errore "il CSS non referenzia nessun font di prima parte."

while read -r rel; do
  [[ -s "dist${rel}" ]] || errore "il CSS referenzia ${rel}, che non esiste in dist/."
done <<< "$riferimenti"

echo "    font: di prima parte, $(wc -l <<< "$riferimenti") referenziati e presenti"

# Ogni immagine citata dall'HTML deve esistere in dist/. Le foto dei
# contenuti si scaricano dal backend in fase di build e si depositano qui: se
# un download fallisse, la pagina resterebbe valida e con la sua brava
# dimensione, solo con dei riquadri rotti al posto delle immagini.
mancanti=0
while read -r rif; do
  [[ -s "dist${rif}" ]] || { echo "    immagine mancante: ${rif}"; mancanti=$((mancanti + 1)); }
done < <(grep -rhoE 'src="/(media|[a-z0-9_-]+\.(svg|png|jpg|webp))[^"]*"' dist --include='*.html' \
         | sed -E 's/^src="//; s/"$//' | sort -u)

(( mancanti == 0 )) || errore "$mancanti immagini referenziate non esistono in dist/."
# Le immagini Open Graph non compaiono come src= nell'HTML, quindi il
# controllo qui sopra non le vede: un'anteprima social rotta passerebbe con
# il deploy verde, e ce ne si accorgerebbe solo condividendo un link.
og_mancanti=0
while read -r assoluta; do
  rel="${assoluta#https://*/}"
  [[ -s "dist/${rel}" ]] || { echo "    og:image mancante: /${rel}"; og_mancanti=$((og_mancanti + 1)); }
done < <(grep -rhoE '<meta property="og:image" content="[^"]+"' dist --include='*.html' \
         | sed -E 's/.*content="//; s/"$//' | sort -u)

(( og_mancanti == 0 )) || errore "$og_mancanti immagini Open Graph non esistono in dist/."
echo "    open graph: tutte le anteprime presenti"

echo "    immagini: tutte presenti"

# La ricerca gira nel browser su questo file: se manca, il campo di ricerca
# c'e' e non trova mai niente — un guasto che si vede solo provando a cercare.
[[ -s dist/ricerca-indice.json ]] || errore "dist/ricerca-indice.json assente: la ricerca del sito non funzionerebbe."
grep -q '"voci"' dist/ricerca-indice.json \
  || errore "dist/ricerca-indice.json senza voci: risposta incompleta dall'API."
[[ -s dist/cerca/index.html ]] || errore "dist/cerca/index.html assente."
echo "    ricerca: indice con $(python3 -c "import json;print(len(json.load(open('dist/ricerca-indice.json'))['voci']))") voci"

# Il modulo di contatto porta l'indirizzo dell'API in un attributo: se fosse
# vuoto o relativo, ogni messaggio scritto da un visitatore andrebbe perso in
# silenzio, che e' il modo peggiore di rompersi.
if grep -rql 'class="modulo"' dist --include='*.html'; then
  while read -r endpoint; do
    [[ "$endpoint" =~ ^https://.+/public/.+/contact$ ]] \
      || errore "modulo di contatto con endpoint non valido: '$endpoint'"
  done < <(grep -rhoE 'data-endpoint="[^"]*"' dist --include='*.html' | sed -E 's/data-endpoint="//; s/"$//' | sort -u)
  echo "    modulo di contatto: endpoint verificato"
fi

# /favicon.ico non compare in nessun href dell'HTML per volere del browser:
# lo chiede da solo alla radice del dominio, e con lui ogni crawler. Se manca
# il sito risponde 404 a ogni visita, il che e' esattamente il primo 404 che
# il nostro monitoraggio ha registrato su slimcms.it.
[[ -s dist/favicon.ico ]] || errore "dist/favicon.ico assente: ogni visita produrrebbe un 404."
# Un ICO vero comincia con 00 00 01 00. Un file della giusta dimensione ma
# con dentro una pagina di errore JSON dell'API passerebbe il controllo sopra.
[[ "$(head -c 4 dist/favicon.ico | od -An -tx1 | tr -d ' ')" == "00000100" ]] \
  || errore "dist/favicon.ico non e' un file ICO: l'API ha risposto qualcos'altro."
echo "    favicon: /favicon.ico presente e valida$([[ -s dist/favicon.svg ]] && echo ', con la variante SVG')"

# Il .htaccess porta i reindirizzamenti e la pagina d'errore del sito. Se e'
# malformato Apache risponde 500 su TUTTO il sito, non solo sugli indirizzi
# reindirizzati: e' il file piu' pericoloso che pubblichiamo.
[[ -s dist/.htaccess ]] || errore "dist/.htaccess assente: l'integrazione non ha girato."
grep -q '^ErrorDocument 404 /slimcms-404.php$' dist/.htaccess \
  || errore ".htaccess senza ErrorDocument: i 404 mostrerebbero la pagina dell'hosting."
[[ -s dist/404.html ]] || errore "dist/404.html assente, ma il gestore d'errore lo stampa."
# Il gestore e' anche il monitoraggio: senza, i collegamenti rotti non si
# saprebbero mai, perche' un 404 su un sito statico non lascia altra traccia.
[[ -s dist/slimcms-404.php ]] || errore "dist/slimcms-404.php assente, ma il .htaccess ci punta."
grep -q 'slimcms-404.jsonl' dist/slimcms-404.php \
  || errore "il gestore d'errore non annota niente: il monitoraggio dei 404 sarebbe cieco."
# Le direttive ammesse sono queste e basta: qualunque altra cosa in quel file
# arriva da un percorso scritto da chi redige, e non deve poterci finire.
if grep -vE '^\s*(#|$|ErrorDocument |RewriteEngine |RewriteCond |RewriteRule |</?IfModule)' dist/.htaccess; then
  errore "il .htaccess contiene direttive impreviste (righe sopra)."
fi
echo "    .htaccess: $(grep -c '^RewriteRule' dist/.htaccess) reindirizzamenti, pagina 404 del sito"

[[ -s dist/sitemap.xml ]] || errore "dist/sitemap.xml assente o vuoto."
grep -q "<loc>" dist/sitemap.xml || errore "sitemap.xml senza nessuna URL."
echo "    sitemap.xml: $(grep -c '<loc>' dist/sitemap.xml) URL"

# robots.txt: quello autogenerato da HestiaCP conterrebbe Crawl-delay, che su
# un prodotto che vende visibilita' sui motori e' controproducente.
cat > dist/robots.txt <<ROBOTS
User-agent: *
Allow: /

Sitemap: $SITO/sitemap.xml
ROBOTS

if $dry_run; then
  log "--dry-run: verifica superata, non pubblico."
  exit 0
fi

[[ -d "$DOCROOT" ]] || errore "document root inesistente: $DOCROOT"

log "Pubblicazione su $DOCROOT"
rsync -a --delete dist/ "$DOCROOT/"
find "$DOCROOT" -type d -exec chmod 755 {} \;
find "$DOCROOT" -type f -exec chmod 644 {} \;

log "Verifica del sito pubblicato"
codice=$(curl -sS -m 20 -o /dev/null -w '%{http_code}' "$SITO/" || echo 000)
[[ "$codice" == "200" ]] || errore "$SITO/ risponde $codice dopo la pubblicazione."

curl -sS -m 20 "$SITO/" | grep -q "${ATTESI[0]}" \
  || errore "il sito pubblicato non contiene \"${ATTESI[0]}\"."

echo "    $SITO/ -> HTTP 200, contenuto verificato"

# Ogni URL della sitemap deve rispondere 200 SENZA redirect: una sitemap che
# elenca URL che redirigono fa pagare un salto in piu' a ogni crawl, ed e' il
# tipo di difetto che non si vede guardando il sito.
while read -r loc; do
  codice=$(curl -sS -m 15 -o /dev/null -w '%{http_code}' "$loc" || echo 000)
  if [[ "$codice" != "200" ]]; then
    errore "la sitemap elenca $loc che risponde $codice invece di 200."
  fi
  echo "    sitemap: $loc -> 200"
done < <(grep -oP '(?<=<loc>)[^<]+' dist/sitemap.xml)

log "Fatto."
