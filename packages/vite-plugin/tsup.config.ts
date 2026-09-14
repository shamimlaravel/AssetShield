import { defineConfig } from 'tsup';

export default defineConfig({
    entry: ['src/index.ts'],
    format: ['esm', 'cjs'],
    dts: true,
    clean: true,
    sourcemap: false,
    minify: false,
    target: 'node18',
    outDir: 'dist',
    // Loaded lazily at runtime via dynamic import; the optional peer must
    // stay external so it resolves from the consumer's node_modules (and so
    // the multi-megabyte CJS bundle is not inlined into our dist).
    external: ['javascript-obfuscator'],
    esbuildOptions(options, context) {
        // The subprocess engine only touches import.meta.url inside a
        // `typeof __filename !== 'undefined'` guard, so the empty-import-meta
        // CJS warning is a false positive for our build.
        options.logOverride = { 'empty-import-meta': 'silent' };
    },
});