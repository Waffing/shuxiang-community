'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../app/public/assets/app.js'), 'utf8').replace(/\ninit\(\);\s*$/, '');
const context = vm.createContext({
  navigator: { userAgent: 'test' },
  document: { body: { dataset: {} } },
  location: { origin: 'https://community.test', pathname: '/', hash: '' },
  URL, URLSearchParams, AbortController, Intl, console,
  fetch: async () => ({ ok: false, status: 401, json: async () => ({ error: 'invalid_credentials' }) })
});
vm.runInContext(source, context);

(async () => {
  assert.equal(vm.runInContext('formatNumber(null)', context), '暂无数据');
  assert.equal(vm.runInContext('formatNumber(0)', context), '0');
  assert.equal(vm.runInContext('formatNumber(undefined)', context), '暂无数据');
  await assert.rejects(vm.runInContext("api('login')", context), { message: '用户名或密码不正确，请重试。' });
  await assert.rejects(vm.runInContext("api('change_password')", context), { message: '当前密码不正确，请重新输入。' });
  const card = vm.runInContext("resourceCard({id: 9, slug: 'real-tool', status: 'published', name: '<svg onload=1>', downloads: 0})", context);
  assert.match(card, /href="\/software\/real-tool"/);
  assert.ok(!card.includes('<svg onload=1>'));
  const draft = vm.runInContext("resourceCard({id: 9, slug: 'real-tool', status: 'draft', name: 'draft'})", context);
  assert.match(draft, /href="#\/detail\/9"/);
  const pages = vm.runInContext("paginationMarkup(2, 5, 'my-page')", context);
  assert.match(pages, /data-my-page="3"/);
  assert.match(pages, /aria-current="page"/);
  context.document.body.dataset.resourceId = '9';
  context.location.pathname = '/software/real-tool';
  assert.equal(vm.runInContext('currentRoute().id', context), '9');
  context.location.pathname = '/platform/android';
  context.document.body.dataset.platform = 'android';
  assert.equal(vm.runInContext('currentRoute().page', context), 'search');
  assert.equal(vm.runInContext('filtersFromUrl().platform[0]', context), 'android');
  context.document.body.dataset.category = '效率';
  assert.equal(vm.runInContext('filtersFromUrl().category', context), '效率');
  const preview = vm.runInContext("queueCard({id: 9, name: 'Pending', status: 'draft', author: 'User'}, 'software')", context);
  assert.match(preview, /data-status="published"/);
  assert.match(preview, /data-status="draft"/);
  assert.match(preview, /name="reason"/);
  const nodes = Object.fromEntries(['#latest-list', '#weekly-ranking', '#search-results', '#results-title', '#pagination'].map((selector) => [selector, { innerHTML: '', textContent: '' }]));
  context.document.querySelector = (selector) => nodes[selector];
  context.api = async (action) => {
    if (action === 'list') throw new Error('网络连接失败，请检查网络后重试。');
    return { weekly: [], latest: [] };
  };
  await vm.runInContext('loadHome()', context);
  assert.match(nodes['#latest-list'].innerHTML, /重新加载/);
  assert.ok(!nodes['#latest-list'].innerHTML.includes('暂无最新资源'));
  context.api = async () => ({ items: [], weekly: [], latest: [] });
  await vm.runInContext('loadHome()', context);
  assert.match(nodes['#latest-list'].innerHTML, /暂无最新资源/);
  context.location.hash = '#/search?page=2';
  context.applyFiltersToForm = () => {};
  context.api = async () => ({ items: [], page: 2, total: 99, total_pages: 9 });
  await vm.runInContext('loadSearch()', context);
  assert.equal(nodes['#results-title'].textContent, '找到 99 个资源');
  assert.match(nodes['#pagination'].innerHTML, /data-page="9"/);
  assert.equal(vm.runInContext("resourceStatus('draft', '请补充版本')", context), '需修改');
  console.log('Frontend release behavior tests passed');
})().catch((error) => { console.error(error); process.exitCode = 1; });
