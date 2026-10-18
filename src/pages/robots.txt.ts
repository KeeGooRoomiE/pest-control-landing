import type { APIRoute } from "astro";

// robots.txt строится от `site` из astro.config.mjs, поэтому при смене домена
// ссылка на sitemap обновится сама.
export const GET: APIRoute = ({ site }) => {
  const sitemap = new URL("sitemap-index.xml", new URL(import.meta.env.BASE_URL.replace(/\/?$/, "/"), site));
  const body = `User-agent: *\nAllow: /\n\nSitemap: ${sitemap.href}\n`;
  return new Response(body, { headers: { "Content-Type": "text/plain; charset=utf-8" } });
};
