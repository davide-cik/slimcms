import type { Pagina } from './api';

/**
 * Genera il JSON-LD dai campi che l'API restituisce, senza che il redattore
 * debba scrivere una riga di markup (specifiche, sezione 6).
 *
 * Restituisce un array di nodi: una pagina puo' averne piu' d'uno, per
 * esempio il tipo principale piu' un FAQPage se ci sono domande.
 */
export function grafoJsonLd(p: Pagina, dominio: string, editore?: Record<string, unknown>) {
  // Slash finale coerente con la sitemap e con cio' che il server
  // restituisce con 200: la forma senza slash risponde 301.
  const url = `https://${dominio}${p.is_home ? '/' : `/${p.slug}/`}`;
  const nodi: Record<string, unknown>[] = [];

  const principale: Record<string, unknown> = {
    '@context': 'https://schema.org',
    '@type': p.aeo.schema_type || 'Article',
    name: p.seo.meta_title,
    headline: p.title,
    url,
    // structured_summary e' scritto per i motori generativi: se c'e', e'
    // una descrizione migliore della meta_description, pensata per essere citata.
    description: p.geo.structured_summary ?? p.seo.meta_description ?? undefined,
    datePublished: p.published_at ?? undefined,
    dateModified: p.updated_at ?? undefined,
  };

  if (editore) principale.publisher = editore;
  if (p.seo.og_image) principale.image = `https://${dominio}${p.seo.og_image}`;

  nodi.push(pulisci(principale));

  if (p.aeo.faq.length > 0) {
    nodi.push({
      '@context': 'https://schema.org',
      '@type': 'FAQPage',
      mainEntity: p.aeo.faq.map((v) => ({
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
