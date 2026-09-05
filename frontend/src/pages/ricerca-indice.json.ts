import type { APIRoute } from 'astro';
import { elencoArticoli, elencoPagine, sito, type Articolo, type Pagina } from '../lib/api';

/**
 * L'indice di ricerca del sito, generato in build.
 *
 * La ricerca gira nel browser su questo file, non su una chiamata all'API. E'
 * la sezione 7 delle specifiche applicata alla lettera: il sito pubblico non
 * tocca Laravel per leggere. In cambio la ricerca e' istantanea, funziona a
 * backend fermo, non consuma rate limit e non fa una richiesta di rete per
 * ogni tasto premuto.
 *
 * Non c'e' il rischio che l'indice invecchi: ogni pubblicazione accoda una
 * build, e la build lo riscrive. Se un giorno un cliente avesse cosi' tanti
 * contenuti da rendere il file pesante da scaricare, esiste ancora
 * `GET /api/public/{sito}/search` che fa la stessa cosa lato server.
 */

/** Il testo dentro un blocco, qualunque forma abbia. */
function testoDeiBlocchi(blocchi: unknown): string {
  const pezzi: string[] = [];

  const cammina = (v: unknown): void => {
    if (typeof v === 'string') {
      pezzi.push(v);
      return;
    }
    if (Array.isArray(v)) {
      v.forEach(cammina);
      return;
    }
    if (v && typeof v === 'object') {
      Object.values(v as Record<string, unknown>).forEach(cammina);
    }
  };

  cammina(blocchi);

  return pezzi
    .join(' ')
    // I blocchi di testo ricco contengono HTML: i tag non si cercano.
    .replace(/<[^>]*>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/**
 * Il testo indicizzato di un documento, troncato.
 *
 * Senza un tetto, un sito con dieci pagine lunghe produce un indice da
 * centinaia di kilobyte che ogni visitatore scarica per cercare una parola.
 * I primi 1200 caratteri contengono quasi sempre di che riconoscere una
 * pagina; il resto e' peso.
 */
const TETTO_TESTO = 1200;

export const GET: APIRoute = async () => {
  const [pagine, articoli, s] = await Promise.all([elencoPagine(), elencoArticoli(), sito()]);

  const voci = [
    ...pagine.map((p: Pagina) => ({
      titolo: p.title,
      percorso: p.is_home ? '/' : `/${p.slug}/`,
      tipo: 'pagina' as const,
      sommario: p.geo?.structured_summary ?? p.seo?.meta_description ?? null,
      testo: testoDeiBlocchi(p.blocks).slice(0, TETTO_TESTO),
    })),
    ...articoli.map((a: Articolo) => ({
      titolo: a.title,
      percorso: `/${s.base_blog}/${a.slug}/`,
      tipo: 'articolo' as const,
      sommario: a.excerpt ?? a.geo?.structured_summary ?? a.seo?.meta_description ?? null,
      testo: testoDeiBlocchi(a.blocks).slice(0, TETTO_TESTO),
    })),
  ];

  return new Response(JSON.stringify({ voci }), {
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'public, max-age=300',
    },
  });
};
