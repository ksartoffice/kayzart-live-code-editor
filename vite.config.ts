import { defineConfig } from 'vite';
import { fileURLToPath } from 'url';
/* `wp i18n make-pot` reads translatable strings out of built JS, but it cannot
   parse .tsx and only recognizes bare __() calls — and the shipped bundle turns
   every call into `wp.i18n.__()` while inlining dependencies whose syntax the
   extractor chokes on. Both leave the React UI untranslated.

   The `i18n` mode writes an extraction-only bundle to the same path: this
   project's own modules with their __() calls intact, and every package kept
   external so no third-party code reaches the parser. The path must stay
   assets/dist/main.js because WP derives the script-translation filename from
   the md5 of the enqueued path. `npm run i18n:build` rebuilds the shipped
   bundle afterwards. */
export default defineConfig(({ mode }) => ({
  root: '.',
  base: '',
  resolve: {
    alias: {
      lucide: fileURLToPath(
        new URL('./node_modules/lucide/dist/esm/lucide/src/lucide.js', import.meta.url)
      ),
    },
  },
  build: {
    outDir: 'assets/dist',
    assetsDir: '',
    emptyOutDir: true,
    target: 'es2020',
    sourcemap: true,
    cssCodeSplit: false, 
    rollupOptions: {
      input: 'src/admin/main.ts',
      external: mode === 'i18n'
        ? (id: string, importer: string | undefined) => Boolean(importer) && !id.startsWith('\0')
          && (id.includes('node_modules') || !/^[./]|^[A-Za-z]:/.test(id))
        : ['@wordpress/element', '@wordpress/i18n'],
      output: {
        entryFileNames: 'main.js',
        format: mode === 'i18n' ? 'es' : 'iife',
        inlineDynamicImports: true,
        globals: {
          '@wordpress/element': 'wp.element',
          '@wordpress/i18n': 'wp.i18n',
        },
        assetFileNames: (assetInfo) => {
          if (assetInfo.name && assetInfo.name.endsWith('.css')) return 'style.css';
          return '[name][extname]';
        },
      }
    }
  }
}));
