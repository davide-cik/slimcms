import type { APIRoute } from 'astro';
import { elencoProdotti, immagineOpenGraph } from '../../../lib/api';

/**
 * Immagini Open Graph dei PRODOTTI, in una cartella propria come quelle
 * degli articoli: gli slug vivono in tabelle diverse e possono coincidere.
 */
export async function getStaticPaths() {
  const prodotti = await elencoProdotti();

  return prodotti.map((p) => ({ params: { slug: p.slug } }));
}

export const GET: APIRoute = async ({ params }) => {
  const byte = await immagineOpenGraph(String(params.slug), 'prodotto');

  return new Response(byte, {
    headers: {
      'Content-Type': 'image/png',
      'Cache-Control': 'public, max-age=3600',
    },
  });
};
