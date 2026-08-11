// Maintainer-only build for dist/numerosis.js — see package.json's
// description and resources/js/numerosis.js's own docblock. Library mode
// with a single IIFE entry: this ships as a plain <script> tag
// (FilamentAsset::register(Js::make(...))), not as an importable module a
// consumer's own bundler resolves, so there is nothing here for
// laravel-vite-plugin (manifest generation, hot-reload) to do.
import { defineConfig } from 'vite';
import { resolve } from 'node:path';

export default defineConfig({
  // Vue's reactivity internals (pulled in transitively by @laravel/echo-vue,
  // even for the plain configureEcho()/echo() functions this file actually
  // uses) branch on a literal `process.env.NODE_ENV`, not
  // `import.meta.env.MODE` — Vite's default replacement covers the latter
  // automatically but not the former, so without this the built bundle
  // throws `ReferenceError: process is not defined` the moment it runs in
  // an actual browser (Node's own global `process` masks this in a quick
  // `node dist/numerosis.js` smoke test — caught only by evaluating the
  // output in a sandbox with no `process` global).
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  build: {
    outDir: 'dist',
    // dist/filament-theme.css and dist/numerosis.css are built by separate
    // npm scripts (see package.json) and must survive this build running
    // after or before them in any order.
    emptyOutDir: false,
    lib: {
      entry: resolve(__dirname, 'resources/js/numerosis.js'),
      // Never referenced — the entry has no exports (it's a side-effecting
      // script, not a module a consumer imports), so Rollup's IIFE output
      // never assigns this name to `window`. Required by Vite's lib-mode
      // validation regardless; deliberately distinct from `window.Numerosis`
      // (resources/views/partials/script-config.blade.php) so a future
      // export added here couldn't silently clobber that config object.
      name: 'NumerosisApp',
      formats: ['iife'],
      fileName: () => 'numerosis.js',
    },
  },
});
