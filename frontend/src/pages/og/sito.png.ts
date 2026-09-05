import type { APIRoute } from 'astro';
import { immagineOpenGraphSito } from '../../lib/api';

/**
 * L'immagine Open Graph del sito.
 *
 * La usano le pagine che non sono un contenuto — indice del blog, archivi,
 * pagina d'errore — che non hanno un'immagine propria. Senza questa rotta
 * quelle pagine dichiarano un og:image che non esiste, e chi le condivide
 * vede un riquadro vuoto.
 */
export const GET: APIRoute = async () =>
  new Response(await immagineOpenGraphSito(), {
    headers: { 'Content-Type': 'image/png', 'Cache-Control': 'public, max-age=3600' },
  });
