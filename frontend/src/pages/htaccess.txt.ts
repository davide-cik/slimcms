import type { APIRoute } from 'astro';
import { htaccess } from '../lib/api';

/**
 * Il .htaccess del sito, generato come file temporaneo.
 *
 * Esiste come rotta e non direttamente come `.htaccess` perche' i nomi che
 * iniziano con un punto non sono indirizzi validi in Astro. L'integrazione
 * `slimcms-htaccess` lo rinomina a fine build e cancella questo file, quindi
 * non viene mai pubblicato.
 *
 * La fetch sta qui e non nell'integrazione perche' li' gira fuori da Vite e
 * `import.meta.env` e' vuoto: il token non arriverebbe.
 */
export const GET: APIRoute = async () =>
  new Response(await htaccess(), { headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
