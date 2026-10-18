// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

import tailwindcss from '@tailwindcss/vite';

// Адрес сайта и подпапка берутся из переменных окружения, чтобы переезд на свой домен
// не требовал правки кода: в GitHub → Settings → Secrets and variables → Actions → Variables
// задать SITE_URL (например https://example.kz) и SITE_BASE (для корня домена — "/").
// Без переменных используется временный адрес на github.io.
// При переезде также нужен файл public/CNAME с доменом (см. README → "Свой домен").
const site = process.env.SITE_URL || 'https://keegooroomie.github.io';
const base = process.env.SITE_BASE || '/pest-control-landing';

export default defineConfig({
  site,
  base,
  integrations: [
    sitemap({
      // Только канонические адреса со слэшем на конце; страницу 404 в карту сайта не включаем.
      filter: (page) => page.endsWith('/') && !page.includes('/404'),
    }),
  ],
  vite: {
    plugins: [tailwindcss()]
  }
});
