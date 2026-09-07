import type { APIRoute } from 'astro';
import { sito } from '../lib/api';

/**
 * La pagina mostrata quando un sito e' sospeso o parcheggiato.
 *
 * Apache la serve come `ErrorDocument 503` (vedi `GeneratoreHtaccess`): il
 * visitatore riceve **503**, non 200, perche' un 200 direbbe ai motori che
 * questo e' il contenuto adesso e si ritroverebbero indicizzata la pagina di
 * attesa al posto del sito.
 *
 * Si genera **sempre**, anche a sito attivo: e' un file da poche centinaia di
 * byte, e averlo gia' li' vuol dire che sospendere un sito e' una riga di
 * `.htaccess` che cambia, non una pubblicazione che deve andare a buon fine
 * mentre si sta cercando di fermare qualcosa.
 *
 * Gli stili sono in linea: la pagina deve reggersi da sola, senza dipendere
 * da un foglio di stile che potrebbe non essere piu' pubblicato.
 */
const esc = (t: string): string =>
  t.replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] ?? c
  );

export const GET: APIRoute = async () => {
  const s = await sito();
  const c = s.cortesia ?? {
    titolo: 'Torniamo presto',
    testo: 'Questo sito è temporaneamente non disponibile. Riprova più tardi.',
    nota: null,
  };

  const html = `<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- Una pagina di attesa non va indicizzata: il 503 dice ai motori di
     ripassare, questo dice di non prenderla comunque per il sito. -->
<meta name="robots" content="noindex, nofollow">
<title>${esc(c.titolo)} — ${esc(s.name)}</title>
<link rel="icon" href="/favicon.ico" sizes="32x32">
<style>
  :root { color-scheme: light dark; }
  body {
    margin: 0; min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
    padding: 2rem;
    font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    background: #fbfaf8; color: #1c1b19;
  }
  main { max-width: 30rem; text-align: center; }
  h1 { font-size: clamp(1.6rem, 5vw, 2.4rem); letter-spacing: -0.02em; margin: 0 0 0.8rem; }
  p { margin: 0 0 0.6rem; opacity: 0.8; }
  .nota { margin-top: 1.4rem; padding-top: 1.4rem; border-top: 1px solid rgba(0,0,0,.12); }
  .sito { margin-top: 2rem; font-size: 0.8rem; letter-spacing: 0.08em; text-transform: uppercase; opacity: 0.5; }
  @media (prefers-color-scheme: dark) {
    body { background: #14130f; color: #f2efe9; }
    .nota { border-color: rgba(255,255,255,.15); }
  }
</style>
</head>
<body>
<main>
  <h1>${esc(c.titolo)}</h1>
  <p>${esc(c.testo)}</p>
  ${c.nota ? `<p class="nota">${esc(c.nota)}</p>` : ''}
  <p class="sito">${esc(s.name)}</p>
</main>
</body>
</html>
`;

  return new Response(html, {
    headers: { 'Content-Type': 'text/html; charset=utf-8' },
  });
};
