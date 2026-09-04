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
log "Fatto."
