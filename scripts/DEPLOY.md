# Messa in produzione del backend

Il frontend è già online: `slimcms.it` serve file statici da
`/home/claudio/web/slimcms.it/public_html`.

**L'applicazione Laravel non è ancora deployata.** Gira solo in sviluppo con
`php -S`. Finché non lo è: niente pannello di redazione, niente control plane,
niente API — la build del sito funziona solo da questa macchina.

I passi sotto richiedono **root** e vanno eseguiti a mano.

## 1. DNS

Su Cloudflare, zona `slimcms.it`:

| Tipo | Nome | Contenuto | Proxy |
|---|---|---|---|
| `A` | `manage` | `49.13.157.237` | DNS only |
| `A` | `sites` | `49.13.157.237` | DNS only |

`sites.slimcms.it` è l'hostname a cui i clienti puntano il proprio dominio con
un CNAME. **Proxy disattivato**: con la nuvola arancione Cloudflare termina il
TLS per conto proprio e il certificato Let's Encrypt dell'origine non viene mai
usato dai visitatori.

## 2. Vhost del control plane

```bash
sudo /usr/local/hestia/bin/v-add-domain claudio manage.slimcms.it
sudo /usr/local/hestia/bin/v-add-letsencrypt-domain claudio manage.slimcms.it
```

Poi sostituire la configurazione generata con
`scripts/nginx/manage.slimcms.it.conf.template`, che punta la document root
all'applicazione invece che a una cartella statica.

## 3. Applicazione

```bash
sudo mkdir -p /home/claudio/web/slimcms-app
sudo chown claudio:claudio /home/claudio/web/slimcms-app
# come utente claudio:
rsync -a --exclude=node_modules --exclude=.env \
  /home/claudio/dev/slimcms/backend/ /home/claudio/web/slimcms-app/
cd /home/claudio/web/slimcms-app
cp .env .env.production   # e valorizzare: APP_ENV=production, APP_DEBUG=false,
                          # APP_URL=https://manage.slimcms.it,
                          # SLIMCMS_DOMINIO_MANAGE=manage.slimcms.it,
                          # credenziali del DB di produzione
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan storage:link
```

**Le credenziali di produzione non stanno nel repository** e non devono
finirci: il `.env` di produzione si scrive direttamente sul server.

## 4. Vhost dei siti dei clienti

Ogni sito usa `scripts/nginx/sito-cliente.conf.template`: statico per tutto,
tranne `/admin` e `/api` che vanno a PHP. Non c'è una copia dell'applicazione
per sito — è sempre la stessa, e `ResolveSiteFromDomain` capisce di quale sito
si tratta dall'`Host` della richiesta.

## 5. Verifica

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://manage.slimcms.it/login   # 200
curl -sS -o /dev/null -w '%{http_code}\n' https://slimcms.it/admin/login    # 200
php artisan slimcms:monitora-certificati                                    # tutto valido
```
