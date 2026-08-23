// @ts-check
import { defineConfig } from 'astro/config';
import mdx from '@astrojs/mdx';

export default defineConfig({
  // The long-term home is docs.bronxconservatory.org; this is the interim domain.
  site: 'https://bcmdocs.lillyrosenthal.org',
  output: 'static',
  integrations: [mdx()],
});
