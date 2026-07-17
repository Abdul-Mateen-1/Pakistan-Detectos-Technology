import { defineConfig } from 'astro/config';

import tailwindcss from '@tailwindcss/vite';
import sitemap from '@astrojs/sitemap';

// https://astro.build/config
// Only these 6 top-level pages are meant to be indexed (see noindex={true}
// on every other page's <Layout>) — keep the sitemap in sync so Search
// Console never sees a submitted URL that's also marked noindex.
const INDEXABLE_PATHS = ['/', '/about', '/products', '/brands', '/contact', '/blog'];

export default defineConfig({
  site: 'https://metaldetectors.pk',
  integrations: [
    sitemap({
      filter: (page) => INDEXABLE_PATHS.includes(new URL(page).pathname.replace(/\/$/, '') || '/'),
    }),
  ],
  vite: {
    plugins: [tailwindcss()]
  }
});
