// Maintainer-only build for dist/numerosis.js — see package.json's
// description and resources/js/numerosis.js's own docblock. Library mode
// with a single IIFE entry: this ships as a plain <script> tag pointing at
// public/vendor/numerosis/numerosis.js, not as an importable module a
// consumer's own bundler resolves, so there is nothing here for
// laravel-vite-plugin (manifest generation, hot-reload) to do.
import { defineConfig } from 'vite';
import { resolve } from 'node:path';

export default defineConfig({
  build: {
    outDir: 'dist',
    // dist/numerosis.css is built by a separate npm script (see
    // package.json) and must survive this build running after or before it
    // in either order.
    emptyOutDir: false,
    lib: {
      entry: resolve(__dirname, 'resources/js/numerosis.js'),
      // Never referenced — the entry has no exports (it's a side-effecting
      // script, not a module a consumer imports), so Rollup's IIFE output
      // never assigns this name to `window`. Required by Vite's lib-mode
      // validation regardless.
      name: 'NumerosisApp',
      formats: ['iife'],
      fileName: () => 'numerosis.js',
    },
  },
});
