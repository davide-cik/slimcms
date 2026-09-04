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
# L'IP va SEMPRE passato esplicitamente.
sudo /usr/local/hestia/bin/v-add-domain claudio manage.slimcms.it 49.13.157.237
sudo /usr/local/hestia/bin/v-add-letsencrypt-domain claudio manage.slimcms.it
```

> **Perché l'IP esplicito.** Senza, Hestia usa l'IP di default dell'utente, che
> su questo server è un indirizzo **interno** (`10.0.0.2`). Il vhost nasce in
> ascolto lì, le richieste da internet non lo incontrano mai e cadono sul vhost
> di default. Il sintomo è fuorviante: Let's Encrypt fallisce con un 404 sul
> percorso di validazione e sembra un problema di ACME, mentre è di routing.
> Se capita, si corregge senza ricreare il dominio:
>
> ```bash
> sudo /usr/local/hestia/bin/v-change-web-domain-ip claudio <dominio> 49.13.157.237 yes
> ```

Poi puntare la document root all'applicazione. **Non modificare a mano i file
in `/home/claudio/conf/web/`**: Hestia li rigenera e le modifiche si perdono.
C'è un comando nativo:

```bash
sudo /usr/local/hestia/bin/v-change-web-domain-docroot \
  claudio manage.slimcms.it slimcms-app public
```

Questo imposta la docroot a `/home/claudio/web/slimcms-app/public` e sopravvive
ai rebuild di Hestia.

> **Nota sull'architettura di Hestia su questo server:** nginx sta *davanti ad
> Apache* e gli inoltra tutto ciò che non è un file statico (`proxy_pass` verso
> `:8443`). PHP lo serve Apache, non PHP-FPM direttamente. `AllowOverride All`
> è già attivo, quindi il `.htaccess` di Laravel funziona senza altre
> modifiche.

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

**Questo pezzo non è ancora risolto e va deciso prima del primo cliente.**

Un sito cliente deve servire due cose sullo stesso dominio: i file statici
generati da Astro, e `/admin` più `/api` che devono arrivare a Laravel. Con
nginx+PHP-FPM sarebbe una `location`, e `scripts/nginx/sito-cliente.conf.template`
mostra quella forma. Ma **su questo server Hestia usa nginx davanti ad Apache**,
e la docroot di un dominio è una sola: o la cartella statica, o l'applicazione.

Tre strade, da valutare:

1. **Template Hestia personalizzato** (`/usr/local/hestia/data/templates/web/nginx/`)
   con una `location ~ ^/(admin|api)` che salta il proxy verso Apache e va
   all'applicazione. È la via pulita, ma richiede di mantenere un template.
2. **Admin su un sottodominio della piattaforma** invece che sul dominio del
   cliente: `cliente.slimcms.it/admin` anziché `cliente.it/admin`. Devia dalle
   specifiche §8, ma elimina il problema e semplifica anche i certificati.
3. **Docroot all'applicazione** e Astro che pubblica dentro `public/siti/<dominio>/`,
   con Laravel che serve i file statici. Semplice da configurare, ma rimette
   Laravel nel percorso di lettura pubblico, cioè annulla il vantaggio
   architetturale dell'intero progetto. **Da scartare.**

`ResolveSiteFromDomain` funziona in tutti e tre i casi: capisce di quale sito
si tratta dall'`Host`, e non c'è mai una copia dell'applicazione per sito.

## 5. Verifica

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://manage.slimcms.it/login   # 200
curl -sS -o /dev/null -w '%{http_code}\n' https://slimcms.it/admin/login    # 200
php artisan slimcms:monitora-certificati                                    # tutto valido
```
