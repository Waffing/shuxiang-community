const CACHE_NAME = 'shuxiang-shell-v7';
const APP_SHELL = [
  '/',
  '/index.php',
  '/offline.html',
  '/manifest.json',
  '/app-update.json',
  '/assets/styles.min.css?v=20260728-2',
  '/assets/app.min.js?v=20260728-2',
  '/assets/icon.svg'
];
const MAX_NAVIGATION_BYTES = 2 * 1024 * 1024;

function isDownloadRequest(request, url) {
  return url.pathname === '/download.php' ||
    url.pathname.startsWith('/downloads/') ||
    request.headers.has('range');
}

function isCacheableNavigation(request, response) {
  if (!response.ok || request.headers.has('range')) return false;
  const contentType = response.headers.get('content-type') || '';
  const disposition = response.headers.get('content-disposition') || '';
  const cacheControl = response.headers.get('cache-control') || '';
  const contentRange = response.headers.get('content-range');
  const contentLength = Number(response.headers.get('content-length') || 0);
  return contentType.toLowerCase().includes('text/html') &&
    !disposition.toLowerCase().includes('attachment') &&
    !cacheControl.toLowerCase().includes('no-store') &&
    contentRange === null &&
    (contentLength === 0 || contentLength <= MAX_NAVIGATION_BYTES);
}

async function rebuildShellCache() {
  const keys = await caches.keys();
  await Promise.all(keys.map((key) => caches.delete(key)));
  const cache = await caches.open(CACHE_NAME);
  await cache.addAll(APP_SHELL);
}

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('message', (event) => {
  if (event.data?.type !== 'CLEAR_CACHES') return;
  event.waitUntil(rebuildShellCache().then(() => {
    event.ports[0]?.postMessage({ ok: true });
  }));
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin || isDownloadRequest(request, url)) return;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then(async (response) => {
          if (isCacheableNavigation(request, response)) {
            const cache = await caches.open(CACHE_NAME);
            await cache.put('/index.php', response.clone());
          }
          return response;
        })
        .catch(async (error) => {
          console.error('[PWA] Navigation failed, using offline content.', error);
          return (await caches.match('/index.php')) || caches.match('/offline.html');
        })
    );
    return;
  }

  event.respondWith(caches.match(request).then((cached) => cached || fetch(request)));
});
