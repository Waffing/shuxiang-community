'use strict';
const fs = require('node:fs');
const path = require('node:path');
const zlib = require('node:zlib');
const { minify } = require('terser');
const { transform } = require('lightningcss');

(async () => {
  const root = path.join(__dirname, '..', 'app', 'public');
  const assets = path.join(root, 'assets');
  const js = await minify(fs.readFileSync(path.join(assets, 'app.js'), 'utf8'));
  const css = transform({ filename: 'styles.css', code: fs.readFileSync(path.join(assets, 'styles.css')), minify: true });
  for (const [name, data] of [['app.min.js', Buffer.from(js.code)], ['styles.min.css', css.code]]) {
    const compressed = zlib.brotliCompressSync(data, { params: { [zlib.constants.BROTLI_PARAM_QUALITY]: 11 } });
    if (!zlib.brotliDecompressSync(compressed).equals(data)) throw new Error(`Brotli mismatch: ${name}`);
    fs.writeFileSync(path.join(assets, name), data);
    fs.writeFileSync(path.join(assets, `${name}.br`), compressed);
    console.log(`${name}: ${data.length} bytes; Brotli ${compressed.length} bytes`);
  }
  const index = fs.readFileSync(path.join(root, 'index.php'), 'utf8');
  const sw = fs.readFileSync(path.join(root, 'sw.js'), 'utf8');
  for (const asset of ['app.min.js', 'styles.min.css']) {
    const reference = index.match(new RegExp(`/assets/${asset.replaceAll('.', '\\.')}\\?v=[A-Za-z0-9-]+`))?.[0];
    if (!reference || !sw.includes(reference)) throw new Error(`Cache version mismatch: ${asset}`);
  }
  console.log('Frontend artifacts and cache versions verified');
})().catch(error => { console.error(error); process.exitCode = 1; });
