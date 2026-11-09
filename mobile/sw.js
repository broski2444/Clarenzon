/* Clarenzon Warehouse mobile app — cache static assets only. Never cache API or POSTs. */
const VERSION = 'clarenzon-mobile-v6';
const PRECACHE = [
  './assets/app.css',
  './assets/app.js',
  './assets/scanner.js',
  './assets/pwa.js',
  './assets/icon-192.png',
  './assets/icon-512.png',
  './offline.html'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(VERSION).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') {
    return;
  }
  const url = new URL(req.url);
  if (url.pathname.indexOf('api.php') !== -1 || url.pathname.indexOf('logout.php') !== -1) {
    return;
  }

  const isAsset = /\/mobile\/assets\//.test(url.pathname)
    || /manifest\.(webmanifest|json)$/.test(url.pathname)
    || /\/mobile\/offline\.html$/.test(url.pathname);

  if (isAsset) {
    event.respondWith(
      fetch(req).then((res) => {
        if (res && res.ok) {
          const copy = res.clone();
          caches.open(VERSION).then((cache) => cache.put(req, copy));
        }
        return res;
      }).catch(() => caches.match(req))
    );
    return;
  }

  const wantsHtml = req.mode === 'navigate'
    || (req.headers.get('accept') || '').indexOf('text/html') !== -1;
  if (wantsHtml) {
    event.respondWith(
      fetch(req).catch(() => caches.match('./offline.html'))
    );
  }
});
