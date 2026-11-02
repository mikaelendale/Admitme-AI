// Builds the unpacked extension into browser-extension/dist (load it from chrome://extensions → "Load unpacked").
// The side panel and service worker are ES modules; the content script must be a classic script, so it is a
// separate IIFE build.
import { copyFile, rm } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import { build } from 'vite';

const here = path.dirname(fileURLToPath(import.meta.url));
const src = path.join(here, 'src');
const dist = path.join(here, 'dist');

await rm(dist, { recursive: true, force: true });

await build({
    configFile: false,
    root: src,
    base: '/',
    publicDir: false,
    logLevel: 'warn',
    plugins: [react()],
    build: {
        outDir: dist,
        emptyOutDir: false,
        rollupOptions: {
            input: {
                sidepanel: path.join(src, 'sidepanel/index.html'),
                background: path.join(src, 'background.ts'),
            },
            output: {
                entryFileNames: (chunk) =>
                    chunk.name === 'background'
                        ? 'background.js'
                        : 'assets/[name]-[hash].js',
            },
        },
    },
});

await build({
    configFile: false,
    root: src,
    publicDir: false,
    logLevel: 'warn',
    build: {
        outDir: dist,
        emptyOutDir: false,
        lib: {
            entry: path.join(src, 'content.ts'),
            formats: ['iife'],
            name: 'AdmitMeContent',
            fileName: () => 'content.js',
        },
    },
});

await copyFile(
    path.join(here, 'manifest.json'),
    path.join(dist, 'manifest.json'),
);

console.log(`Extension built: ${dist}`);
