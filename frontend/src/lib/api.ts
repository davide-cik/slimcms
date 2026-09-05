/**
 * Client per l'API SlimCMS.
 *
 * Viene chiamato SOLO in fase di build: il visitatore riceve HTML statico e
 * non tocca mai il backend (specifiche, sezione 7). Se un giorno vedi questo
 * modulo importato da un componente con `client:*`, e' un errore.
 */

const BASE = import.meta.env.SLIMCMS_API_URL ?? 'http://127.0.0.1:8000/api';
const TOKEN = import.meta.env.SLIMCMS_API_TOKEN;
const SITE = import.meta.env.SLIMCMS_SITE ?? 'slimcms.it';

export interface Blocco {
  tipo?: string;
  [chiave: string]: unknown;
}

export interface Pagina {
  id: number;
  title: string;
  slug: string;
  /** Su quante colonne si dispongono i blocchi di corpo. */
  colonne: number;
  /** Quale pagina sta sulla radice: lo dice il flag, non lo slug. */
  is_home: boolean;
  status: string;
  published_at: string | null;
  updated_at: string | null;
  blocks: Blocco[];
  seo: {
    meta_title: string;
    meta_description: string | null;
    canonical_url: string | null;
    noindex: boolean;
    og_title: string | null;
    og_description: string | null;
    og_image: string | null;
  };
  geo: {
    structured_summary: string | null;
    key_facts: string[];
    source_attribution: { published_at: string | null; updated_at: string | null };
  };
  aeo: {
    direct_answer: string | null;
    faq: { domanda: string; risposta: string }[];
    schema_type: string;
  };
}

async function chiama<T>(percorso: string): Promise<T> {
  if (!TOKEN) {
    throw new Error(
      'SLIMCMS_API_TOKEN non impostato. Emettine uno con:\n' +
        '  php artisan slimcms:build-token <email> --site=<dominio>'
    );
  }

  const risposta = await fetch(`${BASE}/sites/${SITE}${percorso}`, {
    headers: { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' },
  });

  if (!risposta.ok) {
    // Fallire la build e' corretto: pubblicare un sito con contenuti mancanti
    // e' peggio che non pubblicarlo.
    throw new Error(
      `API ${percorso} ha risposto ${risposta.status} ${risposta.statusText}. ` +
        (risposta.status === 403
          ? 'Il token non e\' abilitato per questo sito.'
          : '')
    );
  }

  return risposta.json() as Promise<T>;
}

export interface VoceFooter {
  etichetta: string;
  url: string;
}

export interface ColonnaFooter {
  titolo: string;
  voci?: VoceFooter[];
}

export interface ConfigFooter {
  tipo?: 'semplice' | 'colonne';
  colonne?: number;
  blocchi?: ColonnaFooter[];
  descrizione?: string;
  firma?: boolean;
  organizzazione?: string;
  legale?: string;
}

export interface VoceMenu {
  etichetta: string;
  url: string;
  evidenza?: boolean;
  /**
   * Sottovoci. Il pannello non le offre ancora: il tipo le prevede perche'
   * aggiungerle piu' avanti non debba diventare una migrazione dei dati gia'
   * salvati di tutti i siti.
   */
  voci?: VoceMenu[];
}

export type TipoTestata = 'semplice' | 'centrata' | 'divisa' | 'compatta';

export interface ConfigLayout {
  tipo?: TipoTestata;
  /** La testata resta in alto mentre si scorre. */
  fissa?: boolean;
  /** Riga di servizio sopra la testata: compare solo se ha almeno un campo. */
  barra?: {
    testo?: string;
    telefono?: string;
    email?: string;
  };
  mostra_logo?: boolean;
  nome_visibile?: string;
  voci?: VoceMenu[];
  doppio?: {
    attivo?: boolean;
    etichetta?: string;
    testo?: string;
  };
}

/** I valori di `seo_defaults` che il layout usa davvero. */
export interface SeoDiSito {
  publisher?: string;
  og_image?: string;
  webmaster?: {
    google?: string | null;
    bing?: string | null;
    yandex?: string | null;
  };
  analytics?: {
    ga4?: string | null;
    anonimizza?: boolean;
  };
  [chiave: string]: unknown;
}

export interface Sito {
  id: number;
  domain: string;
  name: string;
  logo_path: string | null;
  // Null quando il cliente ha caricato un'immagine non vettoriale: in
  // quel caso il sito pubblica solo /favicon.ico.
  favicon_svg: string | null;
  favicon_iniziali: string;
  // Come disegnare la verifica anti-spam. Solo la parte pubblica: il segreto
  // resta nel backend.
  captcha?: { tipo: 'semplice' | 'turnstile' | 'recaptcha' | 'nessuno'; chiave?: string | null };
  theme: Record<string, unknown>;
  seo_defaults: SeoDiSito;
  og_config: Record<string, unknown>;
  footer_config: ConfigFooter;
  layout_config: ConfigLayout;
  /** Il segmento sotto cui vive il blog, gia' normalizzato da Laravel. */
  base_blog: string;
}

export async function sito(): Promise<Sito> {
  const { data } = await chiama<{ data: Sito }>('');
  return data;
}

/**
 * Immagine Open Graph di un contenuto, in byte.
 *
 * Si scarica in build e si scrive come file statico del sito: i social la
 * rileggono a ogni condivisione e devono trovarla sul dominio del sito, non
 * dietro un token dell'API.
 */
/**
 * La favicon in formato ICO, in byte.
 *
 * Si scarica in build come le immagini Open Graph: `/favicon.ico` lo chiede
 * il browser al dominio del sito, e dietro un token dell'API non lo
 * troverebbe mai.
 */
export async function faviconIco(): Promise<ArrayBuffer> {
  if (!TOKEN) throw new Error('SLIMCMS_API_TOKEN non impostato.');

  const risposta = await fetch(`${BASE}/sites/${SITE}/favicon.ico`, {
    headers: { Authorization: `Bearer ${TOKEN}` },
  });

  if (!risposta.ok) {
    throw new Error(`Favicon ICO: l'API ha risposto ${risposta.status}.`);
  }

  return risposta.arrayBuffer();
}

/** L'immagine Open Graph del SITO, per le pagine che non sono un contenuto. */
export async function immagineOpenGraphSito(): Promise<ArrayBuffer> {
  if (!TOKEN) throw new Error('SLIMCMS_API_TOKEN non impostato.');

  const risposta = await fetch(`${BASE}/sites/${SITE}/og.png`, {
    headers: { Authorization: `Bearer ${TOKEN}` },
  });

  if (!risposta.ok) {
    throw new Error(`Immagine Open Graph del sito: l'API ha risposto ${risposta.status}.`);
  }

  return risposta.arrayBuffer();
}

export async function immagineOpenGraph(
  slug: string,
  tipo: 'pagina' | 'articolo' = 'pagina'
): Promise<ArrayBuffer> {
  if (!TOKEN) throw new Error('SLIMCMS_API_TOKEN non impostato.');

  // Il tipo disambigua: lo stesso slug puo' esistere sia come pagina sia
  // come articolo, e senza questo l'API restituirebbe sempre la pagina.
  const percorso = `/og/${slug}.png?tipo=${tipo}`;
  const risposta = await fetch(`${BASE}/sites/${SITE}${percorso}`, {
    headers: { Authorization: `Bearer ${TOKEN}` },
  });

  if (!risposta.ok) {
    throw new Error(`Immagine Open Graph per "${slug}": ${risposta.status} ${risposta.statusText}`);
  }

  return risposta.arrayBuffer();
}

/**
 * Un file media come lo consegna l'API, con l'url gia' risolto.
 */
export interface Media {
  id: number;
  url: string;
  anteprima: string | null;
  alt: string | null;
  [chiave: string]: unknown;
}

export const eMedia = (v: unknown): v is Media =>
  typeof v === 'object' && v !== null &&
  typeof (v as Media).url === 'string' && typeof (v as Media).id === 'number';

/**
 * Il percorso con cui un file media vive sul dominio DEL SITO.
 *
 * L'API restituisce url assoluti verso il backend. Lasciarli cosi'
 * significherebbe che ogni foto del sito pubblico viene servita da
 * manage.slimcms.it: il sito statico tornerebbe a dipendere da Laravel per
 * essere leggibile, che e' esattamente cio' che l'architettura evita
 * (specifiche §7). I file si scaricano in build e si riscrivono qui.
 *
 * Deterministico e senza stato condiviso: la rotta che scarica i file e le
 * pagine che li citano ricavano lo stesso percorso dalla stessa funzione.
 */
export function percorsoMedia(m: Media): string {
  const nome = new URL(m.url).pathname.split('/').filter(Boolean).pop() ?? `${m.id}`;

  return `/media/${m.id}/${nome}`;
}

/**
 * Tutti i file media citati dalle pagine, con origine e destinazione.
 *
 * Si cammina l'intera struttura invece di guardare solo le chiavi note: un
 * blocco nuovo che porta un'immagine non deve richiedere di ricordarsi di
 * aggiornare anche questo elenco.
 */
export async function elencoMedia(): Promise<{ origine: string; percorso: string }[]> {
  const trovati = new Map<string, { origine: string; percorso: string }>();

  const cammina = (valore: unknown): void => {
    if (eMedia(valore)) {
      const percorso = percorsoMedia(valore);
      trovati.set(percorso, { origine: valore.url, percorso });

      return;
    }

    if (Array.isArray(valore)) {
      valore.forEach(cammina);

      return;
    }

    if (typeof valore === 'object' && valore !== null) {
      Object.values(valore).forEach(cammina);
    }
  };

  // Pagine E articoli: un articolo ha la copertina e puo' avere blocchi con
  // immagini. Camminare solo le pagine lascerebbe quelle degli articoli a
  // puntare a file mai scaricati — riquadri rotti su ogni articolo.
  cammina(await Promise.all([elencoPagine(), elencoArticoli()]));

  return [...trovati.values()];
}

/** Scarica un file media dal backend, in byte. */
export async function scaricaMedia(origine: string): Promise<ArrayBuffer> {
  const risposta = await fetch(origine);

  if (!risposta.ok) {
    throw new Error(`Media non scaricabile: ${origine} ha risposto ${risposta.status}.`);
  }

  return risposta.arrayBuffer();
}

export interface Termine {
  name: string;
  slug: string;
}

export interface Articolo {
  id: number;
  title: string;
  slug: string;
  excerpt: string | null;
  featured_image: Media | null;
  status: string;
  published_at: string | null;
  updated_at: string | null;
  author?: { name: string | null };
  categories?: Termine[];
  tags?: Termine[];
  blocks: Blocco[];
  seo: Pagina['seo'];
  geo: {
    structured_summary: string | null;
    key_facts: string[];
    source_attribution: { author?: string | null; published_at: string | null; updated_at: string | null };
  };
  aeo: Pagina['aeo'];
}

/**
 * Tutti gli articoli pubblicati, seguendo la paginazione fino in fondo.
 *
 * L'endpoint pagina a 20: fermarsi alla prima pagina vorrebbe dire costruire
 * un sito a cui mancano articoli senza che niente lo segnali — il tipo di
 * guasto che si scopre mesi dopo, quando qualcuno cerca un vecchio pezzo.
 */
export async function elencoArticoli(): Promise<Articolo[]> {
  const tutti: Articolo[] = [];
  let pagina = 1;

  // Il tetto e' una difesa contro un `last_page` che non arriva mai: un ciclo
  // infinito in fase di build bloccherebbe le pubblicazioni di tutti i siti.
  for (let i = 0; i < 200; i++) {
    const risposta = await chiama<{ data: Articolo[]; meta?: { last_page?: number } }>(
      `/posts?per_page=50&page=${pagina}`
    );

    tutti.push(...risposta.data);

    const ultima = risposta.meta?.last_page ?? pagina;

    if (pagina >= ultima) break;

    pagina++;
  }

  return tutti;
}

export async function elencoPagine(): Promise<Pagina[]> {
  const { data } = await chiama<{ data: Pagina[] }>('/pages');
  return data;
}

export async function pagina(slug: string): Promise<Pagina> {
  const { data } = await chiama<{ data: Pagina }>(`/pages/${slug}`);
  return data;
}

/**
 * Il .htaccess del sito, gia' compilato da Laravel.
 *
 * Si scarica il file finito e non l'elenco dei redirect perche' la regola di
 * come un reindirizzamento diventa configurazione Apache deve stare in un
 * posto solo. Riscriverla qui sarebbe la stessa giuntura che in questo
 * progetto ha gia' prodotto piu' di un guasto.
 */
export async function htaccess(): Promise<string> {
  if (!TOKEN) throw new Error('SLIMCMS_API_TOKEN non impostato.');

  const risposta = await fetch(`${BASE}/sites/${SITE}/htaccess`, {
    headers: { Authorization: `Bearer ${TOKEN}` },
  });

  if (!risposta.ok) {
    throw new Error(`htaccess: l'API ha risposto ${risposta.status}.`);
  }

  return risposta.text();
}

export async function sitemap(): Promise<{
  site: string;
  urls: { loc: string; lastmod: string | null; changefreq: string; priority: string }[];
}> {
  return chiama('/sitemap');
}
