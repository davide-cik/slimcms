import type { Articolo, Pagina, Termine } from './api';

/**
 * Genera il JSON-LD dai campi che l'API restituisce, senza che il redattore
 * debba scrivere una riga di markup (specifiche, sezione 6).
 *
 * Restituisce un array di nodi: una pagina puo' averne piu' d'uno, per
 * esempio il tipo principale piu' un FAQPage se ci sono domande.
 */
export function grafoJsonLd(
  p: Pagina,
  dominio: string,
  editore?: Record<string, unknown>
) {
  // Slash finale coerente con la sitemap e con cio' che il server
  // restituisce con 200: la forma senza slash risponde 301.
  const url = `https://${dominio}${p.is_home ? '/' : `/${p.slug}/`}`;

  return grafoDaCampi({
    url,
    schemaType: p.aeo.schema_type,
    metaTitle: p.seo.meta_title,
    titolo: p.title,
    descrizione: p.geo.structured_summary ?? p.seo.meta_description ?? undefined,
    pubblicato: p.published_at ?? undefined,
    modificato: p.updated_at ?? undefined,
    immagine: p.seo.og_image ?? undefined,
    faq: p.aeo.faq,
    dominio,
    editore,
  });
}

/**
 * Il grafo di un ARTICOLO.
 *
 * Non e' una Pagina e non va trattato come tale: passare un articolo alla
 * funzione delle pagine compilerebbe senza errori e produrrebbe un Article
 * senza autore e senza datePublished — peggio che non emettere niente,
 * perche' sembra completo.
 */
export function grafoArticoloJsonLd(
  a: Articolo,
  dominio: string,
  base: string,
  editore?: Record<string, unknown>
) {
  const nodi = grafoDaCampi({
    url: `https://${dominio}/${base}/${a.slug}/`,
    // Un articolo di blog e' un BlogPosting salvo diversa indicazione dal
    // pannello: e' piu' specifico di Article e i motori lo distinguono.
    schemaType: a.aeo.schema_type === 'Article' ? 'BlogPosting' : a.aeo.schema_type,
    metaTitle: a.seo.meta_title,
    titolo: a.title,
    descrizione: a.geo.structured_summary ?? a.seo.meta_description ?? a.excerpt ?? undefined,
    pubblicato: a.published_at ?? undefined,
    modificato: a.updated_at ?? undefined,
    immagine: a.seo.og_image ?? undefined,
    faq: a.aeo.faq,
    dominio,
    editore,
  });

  const principale = nodi[0] as Record<string, unknown>;

  if (a.author?.name) {
    principale.author = { '@type': 'Person', name: a.author.name };
  }

  // Le categorie sono la sezione editoriale dell'articolo; i tag le parole
  // chiave. Sono due campi diversi in Schema.org e non vanno mescolati.
  const sezioni = (a.categories ?? []).map((c: Termine) => c.name);
  if (sezioni.length > 0) principale.articleSection = sezioni;

  const parole = (a.tags ?? []).map((t: Termine) => t.name);
  if (parole.length > 0) principale.keywords = parole;

  return nodi;
}

/** Il grafo di un archivio (categoria o tag): un elenco, non un articolo. */
export function grafoArchivioJsonLd(
  titolo: string,
  url: string,
  articoli: Articolo[],
  dominio: string,
  base: string
) {
  return [
    {
      '@context': 'https://schema.org',
      '@type': 'CollectionPage',
      name: titolo,
      url,
      // ItemList invece di ripetere ogni articolo per intero: qui la pagina
      // e' un indice, e il contenuto vero sta altrove.
      mainEntity: {
        '@type': 'ItemList',
        numberOfItems: articoli.length,
        itemListElement: articoli.map((a, i) => ({
          '@type': 'ListItem',
          position: i + 1,
          url: `https://${dominio}/${base}/${a.slug}/`,
          name: a.title,
        })),
      },
    },
  ];
}

interface CampiGrafo {
  url: string;
  schemaType?: string;
  metaTitle?: string | null;
  titolo: string;
  descrizione?: string;
  pubblicato?: string;
  modificato?: string;
  immagine?: string;
  faq: { domanda: string; risposta: string }[];
  dominio: string;
  editore?: Record<string, unknown>;
}

function grafoDaCampi(c: CampiGrafo) {
  const { url, dominio, editore } = c;
  const nodi: Record<string, unknown>[] = [];

  const principale: Record<string, unknown> = {
    '@context': 'https://schema.org',
    '@type': c.schemaType || 'Article',
    name: c.metaTitle ?? undefined,
    headline: c.titolo,
    url,
    // structured_summary e' scritto per i motori generativi: se c'e', e' una
    // descrizione migliore della meta_description, pensata per essere citata.
    description: c.descrizione,
    datePublished: c.pubblicato,
    dateModified: c.modificato,
  };

  if (editore) principale.publisher = editore;

  // Un og_image puo' arrivare gia' assoluto (la copertina di un articolo lo
  // e', perche' l'API la espone con l'URL del backend). Anteporre il dominio
  // a un indirizzo assoluto produrrebbe una stringa senza senso, e i motori
  // scarterebbero l'immagine senza dirlo.
  if (c.immagine) {
    principale.image = c.immagine.startsWith('http')
      ? c.immagine
      : `https://${dominio}${c.immagine}`;
  }

  nodi.push(pulisci(principale));

  if (c.faq.length > 0) {
    nodi.push({
      '@context': 'https://schema.org',
      '@type': 'FAQPage',
      mainEntity: c.faq.map((v) => ({
        '@type': 'Question',
        name: v.domanda,
        acceptedAnswer: { '@type': 'Answer', text: v.risposta },
      })),
    });
  }

  return nodi;
}

/** Toglie le chiavi undefined, che in JSON-LD sono rumore. */
function pulisci(o: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(Object.entries(o).filter(([, v]) => v !== undefined));
}
