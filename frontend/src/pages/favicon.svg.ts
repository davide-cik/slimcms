import type { APIRoute } from 'astro';
import { sito } from '../lib/api';

/**
 * Favicon del sito, generata in fase di build dai dati dell'API.
 *
 * Non e' un file fisso in public/: ogni sito servito dalla piattaforma ha la
 * propria, con le proprie iniziali e i propri colori. Se il cliente ne ha
 * caricata una, l'API restituisce comunque un SVG coerente e il file caricato
 * viene esposto a parte come favicon_path.
 */
export const GET: APIRoute = async () => {
  const s = await sito();

  return new Response(s.favicon_svg, {
    headers: {
      'Content-Type': 'image/svg+xml',
      // Il nome del file non cambia mai, quindi non si puo' mettere in cache
      // per sempre: un'ora e' il compromesso fra traffico e tempo di
      // propagazione quando il cliente la cambia.
      'Cache-Control': 'public, max-age=3600',
    },
  });
};
