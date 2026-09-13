#!/usr/bin/env node
// Development fixture: reference DOM + reference CSS + Pholio's JavaScript.
//
//   node verify/lib/serve-reference.mjs --reference <export dir> --lab http://localhost:3000
//   node verify/lib/serve-reference.mjs --reference <export dir> --lab <url> --port 4177 --home /docs
//   node verify/lib/serve-reference.mjs ... --proxy /_next/ --proxy /images/
//   node verify/lib/serve-reference.mjs ... --search-index /tmp/search-index.json
//   node verify/lib/serve-reference.mjs ... --tabs-template states/tabs-popup-template.html
//   node verify/lib/serve-reference.mjs ... --inject panels.json --inject popover.json
//
// --reference is the reference export directory (or PHOLIO_REFERENCE), --lab the URL of
// the running reference app. Both are required.
//
// --inject <file.json> adds arbitrary markup to the frozen DOM that React in the
// reference app only renders on demand (closed collapsible panels, popups). Format:
//
//   [
//     { "page": "*",                      "*" = every page, otherwise an exact path
//       "selector": "#nd-sidebar div[data-closed]:has(> button)",
//       "position": "beforeend",          as insertAdjacentHTML: beforebegin |
//                                         afterbegin | beforeend | afterend
//       "html": "<template data-collapsible-panel>…</template>",
//       "all": true }                     optional: every match instead of the first
//   ]
//
// Markup is inserted in the browser, not by text replacement: a classic inline script
// before the module (modules are deferred, so the script is guaranteed to run first)
// applies the entries with a real querySelector, so every CSS selector including :has()
// works, and then removes itself so the DOM comparison sees no extra element. Selectors
// that don't match write a warning to the console.
//
// The server serves the frozen pages of the reference export (pages/**/dom.html) under
// their original addresses, removes everything Next.js loads at runtime (script tags
// pointing to /_next/, the __next_f data streams, dev overlay, next-route-announcer),
// and adds Pholio's own module before </body>:
//
//   <script type="module" src="/theme/js/notebook.js"></script>
//
// /theme/* comes straight from Pholio's theme/ directory (or --theme). Every path below a
// --proxy prefix (default: /_next/ only) is passed on to the running reference app, so
// the same compiled stylesheet and the same images apply.
//
// This measures Pholio's JavaScript against exactly the same DOM and styles as the
// reference, and because the generator produces the same DOM (golden DOM, stage 1),
// every green result here holds for the real site too.
//
// Node built-ins only.

import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { arg, argAll, argNumber, resolveHome, exists } from './cli.mjs';

const pholioRoot = path.resolve(fileURLToPath(new URL('../..', import.meta.url)));

const referenceArg = arg('reference', process.env.PHOLIO_REFERENCE ?? '');
const labArg = arg('lab', '');
if (!referenceArg || !labArg) {
  console.error('Missing: --reference <reference export dir> (or PHOLIO_REFERENCE) and --lab <reference app URL>');
  process.exit(2);
}

const referenceDir = resolveHome(referenceArg);
const themeDir = resolveHome(arg('theme', path.join(pholioRoot, 'theme')));
const lab = labArg.replace(/\/+$/, '');
const port = argNumber('port', 4177);
const proxyPrefixes = argAll('proxy').length ? argAll('proxy') : ['/_next/'];
// Page served for `/`; without --home the root address has no frozen page.
const home = arg('home', '');
// Search index for development. Build it with:
//   php verify/tools/build-search-index.php --order <order.json> /tmp/search-index.json
const searchIndex = arg('search-index', null);
// Content of the section switcher. In the reference app it lives in React; in static
// HTML the generator has to ship it as <template data-nd-tabs-popup>. This flag injects
// a version captured from the reference app, so the popover scenarios measure with the
// same content.
const tabsTemplate = arg('tabs-template', null);
// --inject may be repeated; the entries of all files are concatenated in the order given.
const injectEntries = [];
for (const file of argAll('inject')) {
  const list = JSON.parse(await fs.readFile(resolveHome(file), 'utf8'));
  if (!Array.isArray(list)) throw new Error(`--inject ${file}: expected a JSON array.`);
  injectEntries.push(...list);
}

const tabsTemplateHtml = tabsTemplate ? (await fs.readFile(resolveHome(tabsTemplate), 'utf8')).trim() : null;

const MIME = {
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.svg': 'image/svg+xml',
  '.woff2': 'font/woff2',
};

// The insertion script for --inject; `</script` inside the markup is defused.
function injectScript(pagePath) {
  const entries = injectEntries.filter((e) => e.page === '*' || e.page === pagePath);
  if (!entries.length) return '';
  const data = JSON.stringify(entries).replace(/<\/script/gi, '<\\/script');
  return '<script data-nd-inject>(function(){var list=' + data + ';'
    + 'list.forEach(function(e){var nodes=e.all?document.querySelectorAll(e.selector):[document.querySelector(e.selector)];'
    + 'var hit=0;for(var i=0;i<nodes.length;i++){if(!nodes[i])continue;nodes[i].insertAdjacentHTML(e.position||"beforeend",e.html);hit++;}'
    + 'if(!hit)console.warn("serve-reference --inject: no match for",e.selector);});'
    + 'var self=document.querySelector("script[data-nd-inject]");if(self)self.remove();})();</script>';
}

// Remove Next.js leftovers and add our module.
function prepare(html, pagePath = '') {
  let out = html;
  out = out.replace(/<script[^>]*src="\/_next\/[^"]*"[^>]*><\/script>/g, '');
  out = out.replace(/<script[^>]*>self\.__next_f[\s\S]*?<\/script>/g, '');
  out = out.replace(/<script[^>]*data-nextjs-dev-overlay[\s\S]*?<\/script>/g, '');
  out = out.replace(/<link[^>]*as="script"[^>]*>/g, '');
  out = out.replace(/<next-route-announcer[\s\S]*?<\/next-route-announcer>/g, '');
  out = out.replace(/<!--\$-->|<!--\/\$-->/g, '');
  if (tabsTemplateHtml) {
    out = out.replace('</body>', `<template data-nd-tabs-popup>${tabsTemplateHtml}</template></body>`);
  }
  out = out.replace('</body>', `${injectScript(pagePath)}<script type="module" src="/theme/js/notebook.js"></script></body>`);
  return out;
}

async function send(res, status, body, type) {
  res.writeHead(status, { 'content-type': type, 'cache-control': 'no-store' });
  res.end(body);
}

async function proxy(req, res) {
  try {
    const upstream = await fetch(lab + req.url, { headers: { accept: req.headers.accept ?? '*/*' } });
    const buf = Buffer.from(await upstream.arrayBuffer());
    res.writeHead(upstream.status, {
      'content-type': upstream.headers.get('content-type') ?? 'application/octet-stream',
      'cache-control': 'no-store',
    });
    res.end(buf);
  } catch (err) {
    await send(res, 502, `Reference app not reachable (${lab}): ${err.message}`, 'text/plain; charset=utf-8');
  }
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const pathname = decodeURIComponent(url.pathname);

  if (proxyPrefixes.some((prefix) => pathname.startsWith(prefix))) {
    await proxy(req, res);
    return;
  }

  if (pathname.startsWith('/theme/')) {
    const file = path.join(themeDir, pathname.slice('/theme/'.length));
    if (!file.startsWith(themeDir) || !(await exists(file))) {
      await send(res, 404, `Not found: ${pathname}`, 'text/plain; charset=utf-8');
      return;
    }
    await send(res, 200, await fs.readFile(file), MIME[path.extname(file)] ?? 'application/octet-stream');
    return;
  }

  // Search index: the file given with --search-index, otherwise empty.
  if (pathname.endsWith('/search-index.json')) {
    const file = searchIndex ? resolveHome(searchIndex) : null;
    if (file && await exists(file)) {
      await send(res, 200, await fs.readFile(file), MIME['.json']);
      return;
    }
    await send(res, 200, '[]', MIME['.json']);
    return;
  }

  const clean = pathname.replace(/\/+$/, '') || home;
  const page = path.join(referenceDir, 'pages', clean.replace(/^\//, ''), 'dom.html');
  if (!clean || !page.startsWith(referenceDir) || !(await exists(page))) {
    await send(res, 404, `No frozen page for ${pathname}`, 'text/plain; charset=utf-8');
    return;
  }
  await send(res, 200, prepare(await fs.readFile(page, 'utf8'), clean), 'text/html; charset=utf-8');
});

server.listen(port, () => {
  console.log(`Reference fixture on http://localhost:${port}`);
  console.log(`  Pages : ${referenceDir}/pages/**/dom.html`);
  console.log(`  Theme : ${themeDir}`);
  console.log(`  Proxy : ${lab} for ${proxyPrefixes.join(', ')}`);
});
