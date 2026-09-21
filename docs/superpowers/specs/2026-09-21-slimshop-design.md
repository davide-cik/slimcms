# SlimShop — il modulo negozio

Data: 2026-09-21. Stato: disegno approvato a voce, in attesa di rilettura.

Primo cliente: enneability.it — due prodotti (mazzo singolo 39 €, kit da 3 a 99 € invece di
117 €), con una pagina intermedia che propone il kit.

Nel codice il modulo si chiama `negozio` (italiano come `moduli`, `messaggi`); «SlimShop» è il
nome commerciale e può cambiare senza toccare il codice.

## Decisioni prese

| Tema | Decisione | Perché |
|---|---|---|
| Dove vanno i soldi | sull'account Stripe **del cliente**, con la sua chiave | non tocchiamo denaro altrui: niente Connect, niente obblighi da intermediario |
| Integrazione | **Stripe Checkout ospitato**, carrello nel browser | i dati di carta non toccano i nostri server; il sito resta statico |
| Upsell | **pagina intermedia** dopo «Aggiungi al carrello» | è come funziona oggi Enneability |
| Spedizione | costo **fisso** per ordine + **soglia** di gratuità | copre i piccoli negozi senza tabelle di tariffe |
| Fatture | prezzi IVA inclusa; dati di fatturazione **facoltativi** raccolti da Stripe | il cliente emette la fattura col suo programma; noi non emettiamo niente |
| Scorte | **contatore** di pezzi, prenotati all'apertura del pagamento | vedi §4 |
| Esaurito | pulsante «Avvisami quando torna disponibile» | vedi §6 |
| Abilitazione | interruttore nel **control plane** | il cliente decide *come* usa il negozio, noi *se* ce l'ha |

Scartati: Stripe Payment Links (niente carrello né upsell, catalogo in due posti) e Stripe
Elements nel sito (molto JavaScript nel sito statico, 3-D Secure a mano, per un risultato che
oggi non serve).

## 1. Abilitazione

`sites.shop_attivo` (bool, default `false`) si imposta **solo** da `/manage/sites`, accanto a
`stato`. Stessa linea di sospensione e parcheggio: nel control plane quello che il sito *può*
fare.

A negozio spento:

- nel pannello del sito non compaiono «Prodotti», «Ordini» né la sezione «Negozio» delle
  impostazioni (`canAccess()` / `shouldRegisterNavigation()` guardano il flag, e le policy
  negano: una voce nascosta non è un controllo);
- `POST /checkout` e `POST /avvisami` rispondono **404**;
- la build non genera pagine prodotto, carrello, offerta, grazie, né `/negozio.json`;
- i **webhook restano accettati**: un pagamento già avvenuto va registrato comunque;
- niente viene cancellato: riaccendere riporta tutto com'era.

`CicloDiVitaSitoTest` fissa che `shop_attivo` sta nel control plane e non nel pannello del sito.

## 2. Modello dei dati

Tutte le tabelle nuove hanno `site_id` indicizzato e il trait `BelongsToSite`: `TenantScopeTest`
le copre da solo. **Tutti gli importi sono interi in centesimi, IVA inclusa**: con i decimali
3 × 33,33 fa 99,99 e la differenza si scopre in contabilità.

### `prodotti` — `App\Models\Prodotto`

| Colonna | Tipo | Note |
|---|---|---|
| `site_id` | int, indice | |
| `slug` | string | unico su `(site_id, slug)`, via `Slug::da()` e `Slug::regolaUnica()` |
| `nome` | string | |
| `descrizione` | text | testo ricco |
| `status` | string | enum di `Page`/`Post`; pubblicare passa da `PubblicazioneRiservata` |
| `prezzo` | unsigned int | centesimi |
| `prezzo_barrato` | unsigned int, null | mostrato barrato sopra `prezzo`; deve essere > `prezzo` |
| `scorte` | unsigned int | non scende mai sotto zero (vedi §4) |
| `upsell_prodotto_id` | FK null → `prodotti` | stesso sito, diverso da sé |
| `upsell_titolo`, `upsell_testo` | string/text null | testo della pagina intermedia |
| `seo`, `structured_summary`, `key_facts`, `faq_block`, `direct_answer` | come su `Post` | JSON con cast espliciti |
| timestamps, soft deletes | | |

Immagini: collezione media `immagini` sul prodotto; la prima è la copertina. I blocchi e le
pagine le risolvono come le altre immagini: in build diventano file del sito.

`upsell_prodotto_id` si valida **dentro il sito**: la regola `exists` interroga la tabella e
non il modello, quindi il `where('site_id')` va messo a mano (stessa trappola di
`Slug::regolaUnica()`).

### `ordini` — `App\Models\Ordine`

| Colonna | Tipo | Note |
|---|---|---|
| `site_id` | int, indice | |
| `numero` | unsigned int | progressivo **per sito**, unico su `(site_id, numero)`, assegnato sotto lock |
| `stato` | string | `in_attesa`, `pagato`, `spedito`, `scaduto`, `rimborsato` |
| `stripe_session_id` | string, unico | chiave di idempotenza dei webhook |
| `stripe_payment_intent` | string null | per il link al pagamento su Stripe |
| `livemode` | bool | un ordine di prova non si confonde con uno vero |
| `email`, `nome`, `telefono` | null finché non pagato | |
| `indirizzo_spedizione` | JSON null | come lo restituisce Stripe |
| `fattura` | JSON null | codice fiscale / P.IVA, SDI o PEC, ragione sociale |
| `subtotale`, `spedizione`, `totale` | unsigned int | centesimi |
| `scade_at`, `pagato_at`, `spedito_at` | timestamp null | |

`stato` segue la stessa scelta di `sites.stato`: **niente cast enum** (il cast alza
`ValueError` in lettura su un valore imprevisto e renderebbe illeggibile un ordine pagato);
`Ordine::statoOrdine()` è l'unico punto di conversione, sull'enum `App\Enums\StatoOrdine`.

### `righe_ordine` — `App\Models\RigaOrdine`

`site_id`, `ordine_id`, `prodotto_id` (null on delete), `nome`, `prezzo_unitario`, `quantita`.
Nome e prezzo sono **copiati** all'acquisto: un prezzo cambiato o un prodotto cancellato
l'anno prossimo non riscrivono un ordine pagato.

### `avvisi_disponibilita` — `App\Models\AvvisoDisponibilita`

`site_id`, `prodotto_id`, `email`, `token` (casuale, per la cancellazione), timestamps.
Unico su `(prodotto_id, email)`. Vedi §6.

### Colonne su `sites`

| Colonna | Chi la scrive | Note |
|---|---|---|
| `shop_attivo` | control plane | §1 |
| `spedizione_costo` | pannello (admin) | centesimi |
| `spedizione_gratis_da` | pannello (admin) | centesimi, null = mai gratuita |
| `email_ordini` | pannello (admin) | null → `contact_email` |
| `stripe_chiave_segreta` | pannello (admin) | cast `encrypted`, mai in uscita dall'API |
| `stripe_account_nome`, `stripe_livemode` | backend | mostrati nel pannello dopo la verifica |
| `stripe_webhook_id` | backend | |
| `stripe_webhook_segreto` | backend | cast `encrypted` |

`SiteObserver` accoda una build su ogni colonna non operativa: le colonne `stripe_*`
vanno aggiunte all'elenco delle **escluse** (non cambiano il sito pubblicato), le altre
accodano come devono.

## 3. Percorso d'acquisto

### Pagine statiche

| Indirizzo | Contenuto | Indicizzata |
|---|---|---|
| `/prodotti/<slug>/` | prodotto, prezzo, disponibilità, pulsante | sì, in sitemap e indice di ricerca |
| `/prodotti/<slug>/offerta/` | pagina intermedia di upsell (se configurata) | `noindex` |
| `/carrello/` | carrello disegnato da JS | `noindex` |
| `/grazie/` | conferma, svuota il carrello | `noindex` |

Tutte passano dalla rotta unica `[...percorso].astro` con un nuovo discriminatore, come il blog.
JSON-LD `Product` + `Offer` (`price`, `priceCurrency: EUR`, `availability` InStock/OutOfStock)
da un nuovo `grafoProdottoJsonLd`; `Base.astro` riceve un `Documento`, come per gli articoli.

`/negozio.json` si genera in build (come `ricerca-indice.json`): `id`, `slug`, `nome`,
`prezzo`, `prezzo_barrato`, immagine, disponibile sì/no, e la configurazione di spedizione.
Serve **solo** a disegnare il carrello: i prezzi che mostra sono indicativi, quello vero lo
decide il backend.

### Il carrello

In `localStorage`, solo `[{id, quantita}]`. Letture e scritture in `try/catch`: un browser in
navigazione privata non deve rompere la pagina. Un `id` che non compare più in `/negozio.json`
sparisce dal carrello con un avviso.

Sulla pagina di offerta, «Sì» **sostituisce** il prodotto appena aggiunto con quello proposto;
«No, grazie» porta al carrello. Se il prodotto non ha upsell, «Aggiungi al carrello» va dritto
a `/carrello/`.

Il carrello mostra subtotale, spedizione, e «ti mancano X € per la spedizione gratuita» quando
c'è una soglia.

### `POST /api/public/{sito}/checkout`

Corpo: `{ righe: [{id, quantita}], esca }`. Rate limit: 5 sessioni per IP all'ora.

In **una transazione**:

1. negozio attivo (altrimenti 404), esca vuota (altrimenti 200 finto, come il contatto);
2. da 1 a 20 righe, ogni `quantita` fra 1 e 10, `id` di prodotti **pubblicati** del sito
   (global scope; un prodotto di un altro sito è semplicemente assente → 422);
3. `lockForUpdate` sulle righe dei prodotti, **ordinate per id** (ordine fisso = niente
   deadlock fra due carrelli con gli stessi prodotti in ordine diverso);
4. scorte sufficienti per tutte le righe, altrimenti **409** con
   `[{id, nome, disponibili}]`; poi decremento;
5. `Ordine` `in_attesa` con righe copiate, `numero` progressivo, `scade_at = now + 30 min`;
6. sessione Checkout tramite `Pagamenti` (§5), `ordine_id` nei metadati, chiave di
   idempotenza = id dell'ordine.

Se il passo 6 fallisce la transazione torna indietro: **nessuna scorta resta prenotata per un
ordine che non esiste**. Risposta 503 «pagamento momentaneamente non disponibile, riprova tra
qualche minuto».

Risposta: `{ url }`, e il browser va su Stripe.

### Sessione Checkout

- `mode: payment`, `line_items` con `price_data` costruiti **dal database**;
- `shipping_options`: una tariffa fissa, oppure 0 se il subtotale raggiunge la soglia;
- `shipping_address_collection.allowed_countries = ['IT']`;
- `phone_number_collection`, `tax_id_collection` (P.IVA);
- `custom_fields` facoltativi: codice fiscale, codice SDI o PEC;
- `payment_method_types` limitati ai metodi **immediati** (carta, con Apple Pay e Google Pay
  inclusi): niente bonifici SEPA, niente stato «forse pagato fra tre giorni»;
- `expires_at` = 30 minuti (il minimo di Stripe);
- `success_url` = `https://<dominio>/grazie/`, `cancel_url` = `https://<dominio>/carrello/`.

## 4. Scorte

Le scorte si **prenotano all'apertura del pagamento**, non all'incasso. È l'unico modo in cui
due persone non possono pagare entrambe l'ultimo pezzo: la seconda riceve «esaurito» dal
carrello, prima di arrivare su Stripe.

| Evento | Scorte |
|---|---|
| checkout aperto | scalate (sotto lock) |
| `checkout.session.expired` | **restituite** |
| ordine pagato | nessun cambiamento (già scalate) |
| `charge.refunded` | nessun cambiamento: un rimborso non vuol dire che il pacco sia rientrato |
| modifica dal pannello | il valore scritto |

Un cambio di scorte che attraversa lo zero (da 0 a più, o da più a 0) accoda una build: la
pagina prodotto cambia fra «Aggiungi al carrello» e «Avvisami». Fino alla build decide il 409.

Il rischio accettato: un bot che apre checkout per bloccare il magazzino. Difese: rate limit
per IP, tetto per riga, esca, scadenza a 30 minuti. Ferma il bot banale; un ordine sospetto
si vede nel pannello e si annulla a mano (azione «Annulla» su un ordine `in_attesa`: scade la
sessione su Stripe e restituisce le scorte).

## 5. Stripe

### L'interfaccia `Pagamenti`

`App\Support\Pagamenti\Pagamenti`, sullo schema di `Captcha`: un'interfaccia, un'attuazione
`Stripe` (con `stripe/stripe-php`, versione API fissata nel codice) e una finta per i test.
**Nessun test chiama Stripe.**

Operazioni: verifica chiave (nome account, livemode), registra/cancella webhook, crea
sessione, scade sessione, leggi sessione (per la riconciliazione), verifica firma evento.

### Collegamento dell'account

L'admin incolla la chiave segreta (consigliata una *restricted key* con i soli permessi
necessari; il testo d'aiuto dice quali) in «Impostazioni del sito → Negozio». Al salvataggio:

1. chiamata di verifica: se fallisce il campo mostra l'errore e **niente viene salvato**;
2. se c'era un webhook registrato con la chiave precedente, viene cancellato;
3. registrazione del webhook verso `https://manage.slimcms.it/api/stripe/webhook/<dominio>`
   con i soli eventi usati; si salva id e segreto di firma;
4. il pannello mostra «Collegato a *<nome account>* — modalità test/live».

Il cliente non deve configurare niente dentro Stripe. La chiave e il segreto del webhook
**non escono mai dall'API**: un test lo fissa, come per il segreto del captcha.

### `POST /api/stripe/webhook/{sito}`

Fuori da CORS e da `api/public/*`. Firma verificata con il segreto **di quel sito**; firma
assente o non valida → 400, riga di log, nessun effetto.

| Evento | Effetto |
|---|---|
| `checkout.session.completed` con `payment_status = paid` | ordine `pagato`, salvati cliente, indirizzo, dati fattura, `pagato_at`; mail al titolare e a chi compra |
| `checkout.session.expired` | ordine `scaduto`, scorte restituite |
| `charge.refunded` | ordine `rimborsato` |

Ogni effetto è **idempotente**, deciso sotto `lockForUpdate` dell'ordine: Stripe rimanda lo
stesso evento più volte, e un ordine già `pagato` non manda una seconda mail né restituisce
scorte se arriva un `expired` in ritardo. Transizioni ammesse:
`in_attesa → pagato | scaduto`, `pagato → spedito | rimborsato`, `spedito → rimborsato`.
Tutto il resto si ignora (200, perché Stripe smetta di rimandarlo).

L'ordine si trova per `stripe_session_id` **e** `site_id` del sito della URL: un evento
firmato per un sito non può toccare l'ordine di un altro. Il webhook gira senza contesto
tenant: inizializza tenant e sito esplicitamente (regola 2 di CLAUDE.md).

### Riconciliazione

`slimcms:riconcilia-ordini`, cron ogni ora: per ogni ordine `in_attesa` con `scade_at` passato
da più di 10 minuti chiede la sessione a Stripe e applica lo stesso effetto del webhook
mancato. Un ordine pagato non deve restare «in attesa» perché un evento si è perso.

## 6. «Avvisami quando torna disponibile»

Su un prodotto esaurito il pulsante apre un piccolo modulo: email, consenso, verifica
anti-spam **del sito** (`App\Support\Captcha`, stesso fornitore del modulo di contatto).

`POST /api/public/{sito}/prodotti/{id}/avvisami`, con le difese dei moduli:

- captcha verificato **prima** della validazione;
- esca → 200 senza salvare;
- rate limit per IP;
- risposta **identica** se l'email era già iscritta o il prodotto è tornato disponibile nel
  frattempo: da fuori non si scopre chi aspetta cosa.

`slimcms:avvisi-disponibilita`, cron ogni cinque minuti, per ogni prodotto con scorte > 0 e
avvisi in attesa:

- manda la mail **solo se l'ultima build del sito completata è successiva** al cambio di
  scorte: prima, chi clicca troverebbe ancora «esaurito»;
- manda a tutti insieme, senza prenotare pezzi; la mail dice che chi arriva prima compra;
- mail = Mailable (`AvvisoDisponibilitaMail`), con link al prodotto e link di cancellazione
  (`GET /api/public/{sito}/avvisi/{token}/cancella`);
- **cancella la riga dopo l'invio**; le righe più vecchie di un anno si cancellano comunque.

Nel pannello, sulla scheda del prodotto: «N persone in attesa». Le email **non** sono
esportabili: sono state date per un avviso, non per una newsletter.

## 7. Pannello

| Voce | Visibile a | Scrive |
|---|---|---|
| Prodotti | viewer+ | author scrive, editor pubblica ed elimina, admin elimina definitivamente |
| Ordini | **editor+** (dati personali: il viewer non li vede) | editor: «segna come spedito», «annulla» (solo `in_attesa`) |
| Impostazioni → Negozio | admin | chiave Stripe, spedizione, soglia, email ordini |

Tutte con policy che estende `PolicyDiSito` (le dodici abilità), coperte da `PolicyRuoliTest`.
Gli ordini non si creano né si modificano a mano: `create`/`update` negati, le transizioni
passano dalle azioni. Il dettaglio mostra righe, indirizzo, dati fattura e il link al
pagamento nella dashboard Stripe del cliente.

Le mail d'ordine vanno a `email_ordini`, altrimenti a `contact_email`; se entrambe sono vuote
l'ordine resta comunque in tabella (stesso principio dei messaggi: la tabella prima della
mail). Mittente del dominio nostro, `Reply-To` di chi compra.

## 8. Errori

| Caso | Comportamento |
|---|---|
| Stripe irraggiungibile al checkout | transazione annullata, 503 con messaggio leggibile |
| Scorte insufficienti | 409 con prodotti e disponibilità; il carrello lo mostra riga per riga |
| Prezzo diverso nel browser | ignorato: il browser non manda prezzi |
| Webhook perso | Stripe riprova per tre giorni + riconciliazione oraria |
| Firma webhook non valida | 400, log, nessun effetto |
| Negozio spento con ordini in corso | webhook accettati, ordini registrati |
| Chiave Stripe revocata | checkout 503; il pannello mostra lo stato del collegamento |
| Mailer guasto | ordine già in tabella; l'errore finisce nel log |

## 9. Test

- `TenantScopeTest`: nessuna modifica, copre da solo i quattro modelli.
- Checkout: prezzo dal database; quantità fuori limite; prodotto di un altro sito; prodotto
  in bozza; 409 su scorte; **transazione annullata** se `Pagamenti` fallisce (scorte intatte);
  esca → 200 senza ordine; negozio spento → 404.
- Concorrenza: due checkout sull'ultimo pezzo, uno solo passa (due connessioni reali sul DB).
- Webhook: firma falsa rifiutata; evento ripetuto non raddoppia mail né scorte; `expired`
  restituisce le scorte; `expired` dopo `completed` ignorato; evento di un sito non tocca
  l'ordine di un altro.
- Riconciliazione: ordine pagato con webhook perso diventa `pagato`.
- Avvisi: captcha prima della validazione; esca; risposta identica; mail solo dopo la build;
  riga cancellata dopo l'invio; `Mail::fake()`.
- Segreti: chiave Stripe e segreto webhook assenti da ogni risposta API.
- Control plane: `shop_attivo` solo lì (`CicloDiVitaSitoTest`); a negozio spento niente voci,
  404 sulle API pubbliche, webhook ancora accettati.
- Policy: `PolicyRuoliTest` sulle tre risorse nuove; viewer non vede gli ordini.
- Frontend: `ContrattoBlocchiTest` esteso al tipo di documento prodotto; il gate di deploy
  verifica che ogni prodotto pubblicato abbia la sua pagina e che `/negozio.json` sia valido.

## 10. Passi di consegna

Ogni passo si pubblica da solo.

1. **Catalogo** — `shop_attivo`, `prodotti`, risorsa Prodotti, API, pagine prodotto statiche
   con JSON-LD. Enneability può già mostrare i prodotti (pulsante assente).
2. **Carrello e upsell** — `/negozio.json`, carrello, pagina di offerta. Pulsante «Paga»
   ancora spento.
3. **Pagamento** — `Pagamenti`, collegamento Stripe, checkout, prenotazione scorte, webhook,
   ordini e mail, riconciliazione, pannello Ordini.
4. **Avvisi di disponibilità** — modulo, tabella, comando, mail.

Il passo 3 va provato in **modalità test** di Stripe su enneability.it prima di passare alla
chiave live.

## Fuori da questa specifica

Varianti di prodotto (taglie, colori), codici sconto, spedizione fuori Italia, tariffe per
peso o zona, prodotti digitali, fatturazione elettronica automatica, esportazione ordini.
Ognuno è un'aggiunta locale a questo disegno, non una sua modifica.
