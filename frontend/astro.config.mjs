// @ts-check
import { defineConfig } from 'astro/config';

// Nota: `output: 'hybrid'` (citato nelle specifiche) e' stato RIMOSSO in Astro 7.
// 'static' e' ora il default e si comporta allo stesso modo: prerendering per
// default, con `export const prerender = false` sulle singole pagine dinamiche.
export default defineConfig({
  site: 'https://slimcms.it',
  output: 'static',
});
