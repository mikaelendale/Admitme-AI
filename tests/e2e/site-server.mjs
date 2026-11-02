// Tiny static server for synthetic "college website" fixtures on 127.0.0.1:8766.
import { readFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), 'site');

createServer(async (req, res) => {
    const requested = decodeURIComponent(new URL(req.url ?? '/', 'http://fixture').pathname);
    const file = path.resolve(root, '.' + requested);

    // Never serve anything outside the fixture folder.
    if (!file.startsWith(root + path.sep)) {
        res.writeHead(403).end('forbidden');

        return;
    }

    try {
        const body = await readFile(file);
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(body);
    } catch {
        res.writeHead(404).end('not found');
    }
}).listen(8766, '127.0.0.1', () => console.log('fixture site on http://127.0.0.1:8766'));
