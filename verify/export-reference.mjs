#!/usr/bin/env node
// Reference export of a Fumadocs app: freezes what Pholio is compared against.
//
//   node verify/export-reference.mjs --lab-dir <app dir> --lab-url http://127.0.0.1:3000
//   node verify/export-reference.mjs --lab-dir <app dir> --lab-url <url> --preset notebook --out <dir>
//   node verify/export-reference.mjs --lab-dir <app dir> --lab-url <url> --queries verify/fixtures/demo/queries.json \
//        --brand brand/app-icon.webp --brand images/logo.png
//   PHOLIO_LAB_DIR=<app dir> PHOLIO_LAB_URL=<url> node verify/export-reference.mjs --help
//
// Reference app folder: --lab-dir, otherwise PHOLIO_LAB_DIR. App URL: --lab-url, otherwise
// PHOLIO_LAB_URL. There are no defaults; if the folder doesn't exist or the URL is missing,
// the script exits with code 2.
//
//   node verify/export-reference.mjs … --out <existing export> --extra /<preset>/docs/<page>
//                                                     add a single page afterwards
//   node verify/export-reference.mjs … --out <existing export> --viewport 1024x768 --name 1024 [--skip-existing]
//                                                     additional width: pages/**/dom-1024.html
//   node verify/export-reference.mjs … --out <existing export> --states-only --state-page /<preset>/docs/<page>
//                                                     opened states into states/ (see captureStates)
//
// --preset <name>     route prefix of the app (default notebook): /<preset>, /<preset>/docs,
//                     /<preset>/api/tree and /<preset>/api/search.
// --out <dir>         export folder (default verify/reference/<app commit>, ignored by git).
// --viewport <W>x<H>  viewport for the hydrated DOM (default 1440x900).
// --name <suffix>     writes dom-<suffix>.html instead of dom.html. ssr.html, toc/ and every
//                     other file stay untouched (the server response doesn't depend on the
//                     width); the target folder isn't deleted.
// --skip-existing     skip pages whose DOM file already exists and isn't empty (makes
//                     interrupted runs resumable).
// --states-only       only write the state snapshots of captureStates; needs --state-page.
// --queries <file>    JSON list of search queries; each answer of /<preset>/api/search is
//                     stored under search/. Without it, search answers are skipped.
// --brand <path>      file relative to the app's public/ folder, copied to brand/<name>
//                     (repeatable).
//
// The script freezes the current state of the app so Pholio has a fixed target. Stored:
// the server response (ssr.html) and the hydrated DOM (dom.html) of every page, the
// compiled stylesheet, the font files with their fallback metrics, brand images, the page
// tree, the table of contents per page, the search answers to a fixed query set, the lucide
// icons Fumadocs uses as SVG and the exact package versions.
//
// Requirements: the app's server is running, and Playwright with Chromium is available
// (see lib/browser.mjs). Otherwise Node built-ins only.

import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import { arg, argAll, flag, resolveHome } from './lib/cli.mjs';
import { launchBrowser } from './lib/browser.mjs';

const verifyDir = path.dirname(fileURLToPath(import.meta.url));

if (flag('help') || process.argv.includes('-h')) {
  const source = await fs.readFile(fileURLToPath(import.meta.url), 'utf8');
  const header = source.split('\n').slice(1).filter((l, i, all) => all.slice(0, i + 1).every((x) => x.startsWith('//') || x === '')).map((l) => l.replace(/^\/\/ ?/, ''));
  console.log(header.join('\n').trimEnd());
  process.exit(0);
}

// ---- cli ------------------------------------------------------------------

function usage(message) {
  console.error(message);
  process.exit(2);
}

const preset = arg('preset', 'notebook');
const labUrlRaw = arg('lab-url', '') || process.env.PHOLIO_LAB_URL || '';
if (!labUrlRaw) usage('Missing: --lab-url <base URL of the running reference app> (or PHOLIO_LAB_URL).');
const labUrl = labUrlRaw.replace(/\/+$/, '');

const labDirRaw = arg('lab-dir', '') || process.env.PHOLIO_LAB_DIR || '';
if (!labDirRaw) usage('Missing: --lab-dir <reference app folder> (or PHOLIO_LAB_DIR).');
const labDirSource = arg('lab-dir', '') ? '--lab-dir' : 'PHOLIO_LAB_DIR';
const labDir = resolveHome(labDirRaw);
{
  let isDir = false;
  try {
    isDir = (await fs.stat(labDir)).isDirectory();
  } catch {
    isDir = false;
  }
  if (!isDir) usage(`Reference app folder missing: ${labDir} (from ${labDirSource}).`);
}

// The commit hash of the app goes into the output path, so an export always stays tied to a
// frozen state.
function labHash() {
  try {
    return execFileSync('git', ['-C', labDir, 'rev-parse', '--short', 'HEAD'], { encoding: 'utf8' }).trim();
  } catch {
    return 'unknown';
  }
}
function labTag() {
  try {
    return execFileSync('git', ['-C', labDir, 'tag', '--points-at', 'HEAD'], { encoding: 'utf8' }).trim().split('\n')[0] || '';
  } catch {
    return '';
  }
}

const hash = labHash();
const outDir = resolveHome(arg('out', path.join(verifyDir, 'reference', hash)));

// Pages that aren't in the page tree (for example a component catalogue). With `--extra`
// the export runs in append mode: the target folder stays, and only these pages are written
// (ssr.html, dom.html, toc).
const extraUrls = argAll('extra').map((p) => (p.startsWith('/') ? p : `/${p}`));

const viewportArg = arg('viewport', '1440x900');
const viewportMatch = /^(\d+)x(\d+)$/.exec(viewportArg);
if (!viewportMatch) usage(`--viewport expects <width>x<height>, got: ${viewportArg}`);
const viewport = { width: Number(viewportMatch[1]), height: Number(viewportMatch[2]) };
const defaultViewport = viewport.width === 1440 && viewport.height === 900;
const domSuffix = arg('name', '');
if (domSuffix && !/^[A-Za-z0-9_-]+$/.test(domSuffix)) usage(`--name allows only letters, digits, _ and -: ${domSuffix}`);
const domFileName = domSuffix ? `dom-${domSuffix}.html` : 'dom.html';
const skipExisting = flag('skip-existing');
const statesOnly = flag('states-only');
const statePage = arg('state-page', '');
if (statesOnly && !statePage) usage('--states-only needs --state-page <path of a document page with a table of contents>');
const queriesFile = arg('queries', '');
const brandFiles = argAll('brand');

// Append mode: the target folder stays. That applies to --extra, to an additional width
// (--name) and to --states-only.
const appendOnly = extraUrls.length > 0 || Boolean(domSuffix) || statesOnly;

// ---- Small helpers ------------------------------------------------------------

async function write(rel, data) {
  const file = path.join(outDir, rel);
  await fs.mkdir(path.dirname(file), { recursive: true });
  await fs.writeFile(file, data);
  return file;
}

const json = (value) => `${JSON.stringify(value, null, 2)}\n`;

async function getText(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error(`${res.status} ${res.statusText} for ${url}`);
  return res.text();
}

async function getBuffer(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error(`${res.status} ${res.statusText} for ${url}`);
  return Buffer.from(await res.arrayBuffer());
}

// URL path → folder path in the export, for example /<preset>/docs/<page> → pages/<preset>/docs/<page>
const pageDir = (urlPath) => path.posix.join('pages', urlPath.replace(/^\/+/, ''));

// Latin letters with diacritics that need more than one ASCII letter, written as escapes.
const TRANSLITERATE = { '\u00e4': 'ae', '\u00f6': 'oe', '\u00fc': 'ue', '\u00df': 'ss' };

// Label → file name slug: lower case, the letters above transliterated, every other run of
// characters outside a-z0-9 becomes a dash.
const slugify = (label) =>
  label
    .toLowerCase()
    .replace(/[\u00e4\u00f6\u00fc\u00df]/g, (c) => TRANSLITERATE[c])
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');

// ---- Page list from the tree API ----------------------------------------------

// The tree holds folders (with an optional `index`), pages and separators. Every `page` and
// `index` URL is collected in tree order.
function collectUrls(nodes, out = []) {
  for (const node of nodes ?? []) {
    if (typeof node?.page === 'string') out.push(node.page);
    if (typeof node?.index === 'string') out.push(node.index);
    else if (node?.index && typeof node.index.url === 'string') out.push(node.index.url);
    if (Array.isArray(node?.children)) collectUrls(node.children, out);
  }
  return out;
}

async function nonEmptyFile(file) {
  try {
    return (await fs.stat(file)).size > 0;
  } catch {
    return false;
  }
}

// Page list as in the full export: start page, docs root, then the tree.
async function listPages() {
  const tree = JSON.parse(await getText(`${labUrl}/${preset}/api/tree`));
  const docsUrls = collectUrls(tree);
  // The docs root (`/<preset>/docs`) isn't in the tree API but belongs to the export.
  const docsRoot = `/${preset}/docs`;
  if (!docsUrls.includes(docsRoot) && (await fetch(`${labUrl}${docsRoot}`)).ok) docsUrls.unshift(docsRoot);
  return { tree, docsUrls, urls: [`/${preset}`, ...docsUrls] };
}

// ---- State snapshots (--states-only) ----------------------------------------------

// Waits until no animation runs any more, then 100 ms ("settled").
async function settled(page) {
  await page.waitForFunction(
    () => document.getAnimations().every((a) => a.playState !== 'running'),
    null,
    { timeout: 10_000 },
  );
  await page.waitForTimeout(100);
}

// Folders of the desktop sidebar that are open on no frozen page (dom.html). Result:
// [{ label, page }] with the first page on which the folder is visible.
async function neverOpenFolders() {
  const pagesRoot = path.join(outDir, 'pages');
  const seen = new Map();
  async function walk(dir) {
    const entries = await fs.readdir(dir, { withFileTypes: true });
    if (entries.some((e) => e.isFile() && e.name === 'dom.html')) {
      const html = await fs.readFile(path.join(dir, 'dom.html'), 'utf8');
      const start = html.indexOf('id="nd-sidebar"');
      if (start >= 0) {
        const side = html.slice(start, html.indexOf('</aside>', start));
        const urlPath = `/${path.relative(pagesRoot, dir).split(path.sep).join('/')}`;
        for (const m of side.matchAll(/<div data-(open|closed)="">\s*<button[^>]*>([\s\S]*?)<\/button>/g)) {
          const label = m[2].replace(/<[^>]+>/g, '').trim();
          const entry = seen.get(label) ?? { label, open: 0, pages: [] };
          if (m[1] === 'open') entry.open += 1;
          entry.pages.push(urlPath);
          seen.set(label, entry);
        }
      }
    }
    for (const e of entries) if (e.isDirectory()) await walk(path.join(dir, e.name));
  }
  await walk(pagesRoot);
  return [...seen.values()]
    .filter((e) => e.open === 0)
    .map((e) => ({ label: e.label, page: e.pages.sort()[0] }))
    .sort((a, b) => a.label.localeCompare(b.label));
}

async function writeState(page, name, info, html) {
  await write(path.posix.join('states', `${name}.html`), `${html}\n`);
  const meta = await page.evaluate(() => ({
    url: location.href,
    bodyAttributes: Object.fromEntries([...document.body.attributes].map((a) => [a.name, a.value])),
    activeElement: document.activeElement ? document.activeElement.outerHTML.slice(0, 300) : null,
    animations: document.getAnimations().map((a) => ({ playState: a.playState, name: a.animationName ?? null })),
  }));
  await write(path.posix.join('states', `${name}.json`), json({ ...info, ...meta }));
  console.log(`  states/${name}.html  (${html.length} characters)`);
}

// Opened states that the full export lacks:
//  a) every sidebar folder that is open on no frozen page, opened at 1440 × 900 by a click on
//     its trigger → states/folder-open-<slug>.html (outerHTML of the folder wrapper);
//  b) the table of contents popover opened at 1024 × 768 → states/tocpopover-open-1024.html;
//  c) the drawer opened and closed again at 390 × 844 → states/drawer-open-390.html,
//     states/drawer-closed-390.html (outerHTML of #nd-notebook-layout).
async function captureStates(browser) {
  const folders = await neverOpenFolders();
  console.log(`Never open folders: ${folders.map((f) => `${f.label} (${f.page})`).join(', ') || 'none'}`);

  // Every state gets a fresh page load. Navigation with a 60 s limit and a second attempt,
  // because a shared development server is sometimes heavily loaded.
  const withPage = async (vp, urlPath, fn) => {
    for (let attempt = 1; ; attempt += 1) {
      const page = await browser.newPage({ viewport: vp });
      page.setDefaultNavigationTimeout(60_000);
      page.setDefaultTimeout(60_000);
      try {
        await page.goto(`${labUrl}${urlPath}`, { waitUntil: 'networkidle' });
        await page.waitForSelector('#nd-notebook-layout', { state: 'attached' });
        await page.waitForTimeout(500);
        return await fn(page);
      } catch (error) {
        if (attempt >= 2) throw error;
        console.log(`  attempt ${attempt} for ${urlPath} (${vp.width}x${vp.height}) failed: ${error.message.split('\n')[0]}`);
      } finally {
        await page.close();
      }
    }
  };
  const escapeRe = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

  for (const folder of folders) {
    await withPage({ width: 1440, height: 900 }, folder.page, async (page) => {
      const trigger = page
        .locator('#nd-sidebar div[data-closed] > button')
        .filter({ hasText: new RegExp(`^\\s*${escapeRe(folder.label)}\\s*$`) });
      await trigger.first().waitFor({ state: 'visible' });
      if ((await trigger.count()) !== 1) throw new Error(`Trigger of folder "${folder.label}" not unique: ${await trigger.count()}`);
      // Hold the handles before the click: afterwards the locator (data-closed) no longer matches.
      const button = await trigger.elementHandle();
      const wrapper = await button.evaluateHandle((el) => el.parentElement);
      await button.click();
      await page.waitForFunction((el) => el.hasAttribute('data-open'), wrapper);
      await settled(page);
      const html = await wrapper.evaluate((el) => el.outerHTML);
      await writeState(
        page,
        `folder-open-${slugify(folder.label)}`,
        { page: folder.page, viewport: '1440x900', interaction: `click on the folder trigger "${folder.label}" in #nd-sidebar`, selector: `#nd-sidebar div[data-open] (wrapper of the trigger "${folder.label}")` },
        html,
      );
    });
  }

  await withPage({ width: 1024, height: 768 }, statePage, async (page) => {
    const trigger = page.locator('[data-toc-popover] button').first();
    await trigger.waitFor({ state: 'visible' });
    await trigger.click();
    await page.waitForFunction(() => document.querySelector('[data-toc-popover]')?.hasAttribute('data-open'));
    await settled(page);
    const html = await page.evaluate(() => document.querySelector('[data-toc-popover]').outerHTML);
    await writeState(
      page,
      'tocpopover-open-1024',
      { page: statePage, viewport: '1024x768', interaction: 'click on the first button in [data-toc-popover]', selector: '[data-toc-popover]' },
      html,
    );
  });

  const openDrawer = async (page) => {
    const trigger = page.locator('button[aria-controls="nd-sidebar-mobile"]').first();
    await trigger.waitFor({ state: 'visible' });
    await trigger.click();
    // The drawer switches through data-state="open|closed", not through data-open.
    await page.waitForFunction(() => document.querySelector('#nd-sidebar-mobile')?.getAttribute('data-state') === 'open');
    await settled(page);
  };

  await withPage({ width: 390, height: 844 }, statePage, async (page) => {
    await openDrawer(page);
    const html = await page.evaluate(() => document.querySelector('#nd-notebook-layout').outerHTML);
    await writeState(
      page,
      'drawer-open-390',
      { page: statePage, viewport: '390x844', interaction: 'click on button[aria-controls="nd-sidebar-mobile"]', selector: '#nd-notebook-layout' },
      html,
    );
  });

  await withPage({ width: 390, height: 844 }, statePage, async (page) => {
    await openDrawer(page);
    const isClosed = () => document.querySelector('#nd-sidebar-mobile')?.getAttribute('data-state') === 'closed';
    let closedBy = 'Esc';
    await page.keyboard.press('Escape');
    try {
      await page.waitForFunction(isClosed, null, { timeout: 5_000 });
    } catch {
      // Esc doesn't close the drawer: then the close button inside the drawer.
      closedBy = 'click on button[aria-controls="nd-sidebar-mobile"] in #nd-sidebar-mobile (Esc didn\'t close it)';
      await page.locator('#nd-sidebar-mobile button[aria-controls="nd-sidebar-mobile"]').first().click();
      await page.waitForFunction(isClosed);
    }
    await settled(page);
    const html = await page.evaluate(() => document.querySelector('#nd-notebook-layout').outerHTML);
    await writeState(
      page,
      'drawer-closed-390',
      { page: statePage, viewport: '390x844', interaction: `fresh load, drawer opened by a click on button[aria-controls="nd-sidebar-mobile"], closed by ${closedBy}`, selector: '#nd-notebook-layout' },
      html,
    );
  });
}

// ---- lucide icons -----------------------------------------------------------------

// The SVGs are built exactly as lucide-react renders them:
// shared/src/build/defaultAttributes.mjs provides the base attributes,
// buildLucideIconNode.mjs the order, the classes (`lucide lucide-<name>` plus
// `lucide-<alias>` per alias) and aria-hidden when no a11y props are set. The React `key`
// of each child node isn't a DOM attribute and is dropped.
const LUCIDE_DEFAULTS = [
  ['xmlns', 'http://www.w3.org/2000/svg'],
  ['width', '24'],
  ['height', '24'],
  ['viewBox', '0 0 24 24'],
  ['fill', 'none'],
  ['stroke', 'currentColor'],
  ['stroke-width', '2'],
  ['stroke-linecap', 'round'],
  ['stroke-linejoin', 'round'],
];

const escapeAttr = (value) =>
  String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

// Reads `const __iconData = { … }` from lucide-react/dist/esm/icons/<name>.mjs. The object
// literal is evaluated instead of imported because the import would pull in React; the
// literal itself is plain JSON-like JavaScript.
async function readIconData(iconsDir, name) {
  const source = await fs.readFile(path.join(iconsDir, `${name}.mjs`), 'utf8');
  const start = source.indexOf('const __iconData = ');
  if (start < 0) throw new Error(`__iconData missing in ${name}.mjs`);
  const open = source.indexOf('{', start);
  let depth = 0;
  let end = -1;
  for (let i = open; i < source.length; i += 1) {
    if (source[i] === '{') depth += 1;
    else if (source[i] === '}') {
      depth -= 1;
      if (depth === 0) {
        end = i + 1;
        break;
      }
    }
  }
  if (end < 0) throw new Error(`__iconData incomplete in ${name}.mjs`);
  // eslint-disable-next-line no-new-func
  return new Function(`return ${source.slice(open, end)};`)();
}

function renderIcon(icon) {
  const classes = ['lucide', `lucide-${icon.name}`];
  for (const alias of icon.aliases ?? []) {
    if (typeof alias === 'string' && alias.trim() !== '') classes.push(`lucide-${alias}`);
  }
  const size = icon.size ?? 24;
  const attrs = LUCIDE_DEFAULTS.map(([key, value]) => {
    if (key === 'width' || key === 'height') return [key, String(size)];
    if (key === 'viewBox') return [key, `0 0 ${size} ${size}`];
    return [key, value];
  });
  attrs.push(['class', classes.join(' ')]);
  attrs.push(['aria-hidden', 'true']);

  const children = (icon.node ?? []).map(([tag, raw]) => {
    const inner = Object.entries(raw ?? {})
      .filter(([key]) => key !== 'key')
      .map(([key, value]) => ` ${key}="${escapeAttr(value)}"`)
      .join('');
    return `  <${tag}${inner} />`;
  });

  const head = attrs.map(([key, value]) => ` ${key}="${escapeAttr(value)}"`).join('');
  return `<svg${head}>\n${children.join('\n')}\n</svg>\n`;
}

// React name in Fumadocs → file in lucide-react/dist/esm/icons.
// The mapping comes from the re-exports in lucide-react.mjs; the three surprising cases
// (Sidebar, Text, Edit) are verified there.
const ICONS = {
  Search: 'search',
  Sidebar: 'panel-left',
  SidebarIcon: 'panel-left',
  ChevronDown: 'chevron-down',
  ChevronRight: 'chevron-right',
  ChevronLeft: 'chevron-left',
  ChevronsUpDown: 'chevrons-up-down',
  Check: 'check',
  X: 'x',
  Sun: 'sun',
  Moon: 'moon',
  Airplay: 'airplay',
  Text: 'text-align-start',
  Hash: 'hash',
  Link: 'link',
  CopyCheck: 'copy-check',
  Copy: 'copy',
  Clipboard: 'clipboard',
  ExternalLink: 'external-link',
  Info: 'info',
  TriangleAlert: 'triangle-alert',
  CircleX: 'circle-x',
  CircleCheck: 'circle-check',
  Lightbulb: 'lightbulb',
  Edit: 'square-pen',
  Languages: 'languages',
  Users: 'users',
  Wrench: 'wrench',
  Scale: 'scale',
  ShieldCheck: 'shield-check',
  BookOpen: 'book-open',
  ArrowRight: 'arrow-right',
  FileText: 'file-text',
};

// ---- Main run ------------------------------------------------------------------------

async function main() {
  const started = new Date();
  console.log(`App ${labUrl}, preset ${preset}, commit ${hash}`);
  console.log(`Target ${outDir}`);
  if (!appendOnly) {
    await fs.rm(outDir, { recursive: true, force: true });
  }
  await fs.mkdir(outDir, { recursive: true });

  if (statesOnly) {
    const browser = await launchBrowser();
    try {
      await captureStates(browser);
    } finally {
      await browser.close();
    }
    console.log(`Done in ${Math.round((Date.now() - started.getTime()) / 1000)} s (states).`);
    return;
  }

  let urls;
  if (extraUrls.length > 0) {
    // Append mode: delete nothing, overwrite nothing except the named pages.
    urls = extraUrls;
    console.log(`Append: ${urls.length} page(s), the existing export stays untouched`);
  } else if (domSuffix) {
    // Additional width: page list as in the full export, but tree.json stays untouched.
    const listed = await listPages();
    urls = listed.urls;
    console.log(`Pages: ${urls.length} (1 start page + ${listed.docsUrls.length} document pages), width ${viewportArg} → ${domFileName}`);
  } else {
    // 1) Page tree and page list
    const listed = await listPages();
    await write('tree.json', json(listed.tree));
    urls = listed.urls;
    console.log(`Pages: ${urls.length} (1 start page + ${listed.docsUrls.length} document pages)`);
  }

  // 2) SSR HTML of every page (not with --name: the server response doesn't depend on the width)
  if (!domSuffix) {
    for (const urlPath of urls) {
      const html = await getText(`${labUrl}${urlPath}`);
      await write(path.posix.join(pageDir(urlPath), 'ssr.html'), html);
    }
  }

  // 3) Hydrated DOM and table of contents per page
  const browser = await launchBrowser();
  const page = await browser.newPage({ viewport });
  // Below 1280 px #nd-toc is missing and the collapsible table of contents is in the DOM
  // instead. At the default width the wait list stays as it was.
  const readySelector = defaultViewport
    ? '#nd-toc, #nd-toc-placeholder, #nd-home-layout'
    : '#nd-toc, #nd-toc-placeholder, [data-toc-popover], #nd-home-layout';
  let skipped = 0;
  try {
    for (const urlPath of urls) {
      const domFile = path.join(outDir, pageDir(urlPath), domFileName);
      if (skipExisting && (await nonEmptyFile(domFile))) {
        skipped += 1;
        continue;
      }
      await page.goto(`${labUrl}${urlPath}`, { waitUntil: 'networkidle' });
      // Either the notebook layout (table of contents or its placeholder) or the start page.
      // `attached` instead of `visible`: on narrow pages the placeholder is hidden by CSS.
      await page.waitForSelector(readySelector, {
        state: 'attached',
        timeout: 30_000,
      });
      await page.waitForTimeout(500);

      const dom = await page.evaluate(() => document.documentElement.outerHTML);
      await write(path.posix.join(pageDir(urlPath), domFileName), `<!DOCTYPE html>\n${dom}`);
      if (domSuffix) console.log(`  ${pageDir(urlPath)}/${domFileName}`);

      if (urlPath !== `/${preset}` && !domSuffix) {
        // The indentation depth of the clerk entries is in the inline style:
        // 20px → 2, 32px → 3, 44px → 4, no indentation → 1.
        const toc = await page.evaluate(() => {
          const root = document.querySelector('#nd-toc');
          if (!root) return [];
          const depths = { '20px': 2, '32px': 3, '44px': 4 };
          return [...root.querySelectorAll('a[href^="#"]')].map((a) => ({
            depth: depths[a.style.paddingInlineStart] ?? 1,
            title: (a.textContent ?? '').trim(),
            // Take the href attribute verbatim, so non-ASCII letters aren't percent-encoded.
            url: a.getAttribute('href'),
          }));
        });
        await write(path.posix.join('toc', `${urlPath.replace(/^\/+/, '')}.json`), json(toc));
      }
    }
  } finally {
    await browser.close();
  }
  if (skipped) console.log(`Skipped (already present): ${skipped}`);

  if (appendOnly) {
    console.log(`Done in ${Math.round((Date.now() - started.getTime()) / 1000)} s (append).`);
    return;
  }

  // 4) Stylesheet(s) from the SSR HTML of the start page
  const homeHtml = await fs.readFile(path.join(outDir, pageDir(`/${preset}`), 'ssr.html'), 'utf8');
  const hrefs = [...homeHtml.matchAll(/<link[^>]+rel="stylesheet"[^>]*>/g)]
    .map((m) => m[0].match(/href="([^"]+)"/)?.[1])
    .filter(Boolean);
  const cssFiles = [];
  for (const href of [...new Set(hrefs)]) {
    const cssUrl = new URL(href, labUrl);
    const css = await getText(cssUrl.href);
    const base = decodeURIComponent(path.posix.basename(cssUrl.pathname));
    await write(path.posix.join('css', base), css);
    cssFiles.push({ url: cssUrl.href, base, css });
  }

  // 5) Font files and Inter metrics from the CSS
  const faceBlocks = [];
  const fontUrls = new Set();
  for (const { url: cssUrl, css } of cssFiles) {
    for (const match of css.matchAll(/@font-face\s*\{[^}]*\}/g)) {
      faceBlocks.push(match[0]);
      for (const u of match[0].matchAll(/url\(["']?([^"')]+)["']?\)/g)) {
        if (/\.woff2$/i.test(u[1])) fontUrls.add(new URL(u[1], cssUrl).href);
      }
    }
  }
  for (const fontUrl of fontUrls) {
    await write(path.posix.join('fonts', path.posix.basename(new URL(fontUrl).pathname)), await getBuffer(fontUrl));
  }
  // Every Inter block verbatim, including "Inter Fallback" with the override metrics.
  const interBlocks = faceBlocks.filter((block) => /Inter/i.test(block));
  await write('fonts/inter-fallback-metrics.txt', `${interBlocks.join('\n\n')}\n`);

  // 6) Brand images
  for (const rel of brandFiles) {
    await write(path.posix.join('brand', path.posix.basename(rel)), await fs.readFile(path.join(labDir, 'public', rel)));
  }

  // 7) Search: fixed query set
  if (queriesFile) {
    const queries = JSON.parse(await fs.readFile(resolveHome(queriesFile), 'utf8'));
    const index = {};
    for (const [i, query] of queries.entries()) {
      const slug = slugify(query) || 'empty';
      const file = `${String(i + 1).padStart(2, '0')}-${slug}.json`;
      const res = await getText(`${labUrl}/${preset}/api/search?query=${encodeURIComponent(query)}`);
      await write(path.posix.join('search', file), json(JSON.parse(res)));
      index[query] = file;
    }
    await write('search/index.json', json(index));
  } else {
    console.log('Search answers skipped (no --queries).');
  }

  // 8) Icons
  const iconsDir = path.join(labDir, 'node_modules/lucide-react/dist/esm/icons');
  const aliasLines = [];
  const writtenIcons = new Set();
  for (const [reactName, fileName] of Object.entries(ICONS)) {
    const icon = await readIconData(iconsDir, fileName);
    if (!writtenIcons.has(fileName)) {
      await write(path.posix.join('icons', `${fileName}.svg`), renderIcon(icon));
      writtenIcons.add(fileName);
    }
    aliasLines.push(
      `${reactName.padEnd(16)} -> icons/${fileName}.svg   class="lucide lucide-${icon.name}${(icon.aliases ?? [])
        .map((a) => ` lucide-${a}`)
        .join('')}"`,
    );
  }
  await write(
    'icons/ALIASES.txt',
    [
      '# React name in Fumadocs -> file in the export -> rendered class list',
      '# Source: lucide-react/dist/esm/lucide-react.mjs (re-exports) and',
      '#         lucide-react/dist/esm/shared/src/build/buildLucideIconNode.mjs (classes, attribute order).',
      '# Watch out: Sidebar -> panel-left, Text -> text-align-start, Edit -> square-pen.',
      '',
      ...aliasLines,
      '',
    ].join('\n'),
  );

  // 9) Versions
  const pkgVersion = async (name) => {
    try {
      const pkg = JSON.parse(await fs.readFile(path.join(labDir, 'node_modules', name, 'package.json'), 'utf8'));
      return { name: pkg.name, version: pkg.version };
    } catch {
      return { name, version: null };
    }
  };
  await write(
    'versions.json',
    json({
      lab: { hash, tag: labTag(), url: labUrl, preset },
      exportedAt: started.toISOString(),
      packages: Object.fromEntries(
        await Promise.all(
          ['fumadocs-ui', 'fumadocs-core', 'fumadocs-mdx', '@base-ui/react', 'lucide-react', 'tailwindcss', 'next', 'react'].map(
            async (name) => [name, await pkgVersion(name)],
          ),
        ),
      ),
    }),
  );

  // 10) README
  await write(
    'README.md',
    [
      `# Reference export (${hash})`,
      '',
      `Created on ${started.toISOString()} from commit \`${hash}\` (tag \`${labTag() || '–'}\`, preset \`${preset}\`) at ${labUrl}.`,
      'This folder is the frozen target Pholio is compared against. It is never committed.',
      '',
      '## Commands',
      '',
      '```bash',
      '# create the export (the reference app must be running)',
      `node verify/export-reference.mjs --lab-dir <app dir> --lab-url ${labUrl} --preset ${preset}`,
      '```',
      '',
      '## Contents',
      '',
      '| Folder | Contents |',
      '| --- | --- |',
      '| `pages/<path>/ssr.html` | raw server response of the page (`fetch`) |',
      '| `pages/<path>/dom.html` | `document.documentElement.outerHTML` after hydration (Playwright, 1440 × 900, networkidle + 500 ms) |',
      '| `css/` | the compiled Tailwind stylesheet from the `<link rel="stylesheet">` of the start page |',
      '| `fonts/` | every `.woff2` referenced in the CSS plus `inter-fallback-metrics.txt` (every Inter `@font-face` block verbatim) |',
      '| `brand/` | the files passed with `--brand`, from the app\'s `public/` folder |',
      '| `tree.json` | answer of `/<preset>/api/tree` |',
      '| `toc/<path>.json` | table of contents per document page as `{ depth, title, url }` from `#nd-toc` |',
      '| `search/` | answers of `/<preset>/api/search` to the `--queries` file, `index.json` maps query → file |',
      '| `icons/` | the lucide icons Fumadocs uses as SVG, exactly as lucide-react renders them; `ALIASES.txt` records the mapping |',
      '| `versions.json` | package versions of the app plus commit and tag |',
      '',
      '## Notes',
      '',
      '- The depth in the table of contents comes from the inline `padding-inline-start`: 20px → 2, 32px → 3, 44px → 4.',
      '- lucide additionally renders `aria-hidden="true"` on the `<svg>` and one more class per alias',
      '  (`lucide-panel-left lucide-sidebar`). The React `key` of the child nodes isn\'t a DOM attribute.',
      '- Order of the SVG attributes: xmlns, width, height, viewBox, fill, stroke, stroke-width,',
      '  stroke-linecap, stroke-linejoin, class, aria-hidden.',
      '',
    ].join('\n'),
  );

  console.log(`Done in ${Math.round((Date.now() - started.getTime()) / 1000)} s.`);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
