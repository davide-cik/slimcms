import type { APIRoute } from 'astro';
import { faviconIco } from '../lib/api';

/**
 * `/favicon.ico`, il file che i browser chiedono da soli.
 *
 * Non lo cerca chi legge l'HTML: lo chiede il browser alla radice del
 * dominio, senza guardare i `<link>`, e con lui ogni crawler. Finche' non
 * c'era, ogni visita a slimcms.it produceva un 404 — sono stati i primi che
 * il nostro stesso monitoraggio dei 404 ha registrato.
 *
 * Lo genera il backend da un'unica sorgente (l'immagine caricata dal
 * cliente, altrimenti le iniziali) cosi' l'icona nella scheda e quella nei
 * preferiti non possono raccontare due cose diverse.
 */
export const GET: APIRoute = async () =>
  new Response(await faviconIco(), {
    headers: { 'Content-Type': 'image/x-icon', 'Cache-Control': 'public, max-age=3600' },
  });
