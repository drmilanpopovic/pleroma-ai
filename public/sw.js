/* Cache only the public application shell. No API responses, patient records,
 * authentication responses, uploads or Google callbacks belong in this cache.
 * Bump VERSION with every release of the public assets. New workers wait until
 * all existing app windows close; an unfinished clinical draft is never reloaded.
 */
const VERSION = '1.0.1';
const PREFIX = 'pleroma-shell:' + self.registration.scope + ':';
const CACHE = PREFIX + VERSION;
const FILES = [
  'index.html', 'assets/app.css', 'assets/app.js', 'assets/catalog.js',
  'assets/icon.svg', 'assets/icon-192.png', 'assets/icon-512.png',
  'assets/icon-maskable-512.png', 'manifest.webmanifest'
];
const SHELL = new URL('index.html', self.registration.scope).href;
const STATIC_URLS = new Set(FILES.map(path => new URL(path, self.registration.scope).href));
const ENTRY_PATHS = new Set([
  new URL(self.registration.scope).pathname,
  new URL(SHELL).pathname
]);

self.addEventListener('install', event => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE);
    try {
      await Promise.all([...STATIC_URLS].map(async url => {
        // Omit session cookies while obtaining the public, identical app shell.
        const response = await fetch(new Request(url, {cache: 'reload', credentials: 'omit'}));
        if (!response.ok || response.redirected || response.type !== 'basic') {
          throw new Error('Public application shell unavailable');
        }
        await cache.put(url, response);
      }));
    } catch (error) {
      await caches.delete(CACHE);
      throw error;
    }
  })());
});

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter(key => key.startsWith(PREFIX) && key !== CACHE)
      .map(key => caches.delete(key)));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== 'GET' || url.origin !== self.location.origin) return;

  // Exact allowlist: no query strings, API endpoints or arbitrary navigations
  // may enter Cache Storage through this worker.
  if (request.mode === 'navigate' && ENTRY_PATHS.has(url.pathname)) {
    event.respondWith((async () => {
      try {
        return await fetch(request, {cache: 'no-store'});
      } catch {
        const shell = await (await caches.open(CACHE)).match(SHELL);
        return shell || new Response('Pleroma nije dostupna. Povežite uređaj sa internetom i pokušajte ponovo.', {
          status: 503, headers: {'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store'}
        });
      }
    })());
    return;
  }
  if (!STATIC_URLS.has(url.href)) return;
  event.respondWith((async () => {
    const cached = await (await caches.open(CACHE)).match(url.href);
    return cached || fetch(request, {cache: 'no-store'});
  })());
});
