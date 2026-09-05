import type { APIRoute } from 'astro';
import { sito } from '../lib/api';

/**
 * Favicon del sito, generata in fase di build dai dati dell'API.
 *
 * Non e' un file fisso in public/: ogni sito servito dalla piattaforma ha la
 * propria, con le proprie iniziali e i propri colori.
 *
 * Puo' non esserci: se il cliente ha caricato un PNG, `favicon_svg` e' null,
 * perche' un PNG non diventa un SVG e due icone che si contraddicono sono
 * peggio di una sola. In quel caso la rotta scrive un file vuoto e
 * l'integrazione `slimcms-favicon` lo toglie dal `dist/` a fine build —
 * Astro non sa saltare una rotta statica, ma sa cosa ha scritto.
 */
export const GET: APIRoute = async () => {
  const s = await sito();

  return new Response(s.favicon_svg ?? '', {
    headers: {
      'Content-Type': 'image/svg+xml',
      // Il nome del file non cambia mai, quindi non si puo' mettere in cache
      // per sempre: un'ora e' il compromesso fra traffico e tempo di
      // propagazione quando il cliente la cambia.
      'Cache-Control': 'public, max-age=3600',
    },
  });
};
