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
}

export interface ConfigLayout {
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
  favicon_path: string | null;
  favicon_svg: string;
  favicon_iniziali: string;
  theme: Record<string, unknown>;
  seo_defaults: SeoDiSito;
  og_config: Record<string, unknown>;
  footer_config: ConfigFooter;
  layout_config: ConfigLayout;
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
export async function immagineOpenGraph(slug: string): Promise<ArrayBuffer> {
  if (!TOKEN) throw new Error('SLIMCMS_API_TOKEN non impostato.');

  const percorso = `/og/${slug}.png`;
  const risposta = await fetch(`${BASE}/sites/${SITE}${percorso}`, {
    headers: { Authorization: `Bearer ${TOKEN}` },
  });

  if (!risposta.ok) {
    throw new Error(`Immagine Open Graph per "${slug}": ${risposta.status} ${risposta.statusText}`);
  }

  return risposta.arrayBuffer();
}

export async function elencoPagine(): Promise<Pagina[]> {
  const { data } = await chiama<{ data: Pagina[] }>('/pages');
  return data;
}

export async function pagina(slug: string): Promise<Pagina> {
  const { data } = await chiama<{ data: Pagina }>(`/pages/${slug}`);
  return data;
}

export async function sitemap(): Promise<{
  site: string;
  urls: { loc: string; lastmod: string | null; changefreq: string; priority: string }[];
}> {
  return chiama('/sitemap');
}
