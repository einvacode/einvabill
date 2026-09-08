/* EinvaBill service worker.
 *
 * Rules, in order of importance for a billing app:
 *   1. Never answer a page from the cache. A stale invoice, balance or customer
 *      list is worse than an honest "no connection" notice, and on a shared
 *      phone it could show one user's data to the next.
 *   2. Never touch anything that is not a plain GET, so payments, edits and the
 *      WhatsApp proxy always go straight to the server.
 *   3. Cache only the shell: stylesheets, icons and the offline notice, so an
 *      installed app still opens and explains itself without signal.
 *
 * Bump VERSION whenever the shell files change; the old cache is dropped on
 * activate.
 */
const VERSION = 'einvabill-shell-v1';

const SHELL = [
  'offline.html',
  'public/style.css',
  'public/ui.css',
  'public/tw-app.css',
  'public/icons/icon-192.png',
  'public/icons/icon-512.png',
  'public/icons/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(VERSION)
      // addAll fails the whole install if a single file 404s, so add them one by one.
      .then((cache) => Promise.all(SHELL.map((url) => cache.add(url).catch(() => {}))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return; // fonts and CDNs keep their own rules

  // Pages: straight to the network, with the offline notice as the only fallback.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match('offline.html', { ignoreSearch: true }))
    );
    return;
  }

  // Shell assets: serve fast from the cache, refresh in the background.
  const isShellAsset = /\/public\/(icons\/[^/]+\.png|[^/]+\.css)$/.test(url.pathname);
  if (isShellAsset) {
    event.respondWith(
      caches.open(VERSION).then((cache) =>
        cache.match(req).then((hit) => {
          const live = fetch(req)
            .then((res) => {
              if (res && res.ok) cache.put(req, res.clone());
              return res;
            })
            .catch(() => hit);
          return hit || live;
        })
      )
    );
    return;
  }

  // Everything else — PHP endpoints, uploads, receipts — is never cached.
});
