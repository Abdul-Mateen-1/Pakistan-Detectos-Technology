import { defineConfig } from 'astro/config';

import tailwindcss from '@tailwindcss/vite';
import sitemap from '@astrojs/sitemap';

// https://astro.build/config
// Keep this in sync with noindex={true} usage across src/pages/**/*.astro —
// any path indexable here must not be noindexed in its <Layout>, and vice
// versa, so Search Console never sees a submitted URL that's also noindexed.
// Detail routes (/products/[slug], /brands/[slug], /blog/[slug],
// /categories/[slug]) are prefix-matched since they're dynamic.
const INDEXABLE_EXACT_PATHS = ['/', '/about', '/contact', '/faq'];
const INDEXABLE_PREFIXES = ['/products', '/brands', '/blog', '/categories', '/locations'];

const isIndexable = (path) =>
  INDEXABLE_EXACT_PATHS.includes(path) ||
  INDEXABLE_PREFIXES.some((prefix) => path === prefix || path.startsWith(`${prefix}/`));

export default defineConfig({
  site: 'https://metaldetectors.pk',
  integrations: [
    sitemap({
      filter: (page) => isIndexable(new URL(page).pathname.replace(/\/$/, '') || '/'),
    }),
  ],
  vite: {
    plugins: [tailwindcss()]
  }
});
