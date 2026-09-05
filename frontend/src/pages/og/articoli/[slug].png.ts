import type { APIRoute } from 'astro';
import { elencoArticoli, immagineOpenGraph } from '../../../lib/api';

/**
 * Immagini Open Graph degli ARTICOLI.
 *
 * In una cartella a parte da quelle delle pagine perche' gli slug vivono in
 * due tabelle diverse: una pagina "guida" e un articolo "guida" sono
 * legittimi, e con un percorso solo la seconda immagine sovrascriverebbe la
 * prima senza che nulla lo segnalasse.
 */
export async function getStaticPaths() {
  const articoli = await elencoArticoli();

  return articoli.map((a) => ({ params: { slug: a.slug } }));
}

export const GET: APIRoute = async ({ params }) => {
  const byte = await immagineOpenGraph(String(params.slug), 'articolo');

  return new Response(byte, {
    headers: {
      'Content-Type': 'image/png',
      'Cache-Control': 'public, max-age=3600',
    },
  });
};
