#!/usr/bin/env node
// Runs a real service worker on loopback with synthetic API data only.
const assert = require('node:assert/strict');
const {readFile, mkdir, writeFile} = require('node:fs/promises');
const {createServer} = require('node:http');
const {extname, join, resolve, sep} = require('node:path');

let chromium;
for (const source of ['playwright', ...['PLEROMA_NODE_MODULES', 'CODEX_PRIMARY_RUNTIME_NODE_MODULES']
  .map(key => process.env[key]).filter(Boolean).map(root => join(root, 'playwright'))]) {
  try { ({chromium} = require(source)); break; } catch (error) {
    if (error.code !== 'MODULE_NOT_FOUND') throw error;
  }
}
if (!chromium) throw new Error('Install Playwright or set PLEROMA_NODE_MODULES to its node_modules directory.');

const project = resolve(__dirname, '..');
const publicDir = join(project, 'public');
const marker = 'PRIVATE_RECORD_CACHE_CHECK_5f306e';
const mime = {'.html':'text/html', '.js':'application/javascript', '.css':'text/css',
  '.png':'image/png', '.svg':'image/svg+xml', '.webmanifest':'application/manifest+json'};
let checks = 0;
function check(value, label) { assert.ok(value, label); console.log(`ok ${++checks} - ${label}`); }

async function main() {
const server = createServer(async (request, response) => {
  const url = new URL(request.url, 'http://localhost');
  if (url.pathname === '/api.php') {
    response.writeHead(200, {'Content-Type':'application/json', 'Cache-Control':'no-store'});
    response.end(JSON.stringify(url.searchParams.get('action') === 'session'
      ? {ok:true, loggedIn:false, needsSetup:false, csrf:'synthetic-test-token'}
      : {ok:true, patient:marker}));
    return;
  }
  const target = resolve(publicDir, url.pathname === '/' ? 'index.html' : url.pathname.slice(1));
  if (!target.startsWith(publicDir + sep)) { response.writeHead(403); response.end(); return; }
  try {
    const data = await readFile(target);
    response.writeHead(200, {'Content-Type':mime[extname(target)] || 'application/octet-stream', 'Cache-Control':'no-store'});
    response.end(data);
  } catch { response.writeHead(404); response.end(); }
});
await new Promise((resolveListening, reject) => {
  server.once('error', reject);
  server.listen(0, '127.0.0.1', resolveListening);
});
const origin = `http://127.0.0.1:${server.address().port}`;
const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE;
let browser;
try {
  browser = await chromium.launch({headless:true, ...(executablePath ? {executablePath,
    args:['--no-sandbox', '--disable-dev-shm-usage', '--single-process', '--no-zygote', '--use-gl=angle', '--use-angle=swiftshader']} : {})});
  const context = await browser.newContext({viewport:{width:412, height:915}, isMobile:true, hasTouch:true});
  const page = await context.newPage();
  page.setDefaultTimeout(10000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(origin);
  await page.waitForSelector('#login-form');
  await page.waitForFunction(async () => {
    const registration = await navigator.serviceWorker.getRegistration();
    return !!registration?.active && !!navigator.serviceWorker.controller;
  });
  check(true, 'service worker activates on loopback and controls the page');

  const manifest = JSON.parse(await readFile(join(publicDir, 'manifest.webmanifest'), 'utf8'));
  check(manifest.display === 'standalone' && manifest.icons.some(icon => icon.purpose === 'maskable'),
    'manifest provides standalone mode and a dedicated Android maskable icon');
  for (const size of [192, 512]) {
    const icon = manifest.icons.find(item => item.sizes === `${size}x${size}` && item.purpose === 'any');
    const bytes = await readFile(join(publicDir, icon.src));
    check(bytes.subarray(1,4).toString() === 'PNG' && bytes.readUInt32BE(16) === size && bytes.readUInt32BE(20) === size,
      `${size}px install icon is a PNG with the declared dimensions`);
  }
  const data = await page.evaluate(() => fetch('api.php?action=state').then(response => response.json()));
  check(data.patient === marker, 'synthetic private response is reachable online');
  const cache = await page.evaluate(async privateMarker => {
    const names = await caches.keys(), entries = [];
    for (const name of names) {
      const storage = await caches.open(name);
      for (const request of await storage.keys()) {
        const response = await storage.match(request);
        entries.push({url:request.url, leak:(await response.text()).includes(privateMarker)});
      }
    }
    return {names, entries};
  }, marker);
  const allowedPaths = new Set(['/index.html', '/assets/app.css', '/assets/app.js', '/assets/catalog.js',
    '/assets/icon.svg', '/assets/icon-192.png', '/assets/icon-512.png', '/assets/icon-maskable-512.png', '/manifest.webmanifest']);
  check(cache.names.length === 1 && cache.entries.length === allowedPaths.size && cache.entries.every(entry => {
    const url = new URL(entry.url);
    return allowedPaths.has(url.pathname) && !url.search && !entry.leak;
  }), 'Cache Storage contains only the public shell and no private API data');

  await context.setOffline(true);
  await page.reload();
  await page.waitForSelector('#login-form');
  check(!(await page.locator('body').textContent()).includes(marker), 'offline reload opens a login shell without patient data');
  for (const target of ['api.php?action=state', 'google-callback.php?code=synthetic', 'assets/app.js?patient=synthetic']) {
    const blocked = await page.evaluate(url => fetch(url).then(() => false, () => true), target);
    check(blocked, `${target.split('?')[0]} cannot be read from an offline cache`);
  }
  check(errors.length === 0, 'online and offline shells have no uncaught JavaScript errors');
  const report = {checks, cachedFiles:cache.entries.map(entry => new URL(entry.url).pathname),
    privateDataCached:false, offlineShell:true, uncaughtErrors:errors};
  if (process.env.PLEROMA_PWA_OUTPUT) {
    const output = resolve(process.env.PLEROMA_PWA_OUTPUT);
    await mkdir(output, {recursive:true});
    await writeFile(join(output, 'pwa-report.json'), JSON.stringify(report, null, 2));
    await page.screenshot({path:join(output, 'android-offline.png')});
  }
  console.log(`PWA: ${checks} checks passed.`);
} finally {
  if (browser) await browser.close();
  server.closeAllConnections();
  await new Promise(resolveClosed => server.close(resolveClosed));
}
}
main().catch(error => { console.error(error); process.exitCode = 1; });
