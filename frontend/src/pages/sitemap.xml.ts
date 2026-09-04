import type { APIRoute } from 'astro';
import { sitemap } from '../lib/api';

/**
 * sitemap.xml generata in build dai dati dell'API: solo pagine pubblicate
 * e non escluse dai motori (il filtro noindex lo applica Laravel).
 */
export const GET: APIRoute = async () => {
  const dati = await sitemap();

  const url = dati.urls
    .map(
      (u) =>
        `  <url>\n    <loc>${u.loc}</loc>\n` +
        (u.lastmod ? `    <lastmod>${u.lastmod}</lastmod>\n` : '') +
        `    <changefreq>${u.changefreq}</changefreq>\n    <priority>${u.priority}</priority>\n  </url>`
    )
    .join('\n');

  return new Response(
    `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${url}\n</urlset>\n`,
    { headers: { 'Content-Type': 'application/xml' } }
  );
};
