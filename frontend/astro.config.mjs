// @ts-check
import { defineConfig } from 'astro/config';
import { readFile, rename, unlink } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

/**
 * Deposita il .htaccess nella radice del sito costruito.
 *
 * Serve un passaggio in due tempi. Non puo' essere una rotta di Astro, perche'
 * i nomi che iniziano con un punto non sono indirizzi validi; e non puo'
 * essere messo a mano nella document root, perche' la pubblicazione fa
 * `rsync --delete` e lo cancellerebbe al primo deploy, con dentro i redirect
 * di tutti i clienti.
 *
 * Quindi la rotta `htaccess.txt` lo genera durante la build (li' Vite ha
 * caricato le variabili d'ambiente e il token esiste) e questa integrazione
 * lo rinomina alla fine, cancellando il file temporaneo.
 */
function htaccessDiSlimcms() {
  return {
    name: 'slimcms-htaccess',
    hooks: {
      'astro:build:done': async ({ dir, logger }) => {
        const temporaneo = fileURLToPath(new URL('htaccess.txt', dir));
        const definitivo = fileURLToPath(new URL('.htaccess', dir));

        const contenuto = await readFile(temporaneo, 'utf8');

        // Un .htaccess non valido fa rispondere 500 ad Apache su TUTTO il
        // sito. Meglio fermare la build che pubblicare un file vuoto o
        // troncato da una risposta interrotta a meta'.
        if (!contenuto.includes('ErrorDocument')) {
          throw new Error('.htaccess generato senza ErrorDocument: risposta incompleta dall\'API.');
        }

        await rename(temporaneo, definitivo);
        await unlink(temporaneo).catch(() => {});

        logger.info(`.htaccess scritto (${contenuto.trim().split('\n').length} righe)`);
      },
    },
  };
}

// Nota: `output: 'hybrid'` (citato nelle specifiche) e' stato RIMOSSO in Astro 7.
// 'static' e' ora il default e si comporta allo stesso modo: prerendering per
// default, con `export const prerender = false` sulle singole pagine dinamiche.
export default defineConfig({
  site: 'https://slimcms.it',
  output: 'static',
  integrations: [htaccessDiSlimcms()],
});
