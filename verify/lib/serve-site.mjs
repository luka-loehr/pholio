#!/usr/bin/env node
// Static server for a built Pholio site.
//
//   node verify/lib/serve-site.mjs --root .build/site --port 4177
//   node verify/lib/serve-site.mjs --root .build/site --port 4177 --origin http://localhost:3000
//
// It does exactly two things:
//
//   1. It serves the directory `pholio build` wrote. `/x/y/` and `/x/y` become
//      `<root>/x/y/index.html`, everything else the file of the same name. Nothing is
//      injected or rewritten: the page links its stylesheet itself, and that link is
//      exactly what should be checked.
//   2. Only with `--origin`: whatever doesn't exist under `<root>` is fetched from that
//      origin, for example images or brand assets that live only in the reference app
//      and aren't copied into the build. Without `--origin`, missing files are 404.
//
// This lets the candidate be measured with the same tools as the reference:
// computed-style.mjs and pixel-diff.mjs get two origins and nothing else. The server
// keeps nothing in memory and exits on SIGINT.
//
// Without --port it picks a free port and prints the URL on stdout.
// Imported as a module (`startSite`), it returns { url, port, close }.

import fs from 'node:fs/promises';
import http from 'node:http';
import path from 'node:path';
import process from 'node:process';

const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.avif': 'image/avif',
  '.ico': 'image/x-icon',
  '.woff2': 'font/woff2',
  '.woff': 'font/woff',
  '.ttf': 'font/ttf',
  '.txt': 'text/plain; charset=utf-8',
  '.pdf': 'application/pdf',
};

/** Candidate file system paths for an address, in lookup order. */
function candidates(root, pathname) {
  const clean = path.posix.normalize(decodeURIComponent(pathname)).replace(/^\/+/, '');
  // No escaping from the root directory.
  if (clean.startsWith('..')) return [];
  const base = path.join(root, clean);
  if (clean === '' || clean.endsWith('/')) return [path.join(base, 'index.html')];

  return [base, path.join(base, 'index.html')];
}

async function readFileIfAny(file) {
  try {
    const stat = await fs.stat(file);
    if (!stat.isFile()) return null;

    return await fs.readFile(file);
  } catch {
    return null;
  }
}

/**
 * Start the server.
 *
 * @param {{root: string, port?: number, origin?: string}} options
 * @returns {Promise<{url: string, port: number, close: () => Promise<void>}>}
 */
export async function startSite({ root, port = 0, origin = null }) {
  const absoluteRoot = path.resolve(root);

  const server = http.createServer(async (request, response) => {
    const url = new URL(request.url ?? '/', 'http://localhost');

    for (const file of candidates(absoluteRoot, url.pathname)) {
      const body = await readFileIfAny(file);
      if (body === null) continue;
      response.writeHead(200, {
        'content-type': TYPES[path.extname(file).toLowerCase()] ?? 'application/octet-stream',
        'cache-control': 'no-store',
      });
      response.end(body);

      return;
    }

    // Not part of the build: fetch it from the origin, if one is configured.
    if (origin) {
      try {
        const upstream = await fetch(new URL(url.pathname + url.search, origin), {
          headers: { accept: request.headers.accept ?? '*/*' },
        });
        const buffer = Buffer.from(await upstream.arrayBuffer());
        response.writeHead(upstream.status, {
          'content-type': upstream.headers.get('content-type') ?? 'application/octet-stream',
          'cache-control': 'no-store',
        });
        response.end(buffer);

        return;
      } catch {
        // falls through to 404
      }
    }

    response.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' });
    response.end('404 ' + url.pathname);
  });

  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(port, '127.0.0.1', resolve);
  });
  const address = server.address();
  const actual = typeof address === 'object' && address !== null ? address.port : port;

  return {
    url: `http://127.0.0.1:${actual}`,
    port: actual,
    close: () => new Promise((resolve) => server.close(() => resolve())),
  };
}

// ---- as a command ---------------------------------------------------------

const isMain = process.argv[1] && process.argv[1].endsWith('serve-site.mjs');
if (isMain) {
  const arg = (name, fallback) => {
    const i = process.argv.indexOf(`--${name}`);

    return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
  };
  const root = arg('root', '');
  if (!root) {
    console.error('Missing: --root <directory of the built site>');
    process.exit(2);
  }
  const site = await startSite({
    root,
    port: Number(arg('port', '0')),
    origin: arg('origin', null),
  });
  console.log(site.url);
  process.on('SIGINT', async () => {
    await site.close();
    process.exit(0);
  });
}
