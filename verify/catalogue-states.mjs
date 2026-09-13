#!/usr/bin/env node
// Catalogue components: prepare excerpts for the golden DOM comparison.
//
//   node verify/catalogue-states.mjs --mode static --reference <export> --site <dir> --cuts <file.json> --out <dir> [--no-code]
//   node verify/catalogue-states.mjs --mode states --reference <export> --site <dir> --cuts <file.json> --out <dir>
//   node verify/catalogue-states.mjs --mode layers --site <dir> --page <path> --out <dir>
//
// <export> is the reference export, <site> the directory Pholio built the catalogue page
// into (the page as <page>/index.html plus its assets).
//
// --mode static  cuts every component out of catalogue/pages/<page>/dom.html and out of the
//                built page (without scripts), plus every code block (figure.shiki) and the
//                code tabs one by one; --no-code leaves out the code blocks.
// --mode layers  checks the layer order of [&>figure:only-child]:-m-4 against the built
//                stylesheet.
// --mode states  loads the built page with JavaScript through lib/serve-site.mjs, runs the
//                interaction (a fresh context per state) and cuts out the subtree; the
//                reference is states/<ref>.html of the export.
//
// The cuts file names the page and the excerpts. It is site data: selectors refer to
// heading ids of the catalogue page. Selectors use structure, ids and attributes only,
// because the classes differ on the two sides (utilities against nd-*).
//
//   {
//     "page": "docs/components",                        page path inside the export and the site
//     "static": [["tabs", "#tabs + div"], …],           name, selector (first match)
//     "staticAll": [["code", "article .prose > figure.shiki"], …],   every match, <name>-<nn>
//     "states": [
//       { "name": "tabs-second", "ref": "catalogue-tabs-second.html", "select": "#tabs + div",
//         "click": "#tabs + div [role=\"tab\"]:nth-of-type(2)" },
//       { "name": "imagezoom-open-dialog", "ref": "catalogue-imagezoom-open-body.html",
//         "refSelect": "div[data-rmiz-portal]", "select": "div[data-rmiz-portal]",
//         "zoom": true, "bodyStyle": true }
//     ]
//   }
//
// A state either clicks a selector (scrolled to the centre first), opens the image zoom
// ("zoom": true) or does nothing. "bodyStyle": true additionally compares the style
// attribute of <body> with bodyStyle in states/<ref>.json. --page overrides "page".
//
// Result under <out>: reference/pages/<name>/dom.html and candidate/<name>/index.html,
// each <html><body>EXCERPT</body></html>. golden-dom.mjs then compares
// `--reference <out>/reference --candidate <out>/candidate --no-rewrite`.
// Exit code 1 when a selector finds nothing on one side or a direct check fails, 2 on usage
// errors.

import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

import { arg, flag, resolveHome, readJson } from './lib/cli.mjs';
import { startSite } from './lib/serve-site.mjs';
import { launchBrowser } from './lib/browser.mjs';

function usage(message) {
  console.error(`${message}\nUsage: --mode static|states|layers --site <dir> --out <dir> [--reference <export>] [--cuts <file.json>] [--page <path>]`);
  process.exit(2);
}

const mode = arg('mode', 'static');
if (!['static', 'states', 'layers'].includes(mode)) usage(`--mode must be static, states or layers, got: ${mode}`);
if (!arg('site', '') || !arg('out', '')) usage('Missing: --site and --out');
if (mode !== 'layers' && (!arg('reference', '') || !arg('cuts', ''))) usage(`--mode ${mode} needs --reference and --cuts`);
const site = resolveHome(arg('site', ''));
const out = resolveHome(arg('out', ''));
const reference = arg('reference', '') ? resolveHome(arg('reference', '')) : null;
const cuts = arg('cuts', '') ? await readJson(resolveHome(arg('cuts', ''))) : {};
const PAGE = (arg('page', '') || cuts.page || '').replace(/^\/+|\/+$/g, '');
if (!PAGE) usage('Missing: the page path (--page or "page" in the cuts file)');

const withCode = !flag('no-code');
const VIEWPORT = { width: 1440, height: 900 };

const STATIC = cuts.static ?? [];
// Repeated excerpts: every match becomes a comparison of its own (<name>-<n>). Both sides
// must have the same number of matches. When the page was built without code, the code
// blocks are missing on both sides; --no-code turns them off.
const STATIC_ALL = cuts.staticAll ?? [];
const STATES = (cuts.states ?? []).map((state) => ({
  ...state,
  act: state.zoom ? openZoom : state.click ? (page) => clickCentered(page, state.click) : async () => {},
}));

// Bring the target to the middle of the window first: the sticky header and the banner
// (sticky, z-40) otherwise cover an element that Playwright scrolls into view only barely.
async function clickCentered(page, selector) {
  await page.waitForSelector(selector, { timeout: 5000 });
  await page.evaluate((sel) => document.querySelector(sel).scrollIntoView({ block: 'center', inline: 'nearest' }), selector);
  await page.waitForTimeout(100);
  await page.click(selector, { timeout: 5000 });
}

async function openZoom(page) {
  // The image loads with loading="lazy" only near the window; before that it stays
  // "not-found" (as in the frozen dom.html of the reference).
  await page.evaluate(() => document.querySelector('span[data-rmiz]').scrollIntoView({ block: 'center' }));
  await page.waitForSelector('span[data-rmiz] > [data-rmiz-content="found"]', { state: 'attached', timeout: 5000 });
  await clickCentered(page, 'span[data-rmiz] img');
  // Transition 300 ms, then LOADED and the swap of the image source.
  await page.waitForTimeout(900);
}

function wrap(html) {
  return `<!doctype html><html><head></head><body>${html}</body></html>\n`;
}

function stripScripts(html) {
  return html.replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi, '');
}

async function write(kind, name, html) {
  const dir = kind === 'reference' ? path.join(out, 'reference', 'pages', name) : path.join(out, 'candidate', name);
  await fs.mkdir(dir, { recursive: true });
  await fs.writeFile(path.join(dir, kind === 'reference' ? 'dom.html' : 'index.html'), wrap(html));
}

// Excerpt from an HTML text without running scripts.
async function cutStatic(browser, html, selector) {
  const context = await browser.newContext({ viewport: VIEWPORT, javaScriptEnabled: false });
  const page = await context.newPage();
  try {
    await page.setContent(stripScripts(html), { waitUntil: 'domcontentloaded' });
    return await page.evaluate((sel) => document.querySelector(sel)?.outerHTML ?? null, selector);
  } finally {
    await context.close();
  }
}

// Every match of a selector without running scripts.
async function cutAll(browser, html, selector) {
  const context = await browser.newContext({ viewport: VIEWPORT, javaScriptEnabled: false });
  const page = await context.newPage();
  try {
    await page.setContent(stripScripts(html), { waitUntil: 'domcontentloaded' });
    return await page.evaluate((sel) => [...document.querySelectorAll(sel)].map((el) => el.outerHTML), selector);
  } finally {
    await context.close();
  }
}

async function main() {
  const browser = await launchBrowser();
  const problems = [];
  await fs.rm(out, { recursive: true, force: true });

  try {
    if (mode === 'static') {
      const refHtml = await fs.readFile(path.join(reference, 'catalogue/pages', PAGE, 'dom.html'), 'utf8');
      const candHtml = await fs.readFile(path.join(site, PAGE, 'index.html'), 'utf8');
      for (const [name, selector] of STATIC) {
        const ref = await cutStatic(browser, refHtml, selector);
        const cand = await cutStatic(browser, candHtml, selector);
        if (ref === null) problems.push(`${name}: "${selector}" missing in the reference`);
        if (cand === null) problems.push(`${name}: "${selector}" missing in the candidate`);
        if (ref === null || cand === null) continue;
        await write('reference', name, ref);
        await write('candidate', name, cand);
      }
      if (withCode) {
        for (const [name, selector] of STATIC_ALL) {
          const refs = await cutAll(browser, refHtml, selector);
          const cands = await cutAll(browser, candHtml, selector);
          console.log(`${name}: ${refs.length} in the reference, ${cands.length} in the candidate`);
          if (refs.length === 0 || refs.length !== cands.length) {
            problems.push(`${name}: "${selector}" matches ${refs.length}× in the reference, ${cands.length}× in the candidate`);
            continue;
          }
          for (let i = 0; i < refs.length; i += 1) {
            const key = `${name}-${String(i + 1).padStart(2, '0')}`;
            await write('reference', key, refs[i]);
            await write('candidate', key, cands[i]);
          }
        }
      }
    } else if (mode === 'layers') {
      // Layer rule without a reference app: in the reference `[&>figure:only-child]:-m-4`
      // sits in the `utilities` layer after `.prose :where(figure)` (2em). A figure without
      // not-prose as the only child of a tab panel must therefore have a -1rem margin, not
      // 2em. Checked against the built stylesheet.
      const server = await startSite({ root: site });
      try {
        const context = await browser.newContext({ viewport: VIEWPORT });
        const page = await context.newPage();
        await page.goto(`${server.url}/${PAGE}/`, { waitUntil: 'networkidle' });
        const margins = await page.evaluate(() => {
          const prose = document.querySelector('article .prose');
          const panel = document.createElement('div');
          panel.className = 'nd-tabbox-panel prose-no-margin';
          panel.innerHTML = '<figure class="nd-figure"><img alt="" width="10" height="10"></figure>';
          prose.appendChild(panel);
          const style = getComputedStyle(panel.firstElementChild);
          return [style.marginTop, style.marginRight, style.marginBottom, style.marginLeft];
        });
        await context.close();
        if (margins.some((m) => m !== '-16px')) problems.push(`layers: figure:only-child in the tab panel has margin ${margins.join(' ')} instead of -16px`);
        else console.log(`ok layers: figure:only-child in the tab panel ${margins.join(' ')}`);
      } finally {
        await server.close();
      }
    } else {
      const server = await startSite({ root: site });
      try {
        for (const state of STATES) {
          const refFile = await fs.readFile(path.join(reference, 'states', state.ref), 'utf8');
          const ref = state.refSelect
            ? await cutStatic(browser, refFile, state.refSelect)
            : await cutStatic(browser, refFile, 'body > *');
          const refMeta = JSON.parse(await fs.readFile(path.join(reference, 'states', state.ref.replace(/\.html$/, '.json')), 'utf8'));

          const context = await browser.newContext({ viewport: VIEWPORT, deviceScaleFactor: 1 });
          const page = await context.newPage();
          const errors = [];
          page.on('pageerror', (err) => errors.push(err.message));
          try {
            await page.goto(`${server.url}/${PAGE}/`, { waitUntil: 'networkidle' });
            await page.waitForTimeout(300);
            await state.act(page);
            await page.waitForTimeout(700);
            const cand = await page.evaluate((sel) => document.querySelector(sel)?.outerHTML ?? null, state.select);
            if (ref === null) problems.push(`${state.name}: reference excerpt missing`);
            if (cand === null) problems.push(`${state.name}: "${state.select}" missing in the candidate`);
            if (errors.length) problems.push(`${state.name}: script errors ${errors.join(' | ')}`);
            if (ref !== null && cand !== null) {
              await write('reference', state.name, ref);
              await write('candidate', state.name, cand);
            }
            if (state.bodyStyle) {
              const style = await page.evaluate(() => document.body.getAttribute('style'));
              const want = refMeta.bodyStyle;
              const norm = (s) => (s ?? '').replace(/\s+/g, '').replace(/;$/, '');
              if (norm(style) !== norm(want)) problems.push(`${state.name}: body style "${style}" instead of "${want}"`);
              else console.log(`ok ${state.name}: body style "${style}"`);
            }
          } finally {
            await context.close();
          }
        }
      } finally {
        await server.close();
      }
    }
  } finally {
    await browser.close();
  }

  for (const p of problems) console.log(`✗ ${p}`);
  console.log(`Excerpts: ${mode}, ${problems.length} problem(s), output ${out}`);
  process.exit(problems.length ? 1 : 0);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
