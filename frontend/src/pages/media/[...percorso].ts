import type { APIRoute } from 'astro';
import { elencoMedia, scaricaMedia } from '../../lib/api';

/**
 * I file media, scritti come file statici del sito in fase di build.
 *
 * Stessa ragione delle immagini Open Graph: il sito pubblico non deve
 * chiedere niente a Laravel per essere leggibile. Servire le foto da
 * manage.slimcms.it costerebbe a ogni visitatore una connessione in piu' a
 * un altro dominio, esporrebbe il dominio di gestione, e farebbe sparire le
 * immagini di tutti i clienti se il backend fosse fermo.
 */
export async function getStaticPaths() {
  const media = await elencoMedia();

  return media.map((m) => ({
    // Il percorso di destinazione e' /media/<id>/<nome>: qui si toglie il
    // prefisso, perche' il parametro e' cio' che segue /media/.
    params: { percorso: m.percorso.replace(/^\/media\//, '') },
    props: { origine: m.origine },
  }));
}

export const GET: APIRoute = async ({ props }) => {
  const byte = await scaricaMedia(String((props as { origine: string }).origine));

  return new Response(byte, {
    headers: {
      // I file sono immutabili: il nome cambia se cambia il contenuto,
      // quindi si possono tenere in cache a lungo.
      'Cache-Control': 'public, max-age=31536000, immutable',
    },
  });
};
