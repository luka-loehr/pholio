// Playwright wrapper for the verify tools: always the same browser, the same waiting
// time, the same theme setting on both sides.
//
// Theme: the reference app uses next-themes with the key `theme` in localStorage and
// the values `light` | `dark`; it also sets the class `dark` on <html> and
// `color-scheme`. So that the very first frame is already right, localStorage is written
// by an init script before every document; after loading, the class is forced once more
// so the candidate starts from the same state, however it applies the theme itself.
//
// Playwright resolution, first hit wins:
//
//   1. PHOLIO_PLAYWRIGHT: the playwright package directory or its index.mjs.
//   2. node_modules/playwright in verify/ and in every directory above it. This covers
//      `cd verify && npm ci` in a Pholio checkout as well as a copy of Pholio vendored
//      into another repository whose own node_modules sits further up.
//   3. The directory passed to launchBrowser(), then process.cwd(), each with
//      node_modules/playwright.

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath, pathToFileURL } from 'node:url';

export const DEFAULT_SETTLE_MS = 500;

const VERIFY_DIR = path.resolve(fileURLToPath(new URL('..', import.meta.url)));

function entryIn(dir) {
  return path.join(dir, 'node_modules/playwright/index.mjs');
}

// Candidate entry files in resolution order, without duplicates.
export function playwrightCandidates({ root = null, env = process.env, cwd = process.cwd(), from = VERIFY_DIR } = {}) {
  const out = [];
  if (env.PHOLIO_PLAYWRIGHT) {
    const p = path.resolve(env.PHOLIO_PLAYWRIGHT);
    out.push(p.endsWith('.mjs') ? p : path.join(p, 'index.mjs'));
  }
  for (let dir = path.resolve(from); ; dir = path.dirname(dir)) {
    out.push(entryIn(dir));
    if (path.dirname(dir) === dir) break;
  }
  if (root) out.push(entryIn(path.resolve(root)));
  out.push(entryIn(path.resolve(cwd)));
  return [...new Set(out)];
}

// First existing entry file, or null.
export function resolvePlaywright(options = {}) {
  return playwrightCandidates(options).find((file) => fs.existsSync(file)) ?? null;
}

export async function launchBrowser(root = null) {
  const entry = resolvePlaywright({ root });
  if (!entry) {
    throw new Error(
      'Playwright is missing: run `npm ci` and `npx playwright install chromium-headless-shell` in verify/, or set PHOLIO_PLAYWRIGHT.',
    );
  }
  const { chromium } = await import(pathToFileURL(entry).href);
  return chromium.launch();
}

// One context per page/width/theme, no shared state between comparisons.
export async function openPage(browser, { width, height = 900, theme = 'light', reducedMotion = 'no-preference' }) {
  const context = await browser.newContext({
    viewport: { width, height },
    deviceScaleFactor: 1,
    colorScheme: theme === 'dark' ? 'dark' : 'light',
    reducedMotion,
  });
  await context.addInitScript((t) => {
    try {
      localStorage.setItem('theme', t);
    } catch {
      /* storage may be blocked; the class below is enough then */
    }
  }, theme);
  const page = await context.newPage();
  return { context, page };
}

export async function applyTheme(page, theme) {
  await page.evaluate((t) => {
    const root = document.documentElement;
    root.classList.toggle('dark', t === 'dark');
    root.classList.toggle('light', t === 'light');
    root.style.colorScheme = t;
  }, theme);
}

// Load, let the network go idle, force the theme, wait again.
// The second wait is needed because switching the theme starts transitions.
// A navigation timeout is retried once: a reference dev server shared by several
// verification runs compiles pages on first request, so a timeout says nothing about
// the page. Every other error (HTTP status, dropped connection) is reported at once.
async function gotoWithRetry(page, url) {
  try {
    return await page.goto(url, { waitUntil: 'networkidle' });
  } catch (err) {
    if (err.name !== 'TimeoutError') throw err;
    return page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });
  }
}

export async function loadPage(page, url, { theme = 'light', settle = DEFAULT_SETTLE_MS } = {}) {
  const response = await gotoWithRetry(page, url);
  if (response && response.status() >= 400) throw new Error(`HTTP ${response.status()} for ${url}`);
  await applyTheme(page, theme);
  await page.waitForTimeout(settle);
  return response;
}

// Jump all running animations and transitions to their end and wait until the browser
// is idle. Called before every measurement, so end states are compared and not random
// intermediate frames.
export async function settleAnimations(page, { timeout = 2000 } = {}) {
  await page.evaluate(async (limit) => {
    const started = Date.now();
    while (Date.now() - started < limit) {
      const running = document.getAnimations().filter((a) => a.playState === 'running');
      if (!running.length) break;
      await Promise.race([
        Promise.all(running.map((a) => a.finished.catch(() => {}))),
        new Promise((r) => setTimeout(r, 250)),
      ]);
    }
    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
  }, timeout);
}

// Close a context without endangering the run. If the browser went away earlier (out
// of memory, crashed renderer), close() throws, and an error in the finally branch
// would take every result collected so far down with it.
export async function closeQuietly(context) {
  try {
    await context.close();
  } catch {
    /* the context is already gone; nothing more to do here */
  }
}

// Make every image on the page actually load, decode and paint, before every full-page
// screenshot, identically on both sides.
//
// Background: on long pages with many large screenshots (12,451 px tall, 25 images
// 2880 px wide), single images were missing from the full-page screenshot, sometimes in
// the reference, sometimes in the candidate, although `complete`, `naturalWidth` and
// `decode()` already reported "done" for all of them. Chromium paints tiles outside the
// viewport only while capturing and evicts decoded images from its cache while doing so.
// One quick scroll through wasn't enough (4 of 4 pairs of the reference against itself
// failed). Stopping the scroll at every step until the images visible there are decoded,
// and waiting two frames, rasterises every image in the viewport once; after that,
// 4 of 4 pairs were equal.
//
// Steps: loading=eager for all images, walk the page in viewport heights, decode() the
// visible images at every step and wait two frames, back to the start position, decode()
// all images, fonts.ready, two frames. Returns the images that are neither loaded nor
// decoded after `timeout` milliseconds.
export async function loadAllImages(page, { timeout = 30000 } = {}) {
  return page.evaluate(async (limit) => {
    const frame = () => new Promise((r) => requestAnimationFrame(() => r()));
    const twoFrames = async () => { await frame(); await frame(); };
    const decodeAll = (imgs) => Promise.all(imgs.map((i) => (i.currentSrc || i.getAttribute('src') ? i.decode().catch(() => {}) : null)));

    for (const img of document.images) img.loading = 'eager';

    const before = window.scrollY;
    const step = Math.max(200, window.innerHeight);
    for (let y = 0; y < document.documentElement.scrollHeight + step; y += step) {
      window.scrollTo(0, y);
      await frame();
      const visible = [...document.images].filter((i) => {
        const r = i.getBoundingClientRect();
        return r.bottom > 0 && r.top < window.innerHeight;
      });
      await decodeAll(visible);
      await twoFrames();
    }
    window.scrollTo(0, before);
    await frame();

    const deadline = Date.now() + limit;
    const pending = () => [...document.images].filter((i) => (i.currentSrc || i.getAttribute('src')) && !(i.complete && i.naturalWidth > 0));
    while (pending().length && Date.now() < deadline) {
      await Promise.race([decodeAll(pending()), new Promise((r) => setTimeout(r, 200))]);
    }
    await decodeAll([...document.images]);
    if (document.fonts && document.fonts.ready) await document.fonts.ready;
    await twoFrames();
    return pending().map((i) => i.currentSrc || i.getAttribute('src'));
  }, timeout);
}
