'use strict';
const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const legacyFile = 'app/public/__legacy-worker-test.js';
const origin = 'http://127.0.0.1:18790';

(async () => {
  if (fs.existsSync(legacyFile)) throw new Error('Legacy fixture already exists');
  fs.writeFileSync(legacyFile, fs.readFileSync('tests/fixtures/service-worker-v7.js'));
  const browser = await chromium.launch({ headless: true, args: ['--no-proxy-server'] });
  try {
    const page = await browser.newPage();
    await page.goto(origin+'/offline.html');
    await page.evaluate(async () => {
      await navigator.serviceWorker.register('/__legacy-worker-test.js', { scope: '/' });
      await navigator.serviceWorker.ready;
      await caches.open('unrelated-app-test');
    });
    await page.waitForFunction(() => navigator.serviceWorker.controller?.scriptURL.includes('__legacy-worker-test'));
    assert((await page.evaluate(() => caches.keys())).includes('shuxiang-shell-v7'));
    await page.goto(origin+'/?upgrade=1');
    await page.waitForFunction(() => navigator.serviceWorker.controller?.scriptURL.endsWith('/sw.js'));
    await page.getByRole('checkbox', { name: '我已阅读并同意遵守上述声明' }).check();
    await page.getByRole('button', { name: '同意并进入' }).click();
    await page.locator('#pwa-update:not([hidden])').waitFor();
    await page.locator('#pwa-update-refresh').click();
    await page.waitForLoadState('networkidle');
    await page.waitForFunction(async () => !(await caches.keys()).includes('shuxiang-shell-v7'), null, { timeout: 5000 });
    const keys = await page.evaluate(() => caches.keys());
    assert(keys.includes('shuxiang-shell-v8') && keys.includes('unrelated-app-test'));
    assert(!keys.includes('shuxiang-shell-v7'));
    console.log('Browser PWA upgrade PASS: actual v7 worker to v8; prompt visible; unrelated cache retained');
  } finally {
    await browser.close();
    fs.unlinkSync(legacyFile);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
