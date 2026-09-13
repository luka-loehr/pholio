#!/usr/bin/env node
// The search dialog in real browsers, served under a subpath with the site's own headers.
//
// Usage:
//   node verify/search-dialog.mjs [--browsers chromium,firefox,webkit] [--php <bin>]
//
// Builds examples/demo with base_path /docs into a temporary directory, serves it under /docs/
// with the headers of the generated .htaccess (Content-Security-Policy included), and checks in
// each browser, one browser at a time:
//   · Control+K and Meta+K open the dialog, Escape closes it
//   · typing a query renders results, the first being the engine's first page
//   · the index and the worker load from under /docs/
//   · ArrowDown and ArrowUp move the selection, Enter opens the selected result
//   · with the worker script answering 404, the main-thread fallback still answers
//   · no uncaught errors, no Content-Security-Policy violations, no index load warnings
//
// Needs Playwright with its browsers: `npx playwright install chromium-headless-shell firefox webkit`
// in verify/.
//
// Exit codes: 0 all checks pass, 1 a check failed, 2 usage or setup error.

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { resolvePlaywright } from './lib/browser.mjs';
import { createSearch } from '../theme/js/search.js';

const verifyDir = path.dirname(fileURLToPath(import.meta.url));
const pholioRoot = path.resolve(verifyDir, '..');
const BASE = '/docs';
const QUERY = 'callouts';
const BROWSERS = ['chromium', 'firefox', 'webkit'];
const TIMEOUT_MS = 10000;

function usage(message) {
  console.error(`${message}\nUsage: node verify/search-dialog.mjs [--browsers chromium,firefox,webkit] [--php <bin>]`);
  process.exit(2);
}

const argv = process.argv.slice(2);
let browsers = BROWSERS;
let php = 'php';
for (let i = 0; i < argv.length; i++) {
  if (argv[i] === '--browsers') browsers = (argv[++i] ?? usage('Missing value for --browsers')).split(',').filter(Boolean);
  else if (argv[i] === '--php') php = argv[++i] ?? usage('Missing value for --php');
  else usage(`Unknown option: ${argv[i]}`);
}
for (const name of browsers) if (!BROWSERS.includes(name)) usage(`Unknown browser: ${name}`);

const entry = resolvePlaywright();
if (!entry) {
  console.error('Playwright is missing: run `npm ci` in verify/.');
  process.exit(2);
}
const playwright = await import(pathToFileURL(entry).href);

// ---------------------------------------------------------------------- build

const out = fs.mkdtempSync(path.join(os.tmpdir(), 'pholio-search-dialog-'));
process.on('exit', () => fs.rmSync(out, { recursive: true, force: true }));
try {
  execFileSync(php, [
    path.join(pholioRoot, 'bin/pholio'), 'build', '--config', path.join(pholioRoot, 'examples/demo/pholio.config.php'),
    '--out', out, '--set', `base_path=${BASE}`, '--set', 'asset_base=assets/', '--quiet',
  ], { stdio: ['ignore', 'ignore', 'inherit'] });
} catch (err) {
  console.error(`Demo build failed: ${err.message}`);
  process.exit(2);
}

const index = JSON.parse(fs.readFileSync(path.join(out, 'search-index.json'), 'utf8'));
const expected = createSearch(index).rankPages(QUERY)[0];
if (!expected) {
  console.error(`The demo index has no result for ${JSON.stringify(QUERY)}`);
  process.exit(2);
}
const startUrl = index.pages.map((entry) => entry[0]).find((url) => url !== BASE && url !== expected.url) ?? BASE;

// --------------------------------------------------------------------- server

// The headers production gets from the generated .htaccess.
function htaccessHeaders(file) {
  const headers = {};
  if (!fs.existsSync(file)) return headers;
  for (const match of fs.readFileSync(file, 'utf8').matchAll(/^\s*Header always set ([\w-]+) "((?:[^"\\]|\\.)*)"/gm)) {
    headers[match[1]] = match[2];
  }
  return headers;
}

const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.webp': 'image/webp',
  '.woff2': 'font/woff2',
};
const headers = htaccessHeaders(path.join(out, '.htaccess'));
const requests = [];
let blockWorker = false;

const server = http.createServer((req, res) => {
  const { pathname } = new URL(req.url, 'http://localhost');
  requests.push(pathname);
  let found = null;
  if (pathname === BASE || pathname.startsWith(`${BASE}/`)) {
    const target = path.join(out, path.posix.normalize(decodeURIComponent(pathname.slice(BASE.length))));
    if (target.startsWith(out)) {
      found = [target, path.join(target, 'index.html')].find((file) => fs.existsSync(file) && fs.statSync(file).isFile()) ?? null;
    }
  }
  if (found === null || (blockWorker && pathname.endsWith('/search-worker.js'))) {
    res.writeHead(404, { ...headers, 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('not found');
    return;
  }
  res.writeHead(200, { ...headers, 'Content-Type': TYPES[path.extname(found)] ?? 'application/octet-stream' });
  fs.createReadStream(found).pipe(res);
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;

// --------------------------------------------------------------------- checks

let passed = 0;
let failed = 0;
function check(name, ok, detail = '') {
  if (ok) passed++;
  else failed++;
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${!ok && detail ? `\n     ${detail}` : ''}`);
  return ok;
}

// Uncaught errors, CSP violations and the dialog's own load warnings.
function watch(page) {
  const problems = [];
  page.on('pageerror', (error) => problems.push(`uncaught: ${error.message}`));
  page.on('console', (message) => {
    const text = message.text();
    if (/content.security.policy|refused to (load|execute|connect|create)|search index not loaded/i.test(text)) problems.push(text);
  });
  return problems;
}

const ok = (promise) => promise.then(() => true, () => false);

// The dialog moves focus to its input after opening; keys typed before that go elsewhere.
const inputFocused = (page) => ok(page.waitForFunction(
  () => document.activeElement?.matches('[data-fd-search-dialog-input]'),
  null,
  { timeout: TIMEOUT_MS },
));

// Resolves once the dialog has not changed for 300 ms (at most TIMEOUT_MS): the answer for
// the last keystroke is rendered, not only one for an earlier prefix.
const settled = (page) => page.evaluate(({ quietMs, maxMs }) => new Promise((resolve) => {
  const target = document.querySelector('#fd-search-dialog-content') ?? document.body;
  const observer = new MutationObserver(() => {
    clearTimeout(timer);
    timer = setTimeout(done, quietMs);
  });
  let timer = setTimeout(done, quietMs);
  const cap = setTimeout(done, maxMs);
  function done() {
    observer.disconnect();
    clearTimeout(timer);
    clearTimeout(cap);
    // A render already scheduled for the next frame has not touched the DOM yet: let two
    // frames pass (headless WebKit on Linux can hold frames back, so at most one second).
    let resolved = false;
    const finish = () => {
      if (!resolved) resolve();
      resolved = true;
    };
    requestAnimationFrame(() => requestAnimationFrame(finish));
    setTimeout(finish, 1000);
  }
  observer.observe(target, { subtree: true, childList: true, attributes: true, characterData: true });
}), { quietMs: 300, maxMs: TIMEOUT_MS });
const firstTitle = (page, title) => ok(page.waitForFunction(
  (t) => document.querySelector('#fd-search-dialog-content .nd-search-item')?.textContent.includes(t),
  title,
  { timeout: TIMEOUT_MS },
));

async function openAndType(page, name) {
  await page.goto(`${origin}${startUrl}`, { waitUntil: 'load' });
  const input = page.locator('[data-fd-search-dialog-input]');
  await page.keyboard.press('Control+K');
  if (!(await ok(input.waitFor({ state: 'visible', timeout: TIMEOUT_MS })))) return false;
  if (!(await inputFocused(page))) return false;
  await page.keyboard.type(QUERY, { delay: 40 });
  return firstTitle(page, expected.title);
}

async function runBrowser(name) {
  let browser;
  try {
    browser = await playwright[name].launch();
  } catch (err) {
    const install = name === 'chromium' ? 'chromium-headless-shell' : name;
    check(`${name}: launches`, false, `${err.message.split('\n')[0]} (run npx playwright install ${install} in verify/)`);
    return;
  }
  try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const page = await context.newPage();
    const problems = watch(page);
    requests.length = 0;
    await page.goto(`${origin}${startUrl}`, { waitUntil: 'load' });
    const input = page.locator('[data-fd-search-dialog-input]');

    // "K" as a user with Caps Lock, or a test tool, sends it.
    for (const hotkey of ['Control+K', 'Meta+K']) {
      await page.keyboard.press(hotkey);
      const opened = check(`${name}: ${hotkey} opens the search dialog`, await ok(input.waitFor({ state: 'visible', timeout: TIMEOUT_MS })));
      if (!opened) continue;
      check(`${name}: ${hotkey} moves focus to the search input`, await inputFocused(page));
      await page.keyboard.press('Escape');
      check(`${name}: Escape closes it`, await ok(input.waitFor({ state: 'hidden', timeout: TIMEOUT_MS })));
    }

    await page.keyboard.press('Control+K');
    await ok(input.waitFor({ state: 'visible', timeout: TIMEOUT_MS }));
    check(`${name}: reopened, focus is in the search input`, await inputFocused(page));
    await page.keyboard.type(QUERY, { delay: 40 });
    const rendered = check(
      `${name}: typing "${QUERY}" renders results, first "${expected.title}"`,
      await firstTitle(page, expected.title),
      `first row: ${JSON.stringify(await page.locator('#fd-search-dialog-content .nd-search-item').first().textContent({ timeout: 1000 }).catch(() => null))}`,
    );
    check(`${name}: the index loads from ${BASE}/search-index.json`, requests.includes(`${BASE}/search-index.json`),
      `requests: ${requests.filter((r) => r.includes('search')).join(', ') || 'none'}`);
    check(`${name}: the worker loads from under ${BASE}/`, requests.some((r) => r.startsWith(`${BASE}/`) && r.endsWith('/search-worker.js')));

    if (rendered) {
      await settled(page);
      const selected = () => page.evaluate(() => [...document.querySelectorAll('#fd-search-dialog-content .nd-search-item')]
        .findIndex((button) => button.getAttribute('aria-selected') === 'true'));
      // A key press may land while a render waits for the next frame, which then applies the
      // selection: wait for the DOM to show it instead of reading it once.
      const selects = async (index) => ok(page.waitForFunction(
        (i) => [...document.querySelectorAll('#fd-search-dialog-content .nd-search-item')]
          .findIndex((button) => button.getAttribute('aria-selected') === 'true') === i,
        index,
        { timeout: TIMEOUT_MS },
      ));
      const count = await page.locator('#fd-search-dialog-content .nd-search-item').count();
      check(`${name}: the first result starts selected`, await selects(0), `selected: ${await selected()}`);
      await page.keyboard.press('ArrowDown');
      check(`${name}: ArrowDown selects the next result`, await selects(count > 1 ? 1 : 0), `selected: ${await selected()} of ${count}`);
      await page.keyboard.press('ArrowUp');
      check(`${name}: ArrowUp selects the first again`, await selects(0), `selected: ${await selected()}`);
      const navigated = ok(page.waitForURL((url) => new URL(url).pathname === expected.url, { timeout: TIMEOUT_MS }));
      await page.keyboard.press('Enter');
      check(`${name}: Enter opens ${expected.url}`, await navigated, `at ${page.url()}`);
    }
    check(`${name}: no uncaught errors, CSP violations or index warnings`, problems.length === 0, problems.join('\n     '));
    await context.close();

    // The worker script missing: search must fall back to the main thread.
    blockWorker = true;
    const fallback = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const fallbackPage = await fallback.newPage();
    const fallbackProblems = watch(fallbackPage);
    check(`${name}: with search-worker.js answering 404, the main-thread fallback answers`, await openAndType(fallbackPage, name));
    check(`${name}: the fallback raises no uncaught errors or CSP violations`, fallbackProblems.length === 0, fallbackProblems.join('\n     '));
    await fallback.close();
  } finally {
    blockWorker = false;
    await browser.close();
  }
}

try {
  for (const name of browsers) await runBrowser(name);
} finally {
  server.close();
}

console.log(`\n${passed}/${passed + failed} checks passed`);
process.exit(failed === 0 ? 0 : 1);
