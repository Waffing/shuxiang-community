const CACHE_NAME = 'shuxiang-shell-v8';
const CACHE_PREFIX = 'shuxiang-shell-';
const APP_SHELL = ['/', '/index.php', '/offline.html', '/manifest.json', '/assets/styles.min.css?v=20260917-1', '/assets/app.min.js?v=20260917-1', '/assets/icon.svg'];
const MAX_NAVIGATION_BYTES = 2 * 1024 * 1024;

function isDownloadRequest(request, url) {
  return ['/download.php', '/api.php', '/app-update.json', '/seo.php'].includes(url.pathname) ||
    url.pathname.startsWith('/downloads/') || request.headers.has('range');
}

function isCacheableResponse(response) {
  return response.ok && response.status !== 206 &&
    !/attachment/i.test(response.headers.get('content-disposition') || '') &&
    !/no-store|private/i.test(response.headers.get('cache-control') || '') &&
    !response.headers.has('content-range') &&
    Number(response.headers.get('content-length') || 0) <= MAX_NAVIGATION_BYTES;
}

async function putBounded(cache, key, response) {
  if (!isCacheableResponse(response)) return;
  const reader = response.body?.getReader();
  if (!reader) return;
  const chunks = [];
  let bytes = 0;
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    bytes += value.byteLength;
    if (bytes > MAX_NAVIGATION_BYTES) {
      reader.cancel().catch((error) => console.error('[PWA] Cache stream cancel failed.', error));
      return;
    }
    chunks.push(value);
  }
  await cache.put(key, new Response(new Blob(chunks), {
    status: response.status, statusText: response.statusText, headers: response.headers
  }));
}

async function populateShell() {
  const cache = await caches.open(CACHE_NAME);
  await Promise.all(APP_SHELL.map(async (url) => {
    const response = await fetch(url, { cache: 'reload', credentials: 'omit' });
    if (!isCacheableResponse(response)) throw new Error(`Shell response rejected: ${url}`);
    await putBounded(cache, url, response);
  }));
}

async function removeOldShells() {
  const keys = await caches.keys();
  await Promise.all(keys.filter((key) => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME).map((key) => caches.delete(key)));
}

self.addEventListener('install', (event) => {
  event.waitUntil(populateShell().then(() => self.skipWaiting()).catch((error) => {
    console.error('[PWA] Installation failed.', error);
    throw error;
  }));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(removeOldShells().then(() => self.clients.claim()));
});

self.addEventListener('message', (event) => {
  if (event.data?.type !== 'CLEAR_CACHES') return;
  event.waitUntil(caches.keys().then((keys) => Promise.all(
    keys.filter((key) => key.startsWith(CACHE_PREFIX)).map((key) => caches.delete(key))
  )).then(populateShell).then(() => event.ports[0]?.postMessage({ ok: true })).catch((error) => {
    console.error('[PWA] Cache rebuild failed.', error);
    event.ports[0]?.postMessage({ ok: false });
  }));
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin || isDownloadRequest(request, url)) return;
  const isHome = url.pathname === '/' || url.pathname === '/index.php';
  if (request.mode === 'navigate') {
    event.waitUntil(removeOldShells());
    event.respondWith(fetch(request).then((response) => {
      if (isHome && isCacheableResponse(response) && /^text\/html\b/i.test(response.headers.get('content-type') || '')) {
        event.waitUntil(caches.open(CACHE_NAME).then((cache) => putBounded(cache, '/index.php', response.clone()))
          .catch((error) => console.error('[PWA] Navigation cache failed.', error)));
      }
      return response;
    }).catch(async (error) => {
      console.error('[PWA] Navigation failed, using offline content.', error);
      const cache = await caches.open(CACHE_NAME);
      return (isHome && await cache.match('/index.php')) || await cache.match('/offline.html') ||
        new Response('离线内容尚未缓存，请连接网络后重试。', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
    }));
    return;
  }
  if (APP_SHELL.includes(url.pathname + url.search)) {
    event.respondWith(caches.open(CACHE_NAME).then((cache) => cache.match(request)).then((cached) => cached || fetch(request)));
  }
});
