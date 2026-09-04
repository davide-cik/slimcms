import type { APIRoute } from 'astro';
import { elencoPagine, immagineOpenGraph } from '../../lib/api';

/**
 * Immagini Open Graph, scritte come file statici del sito in fase di build.
 *
 * Non si rimanda ai social l'URL dell'API: quella richiede un token, e i
 * social non ce l'hanno. L'immagine deve stare sul dominio del sito.
 */
export async function getStaticPaths() {
  const pagine = await elencoPagine();

  return pagine.map((p) => ({ params: { slug: p.slug } }));
}

export const GET: APIRoute = async ({ params }) => {
  const byte = await immagineOpenGraph(String(params.slug));

  return new Response(byte, {
    headers: {
      'Content-Type': 'image/png',
      'Cache-Control': 'public, max-age=3600',
    },
  });
};
