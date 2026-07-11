// @ts-check
import { defineConfig } from 'astro/config';

import tailwindcss from '@tailwindcss/vite';

// TODO: switch site/base to the custom domain once the client confirms one
// once DNS is pointed at this repo, and drop `base`.
export default defineConfig({
  site: 'https://keegooroomie.github.io',
  base: '/pest-control-landing',
  vite: {
    plugins: [tailwindcss()]
  }
});