'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const listeners = {};
const stores = new Map();
const writes = [];
let failWrites = false;
let fetchImplementation = async () => new Response('<html>shell</html>', { headers: { 'Content-Type': 'text/html' } });
const key = value => typeof value === 'string' ? value : value.url.replace('https://forum.example.com', '');
function cache(name) {
  if (!stores.has(name)) stores.set(name, new Map());
  const entries = stores.get(name);
  return {
    match: async url => entries.get(key(url))?.clone(),
    addAll: async urls => urls.forEach(url => entries.set(url, new Response('<html>shell</html>'))),
    put: async (url, response) => {
      if (failWrites) throw new Error('quota');
      writes.push(key(url)); entries.set(key(url), response);
    }
  };
}
const context = {
  URL, Response, Blob, console: { error: () => {} },
  fetch: (...args) => fetchImplementation(...args),
  caches: {
    open: async name => cache(name), keys: async () => [...stores.keys()],
    delete: async name => stores.delete(name),
    match: async url => {
      for (const entries of stores.values()) if (entries.has(key(url))) return entries.get(key(url)).clone();
    }
  },
  self: { location: { origin: 'https://forum.example.com' }, clients: { claim: async () => {} },
    skipWaiting: () => {}, addEventListener: (type, handler) => { listeners[type] = handler; } }
};
vm.runInNewContext(fs.readFileSync(process.argv[2] || 'app/public/sw.js', 'utf8'), context);
function request(path, headers = {}, mode = 'navigate') {
  return { method: 'GET', mode, url: `https://forum.example.com${path}`, headers: new Headers(headers) };
}
async function dispatch(req) {
  const tasks = [];
  let response;
  listeners.fetch({ request: req, waitUntil: task => tasks.push(task), respondWith: value => { response = value; } });
  const result = response ? await response : null;
  await Promise.all(tasks);
  return result;
}
async function lifetime(type, extra = {}) {
  const tasks = [];
  listeners[type]({ ...extra, waitUntil: task => tasks.push(task) });
  await Promise.all(tasks);
}
(async () => {
  for (const path of ['/download.php?token=test', '/downloads/file.apk', '/api.php?action=me', '/app-update.json']) {
    assert.equal(await dispatch(request(path)), null, `Bypass required: ${path}`);
  }
  assert.equal(await dispatch(request('/file', { Range: 'bytes=0-10' })), null);
  stores.set('unrelated-app-cache', new Map());
  stores.set('shuxiang-shell-old', new Map());
  await lifetime('install');
  await lifetime('activate');
  assert(stores.has('unrelated-app-cache'));
  assert(!stores.has('shuxiang-shell-old'));
  const shell = [...stores.entries()].find(([name]) => name.startsWith('shuxiang-shell-'))[1];
  assert(shell.has('/offline.html'));
  assert(!shell.has('/app-update.json'));
  writes.length = 0;
  fetchImplementation = async () => new Response('<html>home</html>', { headers: { 'Content-Type': 'text/html' } });
  assert.equal(await (await dispatch(request('/'))).text(), '<html>home</html>');
  assert.deepEqual(writes, ['/index.php']);
  for (const response of [
    new Response('binary', { headers: { 'Content-Type': 'application/octet-stream', 'Content-Disposition': 'attachment' } }),
    new Response('<html>private</html>', { headers: { 'Content-Type': 'text/html', 'Cache-Control': 'no-store' } }),
    new Response('partial', { status: 206, headers: { 'Content-Type': 'text/html' } }),
    new Response('error', { status: 500, headers: { 'Content-Type': 'text/html' } }),
    new Response('x'.repeat(2097153), { headers: { 'Content-Type': 'text/html' } }),
    new Response('large', { headers: { 'Content-Type': 'text/html', 'Content-Length': '2097153' } })
  ]) {
    writes.length = 0; fetchImplementation = async () => response.clone();
    await dispatch(request('/')); assert.equal(writes.length, 0);
  }
  writes.length = 0;
  fetchImplementation = async () => new Response('<html>detail</html>', { headers: { 'Content-Type': 'text/html' } });
  await dispatch(request('/software/test'));
  assert.equal(writes.length, 0, 'Detail must not replace offline home');
  failWrites = true;
  assert.equal(await (await dispatch(request('/'))).text(), '<html>detail</html>', 'Cache failure must not replace a valid network response');
  failWrites = false;
  fetchImplementation = async () => { throw new Error('offline'); };
  assert.match(await (await dispatch(request('/'))).text(), /home|shell/);
  assert.match(await (await dispatch(request('/software/test'))).text(), /shell/);
  let cleared;
  fetchImplementation = async () => new Response('<html>shell</html>', { headers: { 'Content-Type': 'text/html' } });
  await lifetime('message', { data: { type: 'CLEAR_CACHES' }, ports: [{ postMessage: result => { cleared = result.ok; } }] });
  assert(cleared && stores.has('unrelated-app-cache'));
  console.log('PWA cache boundary tests passed (18 cases)');
})().catch(error => { console.error(error); process.exitCode = 1; });
