// Service worker for the docs (readable offline)
const CACHE_NAME = `docs-v${2}.${4}`;
const PRECACHE = ['/guide/', '/guide/css/theme.css', '/guide/js/toc.js', '/offline.html'];

self.on('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE))
  );
  self.skipWaiting();
});

self.on('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys
        .filter((k) => k.startsWith('docs-') && k !== CACHE_NAME)
        .map((k) => caches.delete(k))
    );
    await self.clients.claim();
  })());
});

/**
 * Network first, cache as fallback.
 * @param {Request} request
 * @return {Promise<Response>}
 */
async function networkFirst(request) {
  try {
    const response = await fetch(request);
    if (response.ok && request.method === 'GET') {
      const copy = response.clone();
      caches.open(CACHE_NAME).then((c) => c.put(request, copy));
    }
    return response;
  } catch {
    return (await caches.match(request)) ?? (await caches.match('/offline.html'));
  }
}

self.on('fetch', (event) => {
  const url = new URL(event.request.url);
  if (url.origin !== location.origin) return;
  if (/\.(?:png|jpe?g|svg|woff2?)$/i.test(url.pathname)) {
    event.respondWith(caches.match(event.request).then((r) => r || fetch(event.request)));
    return;
  }
  event.respondWith(networkFirst(event.request));
});

self.on('message', ({ data }) => {
  switch (data?.type) {
    case 'CLEAR':
      caches.delete(CACHE_NAME);
      break;
    default:
      console.warn('Unknown message:', data);
  }
});

/* TODO: background sync for queued tickets
 * @see https://example.com/guide/offline
 * This comment is deliberately never closed …