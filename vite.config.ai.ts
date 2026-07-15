import { defineConfig } from 'vite';

export default defineConfig({
  base: '',
  build: {
    outDir: 'assets/dist',
    assetsDir: '',
    emptyOutDir: false,
    target: 'es2020',
    sourcemap: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: 'src/editor-ai/main.tsx',
      external: ['@wordpress/element', '@wordpress/i18n'],
      output: {
        entryFileNames: 'ai-editor.js',
        format: 'iife',
        inlineDynamicImports: true,
        globals: {
          '@wordpress/element': 'wp.element',
          '@wordpress/i18n': 'wp.i18n',
        },
        assetFileNames: (assetInfo) =>
          assetInfo.name?.endsWith('.css') ? 'ai-editor.css' : '[name][extname]',
      },
    },
  },
});
