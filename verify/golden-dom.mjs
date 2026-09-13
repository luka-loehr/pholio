#!/usr/bin/env node
// Golden DOM comparison: a reference export against a Pholio build.
//
//   node verify/golden-dom.mjs --reference <export> --rewrites <file.json> --candidate http://127.0.0.1:4000/docs
//   node verify/golden-dom.mjs --reference <export> --rewrites <file.json> --candidate out --only guide/install
//   node verify/golden-dom.mjs --reference <export> --candidate <export> --no-rewrite --ignore-classes   self comparison
//   node verify/golden-dom.mjs --reference <export> --candidate <cand> --strict-classes --report .build/golden-dom
//   node verify/golden-dom.mjs --reference <export> --candidate <cand> --scope "article#nd-page div.prose"
//   node verify/golden-dom.mjs --reference <export> --candidate <cand> --only guide --dump /tmp/tree.txt --dump-side reference
//   node verify/golden-dom.mjs --reference <export> --candidate <url> --dom dom-390.html --viewport 390x844
//   node verify/golden-dom.mjs --reference <export> --candidate <cand> --allow verify/allow/golden-dom.json --allow site-allow.json
//
// What is compared is not the source text but the normalised element tree from <html>
// down: structure, tag names, attributes and text. Normalisation runs identically on both
// sides and removes everything Next.js adds in the reference (scripts, preloads, the dev
// overlay, comments) and everything that is rolled anew on every hydration (Base UI and
// React ids). Class lists go through class-map.json because Pholio carries component
// classes instead of utility classes. Known differences that can't be rebuilt are listed
// with a reason in the allow files and are printed anyway, so nothing disappears silently.
//
// Reference page: the frozen `pages/**/dom.html` of the reference export (hydrated DOM,
// viewport 1440 × 900). They are loaded without running scripts because they already are
// the result after hydration. --dom compares another file of the same page, for example
// dom-1024.html (1024 × 768) or dom-390.html (390 × 844). --viewport sets the window size
// used for loading (default 1440x900); for a candidate URL it must match the chosen
// reference width, otherwise Pholio is measured at another width than the reference.
// Candidate files: without --candidate-dom, <path>/index.html is tried first, then a file
// named like --dom. With --candidate-dom only that file name counts; if the file is
// missing, the page is red instead of silently falling back to another width.
// Candidate page: either a base URL, loaded for real with JavaScript (`networkidle` plus
// 500 ms, same viewport), or a directory with `<path>/index.html`; files are loaded
// without scripts, with --candidate-file-js through file:// including JavaScript.
//
// Options:
//   --rewrites <file.json>   reference → candidate path rewrites (repeatable, see lib/cli.mjs)
//   --rewrite from=to        one more rule; --no-rewrite turns all rules off
//   --map <file.json>        class map (repeatable, merged in order; default verify/class-map.json)
//   --allow <file.json>      allow list (repeatable, concatenated in order;
//                            default verify/allow/golden-dom.json when no --allow is given)
//   --report <dir>           report directory (default .build/golden-dom in the working directory)
//   --only <list>            comma list of page paths, trailing segments or substrings
//   --strict-classes, --ignore-classes, --drop-style-tags, --candidate-file-js
//   --scope <selector>, --dump <file>, --dump-side reference|candidate
//
// Exit code 1 as soon as a page has a difference that isn't allowed, 2 on usage errors.
// Requires Playwright with Chromium (see lib/browser.mjs); otherwise Node built-ins only.

import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import { arg, flag, argAll, resolveHome, readJson, exists, isUrl, buildRewrites, rewritePath } from './lib/cli.mjs';
import { launchBrowser } from './lib/browser.mjs';

const verifyDir = path.dirname(fileURLToPath(import.meta.url));

// ---- cli ------------------------------------------------------------------

function usage(message) {
  console.error(message);
  process.exit(2);
}

// Which reference file per page is compared, and at which window size it is loaded.
function fileNameArg(name, fallback) {
  const value = arg(name, fallback);
  if (!value || value.includes('/') || value.includes(path.sep) || value.startsWith('.')) {
    usage(`--${name} expects a plain file name like dom-390.html, got: ${value}`);
  }
  return value;
}
const domName = fileNameArg('dom', 'dom.html');
const candidateDomExplicit = process.argv.includes('--candidate-dom');
const candidateDomName = candidateDomExplicit ? fileNameArg('candidate-dom', domName) : domName;

function parseViewport(value) {
  const m = /^(\d+)x(\d+)$/.exec(value);
  if (!m) usage(`--viewport expects <width>x<height>, for example 390x844, got: ${value}`);
  return { width: Number(m[1]), height: Number(m[2]) };
}
const VIEWPORT = parseViewport(arg('viewport', '1440x900'));

// A file name that carries a width (dom-390.html) not matching the window is almost always
// a usage error; it is only a warning because a self comparison of files is valid without
// a matching width.
{
  const m = /-(\d+)\.html$/.exec(domName);
  const expectedWidth = m ? Number(m[1]) : 1440;
  if (expectedWidth !== VIEWPORT.width) {
    console.warn(`Warning: --dom ${domName} belongs to width ${expectedWidth}, loading at ${VIEWPORT.width}x${VIEWPORT.height}.`);
  }
}

// Only check the URL rewriting (for golden-dom-selftest.mjs):
//   node golden-dom.mjs --rewrites <file.json> --rewrite-probe /assets/x.png [--rewrite-probe …]
// prints one line "<input>\t<result>" per value and exits with 0.
const rewriteProbes = argAll('rewrite-probe');

const referenceArg = arg('reference', '');
if (!referenceArg && !rewriteProbes.length) usage('Missing: --reference <directory of the reference export>');
const candidateArg = arg('candidate', '');
if (!candidateArg && !rewriteProbes.length) usage('Missing: --candidate <directory or base URL>');

const onlyFilter = arg('only', '');
const mapFiles = argAll('map').length ? argAll('map') : [path.join(verifyDir, 'class-map.json')];
const allowFiles = argAll('allow').length ? argAll('allow') : [path.join(verifyDir, 'allow/golden-dom.json')];
const reportDir = resolveHome(arg('report', path.join(process.cwd(), '.build/golden-dom')));
const strictClasses = flag('strict-classes');
const ignoreClasses = flag('ignore-classes');
const candidateFileJs = flag('candidate-file-js');
const dropStyleTags = flag('drop-style-tags');
const dumpFile = arg('dump', '');
const dumpSide = arg('dump-side', 'reference');
// Compare a subtree only: the first node the selector matches becomes the root of the
// comparison on both sides (for example "article#nd-page div.prose").
const scopeSelector = arg('scope', '');

// Reference → candidate URL rewrites from --rewrites files and --rewrite flags;
// --no-rewrite turns everything off (self comparison).
let rewrites;
try {
  rewrites = buildRewrites();
} catch (err) {
  usage(err.message);
}

if (rewriteProbes.length) {
  for (const value of rewriteProbes) console.log(`${value}\t${rewriteUrl(value)}`);
  process.exit(0);
}

// ---- Normalisation rules ----------------------------------------------------

// These elements disappear completely on both sides.
const DROP_TAGS = new Set(['script', 'noscript', 'template', 'title', 'nextjs-portal']);
// Of <meta>, only these remain.
const META_KEEP = new Set(['charset', 'viewport', 'description']);
// These <link rel="…"> are pure Next loading optimisation.
const DROP_LINK_REL = new Set(['preload', 'stylesheet', 'modulepreload', 'prefetch', 'preconnect', 'dns-prefetch']);
// Attributes a static build can't have and that say nothing about the page.
const DROP_ATTRS = new Set(['data-precedence', 'data-href', 'suppresshydrationwarning', 'nonce', 'fetchpriority']);
// Attributes whose value contains URLs and is therefore rewritten.
const URL_ATTRS = new Set(['href', 'src', 'action', 'poster']);
// Attributes in which hydration ids can appear.
const ID_ATTRS = new Set(['id', 'aria-controls', 'aria-labelledby', 'aria-describedby', 'for', 'data-id', 'aria-owns', 'aria-activedescendant', 'form', 'list', 'headers']);
// Block elements: only between them may pure whitespace be dropped.
const BLOCK_TAGS = new Set([
  'html', 'head', 'body', 'div', 'section', 'article', 'aside', 'nav', 'header', 'footer', 'main',
  'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th',
  'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'blockquote', 'figure', 'figcaption',
  'form', 'fieldset', 'hr', 'svg', 'g', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'defs', 'style', 'link', 'meta',
]);

// Base UI and React hydration ids, both spellings of React `useId`:
//   `_R_…_`   rendered on the server (also as part of `base-ui-_R_…_-viewport`),
//   `_r_<n>_` created only in the browser, for example on the dialog portal `<div id="_r_2_">`
//             and on the dialog title `<h2 id="base-ui-_r_3_">`. The counter of this form
//             depends on how many useId calls the page made before and differs between two
//             sessions; without normalisation, open overlays stay red.
// The random part can contain uppercase letters (for example `_R_…H1_`, `_R_…H2_` on the
// catalogue components); without them such ids and every reference to them
// (aria-controls, aria-labelledby, …) would stay raw and be reported as differences.
const HYDRATION_ID = /_[Rr]_[0-9a-zA-Z]*_/g;

// ---- Finding pages ----------------------------------------------------------

// The export has the form <root>/pages/<path>/dom.html (or the name from --dom). Either the
// export root folder or the pages directory itself may be passed.
async function referencePagesDir(root) {
  if (await exists(path.join(root, 'pages'))) return path.join(root, 'pages');
  return root;
}

async function findPages(pagesDir) {
  const found = [];
  async function walk(dir) {
    const entries = await fs.readdir(dir, { withFileTypes: true });
    if (entries.some((e) => e.isFile() && e.name === domName)) {
      found.push(path.relative(pagesDir, dir).split(path.sep).join('/'));
    }
    for (const e of entries) if (e.isDirectory()) await walk(path.join(dir, e.name));
  }
  await walk(pagesDir);
  return found.sort();
}

// The reference path (for example reference/docs/guide) becomes the candidate path (for
// example docs/guide) through the same rules as the links.
function candidateRelPath(refRel) {
  let p = `/${refRel}`;
  for (const [from, to] of rewrites) if (p.startsWith(from)) p = to + p.slice(from.length);
  return p.replace(/^\/+/, '');
}

async function candidateSource(refRel) {
  const rel = candidateRelPath(refRel);
  if (isUrl(candidateArg)) {
    const base = candidateArg.replace(/\/+$/, '');
    // The base URL already contains the target path; duplicated prefixes are shortened.
    const basePath = new URL(base).pathname.replace(/^\/+|\/+$/g, '');
    let tail = rel;
    if (basePath && (tail === basePath || tail.startsWith(`${basePath}/`))) tail = tail.slice(basePath.length).replace(/^\/+/, '');
    return { kind: 'url', ref: `${base}${tail ? `/${tail}` : ''}/`.replace(/([^:])\/\/+/g, '$1/') };
  }
  const dir = resolveHome(candidateArg);
  const named = [
    path.join(dir, rel, candidateDomName),
    path.join(dir, 'pages', refRel, candidateDomName),
    path.join(dir, refRel, candidateDomName),
  ];
  const order = candidateDomExplicit ? named : [path.join(dir, rel, 'index.html'), ...named];
  for (const candidate of order) {
    if (await exists(candidate)) return { kind: 'file', ref: candidate };
  }
  return { kind: 'missing', ref: order[0] };
}

// ---- Loading ------------------------------------------------------------------

// Raw tree of a loaded document: elements, attributes, text nodes. Comments are dropped
// here already.
const EXTRACT = `(() => {
  function node(el) {
    const attrs = {};
    for (const a of el.attributes) attrs[a.name.toLowerCase()] = a.value;
    const children = [];
    for (const child of el.childNodes) {
      if (child.nodeType === 1) children.push(node(child));
      else if (child.nodeType === 3) children.push({ text: child.nodeValue });
    }
    return { tag: el.tagName.toLowerCase(), attrs, children };
  }
  return node(document.documentElement);
})()`;

function stripScripts(html) {
  return html
    .replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi, '')
    .replace(/<script\b[^>]*\/>/gi, '');
}

async function loadTree(browser, source, { runScripts }) {
  const context = await browser.newContext({ viewport: VIEWPORT, javaScriptEnabled: runScripts });
  const page = await context.newPage();
  try {
    if (source.kind === 'url') {
      const response = await page.goto(source.ref, { waitUntil: 'networkidle' });
      if (response && response.status() >= 400) throw new Error(`HTTP ${response.status()} for ${source.ref}`);
      await page.waitForTimeout(500);
    } else if (runScripts) {
      await page.goto(`file://${source.ref}`, { waitUntil: 'networkidle' });
      await page.waitForTimeout(500);
    } else {
      const html = stripScripts(await fs.readFile(source.ref, 'utf8'));
      await page.setContent(html, { waitUntil: 'domcontentloaded' });
    }
    return await page.evaluate(EXTRACT);
  } finally {
    await context.close();
  }
}

// ---- Normalisation --------------------------------------------------------------

function rewriteUrl(value) {
  // Absolute external URLs stay verbatim.
  if (/^[a-z][a-z0-9+.-]*:/i.test(value) || value.startsWith('//')) return value;
  return rewritePath(value, rewrites);
}

function rewriteSrcset(value) {
  return value
    .split(',')
    .map((part) => {
      const t = part.trim();
      if (!t) return t;
      const sp = t.indexOf(' ');
      return sp < 0 ? rewriteUrl(t) : `${rewriteUrl(t.slice(0, sp))} ${t.slice(sp + 1).trim()}`;
    })
    .filter(Boolean)
    .join(', ');
}

// style="a:1;b:2" → { a: '1', b: '2' }, order and whitespace don't matter.
function parseStyle(value) {
  const out = {};
  let depth = 0;
  let current = '';
  const parts = [];
  for (const ch of value) {
    if (ch === '(') depth += 1;
    if (ch === ')') depth -= 1;
    if (ch === ';' && depth === 0) {
      parts.push(current);
      current = '';
    } else current += ch;
  }
  parts.push(current);
  for (const part of parts) {
    const i = part.indexOf(':');
    if (i < 0) continue;
    const name = part.slice(0, i).trim();
    if (!name) continue;
    const isCustom = name.startsWith('--');
    out[isCustom ? name : name.toLowerCase()] = part.slice(i + 1).trim().replace(/\s+/g, ' ');
  }
  return out;
}

function classTokens(value) {
  return value.split(/\s+/).filter(Boolean).sort();
}

function shouldDrop(tag, attrs) {
  if (DROP_TAGS.has(tag)) return true;
  if (tag === 'style' && dropStyleTags) return true;
  if (tag === 'meta') {
    if ('charset' in attrs) return false;
    const name = (attrs.name || '').toLowerCase();
    return !META_KEEP.has(name);
  }
  if (tag === 'link') {
    const rels = (attrs.rel || '').toLowerCase().split(/\s+/).filter(Boolean);
    return rels.some((r) => DROP_LINK_REL.has(r));
  }
  for (const name of Object.keys(attrs)) if (name.startsWith('data-nextjs')) return true;
  return false;
}

function normalise(raw) {
  // Registry of hydration ids, per document.
  const ids = new Map();

  // The ordinal follows the tree position of the element that CARRIES the id, not the
  // first occurrence of the string anywhere in the document. Otherwise the same id gets
  // different numbers on the two sides as soon as it appears first as a reference once
  // (aria-controls sorts before id) and first on the element itself the other time, which
  // is exactly what happens with popups whose id only exists once they open.
  (function collectOwners(node) {
    const own = node.attrs.id;
    if (typeof own === 'string') {
      for (const match of own.match(HYDRATION_ID) ?? []) {
        if (!ids.has(match)) ids.set(match, `#id${ids.size + 1}`);
      }
    }
    for (const child of node.children) {
      if (child.text === undefined && !shouldDrop(child.tag, child.attrs)) collectOwners(child);
    }
  })(raw);

  // Only referenced ids without an element of their own get their number afterwards, in
  // order of appearance.
  function token(value) {
    return value.replace(HYDRATION_ID, (match) => {
      if (!ids.has(match)) ids.set(match, `#id${ids.size + 1}`);
      return ids.get(match);
    });
  }

  function attributes(tag, rawAttrs) {
    const out = {};
    for (const name of Object.keys(rawAttrs).sort()) {
      if (DROP_ATTRS.has(name) || name.startsWith('data-nextjs')) continue;
      let value = rawAttrs[name];
      if (ID_ATTRS.has(name)) value = token(value);
      if (URL_ATTRS.has(name)) value = rewriteUrl(token(value));
      else if (name === 'srcset' || name === 'imagesrcset') value = rewriteSrcset(value);
      if (name === 'class') {
        if (ignoreClasses) continue;
        out.class = { kind: 'class', tokens: classTokens(value) };
        continue;
      }
      if (name === 'style') {
        out.style = { kind: 'style', decls: parseStyle(token(value)) };
        continue;
      }
      // Normalise boolean attributes: hidden, hidden="" and hidden="hidden" are the same.
      if (value === '' || value.toLowerCase() === name) value = '';
      else value = value.replace(/\s+/g, ' ').trim();
      out[name] = { kind: 'text', value };
    }
    return out;
  }

  function element(node) {
    const tag = node.tag;
    const attrs = attributes(tag, node.attrs);
    const children = [];
    for (const child of node.children) {
      if (child.text !== undefined) {
        children.push({ text: child.text.replace(/\s+/g, ' ') });
      } else if (!shouldDrop(child.tag, child.attrs)) {
        children.push(element(child));
      }
    }
    // Pure whitespace text nodes between block elements are dropped; between inline
    // elements they stay as one space because they are visible there.
    const kept = [];
    for (let i = 0; i < children.length; i += 1) {
      const child = children[i];
      if (child.text === undefined || child.text.trim() !== '') {
        kept.push(child);
        continue;
      }
      const before = children[i - 1];
      const after = children[i + 1];
      const blockish = (n) => n === undefined || (n.tag !== undefined && BLOCK_TAGS.has(n.tag));
      if (blockish(before) && blockish(after)) continue;
      if (BLOCK_TAGS.has(tag) && (before === undefined || after === undefined) && children.length === 1) continue;
      kept.push({ text: ' ' });
    }
    return { tag, attrs, children: kept };
  }

  const root = element(raw);
  return { root, ids };
}

// ---- Allow list -------------------------------------------------------------------

// A very small selector dialect: one compound of tag, .class, #id and [attr],
// [attr=value], [attr^=value], [attr$=value], [attr*=value]; several selectors separated
// by commas; no combinators. `*` matches everything.
function parseSelector(selector) {
  return selector.split(',').map((part) => {
    const s = part.trim();
    const compound = { tag: null, classes: [], attrs: [] };
    let rest = s;
    const tagMatch = rest.match(/^([a-zA-Z][\w-]*|\*)/);
    if (tagMatch) {
      if (tagMatch[1] !== '*') compound.tag = tagMatch[1].toLowerCase();
      rest = rest.slice(tagMatch[1].length);
    }
    const re = /\.([\w:.\\/[\]()%-]+)|#([\w-]+)|\[([\w:-]+)(?:([~^$*|]?=)"?([^\]"]*)"?)?\]/g;
    let m;
    while ((m = re.exec(rest))) {
      if (m[1] !== undefined) compound.classes.push(m[1].replace(/\\/g, ''));
      else if (m[2] !== undefined) compound.attrs.push({ name: 'id', op: '=', value: m[2] });
      else compound.attrs.push({ name: m[3].toLowerCase(), op: m[4] ?? null, value: m[5] ?? null });
    }
    return compound;
  });
}

function attrString(node, name) {
  const a = node.attrs[name];
  if (a === undefined) return undefined;
  if (a.kind === 'class') return a.tokens.join(' ');
  if (a.kind === 'style') return Object.entries(a.decls).map(([k, v]) => `${k}:${v}`).join(';');
  return a.value;
}

function matches(node, compounds) {
  if (node.tag === undefined) return false;
  return compounds.some((c) => {
    if (c.tag && c.tag !== node.tag) return false;
    const classes = node.attrs.class?.tokens ?? [];
    if (c.classes.some((cl) => !classes.includes(cl))) return false;
    return c.attrs.every(({ name, op, value }) => {
      const actual = attrString(node, name);
      if (actual === undefined) return false;
      if (!op) return true;
      if (op === '=') return actual === value;
      if (op === '^=') return actual.startsWith(value);
      if (op === '$=') return actual.endsWith(value);
      if (op === '*=') return actual.includes(value);
      if (op === '~=') return actual.split(/\s+/).includes(value);
      return false;
    });
  });
}

// Prepare allow entries. An entry with "attribute": "#node" may also carry "kind":
//   "extra"   allows only a node the candidate has and the reference doesn't,
//   "missing" only a node the reference has and the candidate doesn't,
//   "both"    both; without "kind", "both" applies.
// This keeps, for example, the drawer Pholio emits additionally at 1440 px allowed, while
// its absence at 390 px (where the reference has it) stays red.
// An unknown value, or "kind" on an entry without #node, aborts the run.
const NODE_KINDS = new Set(['extra', 'missing', 'both']);

function prepareAllow(entries) {
  return entries
    .filter((e) => e && typeof e === 'object' && e.selector)
    .map((e) => {
      if (e.kind !== undefined) {
        if (e.attribute !== '#node') {
          throw new Error(`Allow entry "${e.selector}": "kind" only exists with "attribute": "#node", got it with "${e.attribute ?? '*'}".`);
        }
        if (!NODE_KINDS.has(e.kind)) {
          throw new Error(`Allow entry "${e.selector}": "kind" must be extra, missing or both, got: ${JSON.stringify(e.kind)}.`);
        }
      }
      return {
        ...e,
        nodeKind: e.kind ?? 'both',
        compounds: parseSelector(e.selector),
        pathRe: e.path ? new RegExp(e.path) : null,
        properties: Array.isArray(e.properties) ? new Set(e.properties) : null,
      };
    });
}

// Find the matching allow entry for a difference. Returns the entry (with its reason) or
// null.
//   - Missing and extra nodes (missing, extra) are only allowed by a #node entry, and only
//     when its "kind" matches the direction.
//   - Attribute differences (attr) are only allowed by entries with an attribute name or "*".
//   - Text and tag differences are never allowed. A #node entry must not be able to waive
//     them: otherwise one entry, such as the one for the drawer, would cancel every text
//     difference on every page.
function allowedBy(allow, { node, nodePath, diffKind, attribute, property }) {
  if (!node) return null;
  const nodeDiff = diffKind === 'missing' || diffKind === 'extra';
  if (!nodeDiff && diffKind !== 'attr') return null;
  for (const entry of allow) {
    if (entry.pathRe && !entry.pathRe.test(nodePath)) continue;
    if (!matches(node, entry.compounds)) continue;
    const wanted = entry.attribute ?? '*';
    if (wanted === '#node') {
      if (nodeDiff && (entry.nodeKind === 'both' || entry.nodeKind === diffKind)) return entry;
      continue;
    }
    if (nodeDiff) continue;
    if (wanted !== '*' && wanted !== attribute) continue;
    if (entry.properties && property !== undefined && !entry.properties.has(property)) continue;
    if (entry.properties && property === undefined) continue;
    return entry;
  }
  return null;
}

// ---- Subtree (--scope) ----------------------------------------------------------

// Descendant selector: compounds separated by whitespace, each as in parseSelector.
// Combinators such as > or + don't exist.
function parseScope(selector) {
  return selector
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .map((part) => parseSelector(part));
}

// First node in document order the last compound matches and that has a matching ancestor
// for every earlier compound.
function findScope(root, steps) {
  let found = null;
  function walk(node, depth) {
    if (found || node.tag === undefined) return;
    const next = depth < steps.length && matches(node, steps[depth]) ? depth + 1 : depth;
    if (next === steps.length) {
      found = node;
      return;
    }
    for (const child of node.children) {
      walk(child, next);
      if (found) return;
    }
  }
  walk(root, 0);
  return found;
}

// Before comparing, the id tokens are renumbered over the compared tree (with --scope only
// over the subtree). Otherwise every id outside the excerpt that exists on one side only
// shifts all numbers and reports differences that aren't any.
// Order: first the ids with an element of their own in tree order, then the referenced ones.
//
// Nodes covered by a #node allow entry (for example the drawer only Pholio emits) don't
// count: their ids get tokens of their own, #skip1, #skip2 … Without this exception an
// allowed extra node would shift the numbers of every later id and produce exactly the
// differences its allow entry is meant to avoid.
// An entry with path is checked against the natural path (position among all child nodes,
// as in the report, as long as no node before it is missing or extra).
// "kind" deliberately plays no role here: the exception applies equally on both sides so the
// numbering stays symmetric. Whether a missing node is still red is decided by allowedBy alone.
function renumberIds(root, allow = []) {
  const nodeEntries = allow.filter((e) => e.attribute === '#node');
  const remap = new Map();
  let main = 0;
  let skipped = 0;
  function isSkipped(node, nodePath) {
    return nodeEntries.some((e) => (!e.pathRe || e.pathRe.test(nodePath)) && matches(node, e.compounds));
  }
  function claim(value, inSkip) {
    for (const match of value.match(/#id\d+/g) ?? []) {
      if (remap.has(match)) continue;
      if (inSkip) remap.set(match, `#skip${(skipped += 1)}`);
      else remap.set(match, `#id${(main += 1)}`);
    }
  }
  function walk(node, nodePath, inSkip, visit) {
    if (node.tag === undefined) return;
    const skip = inSkip || isSkipped(node, nodePath);
    visit(node, skip);
    node.children.forEach((child, i) => {
      if (child.tag !== undefined) walk(child, `${nodePath} > ${nodeLabel(child)}:nth-child(${i + 1})`, skip, visit);
    });
  }
  // Pass 1: id carriers outside allowed nodes, pass 2: carriers inside them.
  walk(root, nodeLabel(root), false, (node, skip) => {
    if (!skip && node.attrs.id) claim(node.attrs.id.value, false);
  });
  walk(root, nodeLabel(root), false, (node, skip) => {
    if (skip && node.attrs.id) claim(node.attrs.id.value, true);
  });
  // Pass 3: rewrite every value; only referenced ids are numbered here.
  walk(root, nodeLabel(root), false, (node, skip) => {
    for (const [name, attr] of Object.entries(node.attrs)) {
      if (attr.kind === 'text') {
        claim(attr.value, skip);
        node.attrs[name] = { kind: 'text', value: attr.value.replace(/#id\d+/g, (m) => remap.get(m)) };
      } else if (attr.kind === 'style') {
        for (const key of Object.keys(attr.decls)) {
          claim(attr.decls[key], skip);
          attr.decls[key] = attr.decls[key].replace(/#id\d+/g, (m) => remap.get(m));
        }
      }
    }
  });
  return root;
}

// ---- Comparison -----------------------------------------------------------------

function nodeKey(node) {
  return node.tag === undefined ? '#text' : node.tag;
}

function nodeLabel(node) {
  if (node.tag === undefined) return '#text';
  const cls = node.attrs.class?.tokens?.[0];
  const id = node.attrs.id?.value;
  return node.tag + (id ? `#${id}` : '') + (cls ? `.${cls.replace(/[^\w-]/g, '_')}` : '');
}

function childPath(parentPath, node, index) {
  return `${parentPath} > ${nodeLabel(node)}:nth-child(${index + 1})`;
}

// Longest common subsequence over the child nodes, so that one missing or extra node
// doesn't report everything after it as a difference.
function align(a, b) {
  const n = a.length;
  const m = b.length;
  const dp = Array.from({ length: n + 1 }, () => new Int32Array(m + 1));
  for (let i = n - 1; i >= 0; i -= 1) {
    for (let j = m - 1; j >= 0; j -= 1) {
      dp[i][j] = nodeKey(a[i]) === nodeKey(b[j]) ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
    }
  }
  const pairs = [];
  let i = 0;
  let j = 0;
  while (i < n && j < m) {
    if (nodeKey(a[i]) === nodeKey(b[j])) pairs.push([i, j]), (i += 1), (j += 1);
    else if (dp[i + 1][j] >= dp[i][j + 1]) pairs.push([i, null]), (i += 1);
    else pairs.push([null, j]), (j += 1);
  }
  while (i < n) pairs.push([i, null]), (i += 1);
  while (j < m) pairs.push([null, j]), (j += 1);
  return pairs;
}

function compareTrees(refRoot, candRoot, { classMap, allow, unmapped }) {
  const diffs = [];

  function record(diff, node) {
    const entry = allowedBy(allow, { node, nodePath: diff.path, diffKind: diff.kind, attribute: diff.attribute, property: diff.property });
    if (entry) diffs.push({ ...diff, allowed: true, reason: entry.reason ?? '' });
    else diffs.push({ ...diff, allowed: false });
  }

  function compareClasses(refNode, candNode, nodePath) {
    if (ignoreClasses) return;
    const refClass = refNode.attrs.class;
    const candClass = candNode.attrs.class;
    if (!refClass && !candClass) return;
    const refTokens = refClass?.tokens ?? [];
    const candTokens = candClass?.tokens ?? [];
    const key = refTokens.join(' ');
    const mapped = Object.prototype.hasOwnProperty.call(classMap, key) ? classMap[key] : undefined;
    if (mapped === undefined) {
      if (key) unmapped.set(key, (unmapped.get(key) ?? 0) + 1);
      if (!strictClasses) return;
      if (refTokens.join(' ') !== candTokens.join(' ')) {
        record({ kind: 'attr', path: nodePath, attribute: 'class', reference: key, candidate: candTokens.join(' ') }, refNode);
      }
      return;
    }
    const expected = classTokens(String(mapped)).join(' ');
    if (expected !== candTokens.join(' ')) {
      record({ kind: 'attr', path: nodePath, attribute: 'class', reference: `${key}  →  ${expected}`, candidate: candTokens.join(' ') }, refNode);
    }
  }

  function compareStyle(refNode, candNode, nodePath) {
    const refDecls = refNode.attrs.style?.decls ?? null;
    const candDecls = candNode.attrs.style?.decls ?? null;
    if (!refDecls && !candDecls) return;
    const names = new Set([...Object.keys(refDecls ?? {}), ...Object.keys(candDecls ?? {})]);
    for (const name of [...names].sort()) {
      const r = refDecls?.[name];
      const c = candDecls?.[name];
      if (r === c) continue;
      record(
        { kind: 'attr', path: nodePath, attribute: 'style', property: name, reference: r === undefined ? null : `${name}: ${r}`, candidate: c === undefined ? null : `${name}: ${c}` },
        refNode,
      );
    }
  }

  function compareNodes(refNode, candNode, nodePath) {
    if (refNode.tag === undefined || candNode.tag === undefined) {
      if (refNode.text !== candNode.text) {
        record({ kind: 'text', path: nodePath, reference: refNode.text, candidate: candNode.text }, null);
      }
      return;
    }
    if (refNode.tag !== candNode.tag) {
      record({ kind: 'tag', path: nodePath, reference: refNode.tag, candidate: candNode.tag }, refNode);
      return;
    }
    const names = new Set([...Object.keys(refNode.attrs), ...Object.keys(candNode.attrs)]);
    for (const name of [...names].sort()) {
      if (name === 'class') continue;
      if (name === 'style') continue;
      const r = refNode.attrs[name];
      const c = candNode.attrs[name];
      if (r && c && r.value === c.value) continue;
      record({ kind: 'attr', path: nodePath, attribute: name, reference: r ? r.value : null, candidate: c ? c.value : null }, refNode);
    }
    compareClasses(refNode, candNode, nodePath);
    compareStyle(refNode, candNode, nodePath);

    const pairs = align(refNode.children, candNode.children);
    let elementIndex = 0;
    for (const [ri, ci] of pairs) {
      const child = ri !== null ? refNode.children[ri] : candNode.children[ci];
      const p = childPath(nodePath, child, elementIndex);
      elementIndex += 1;
      if (ri === null) record({ kind: 'extra', path: p, reference: null, candidate: nodeLabel(candNode.children[ci]) }, candNode.children[ci]);
      else if (ci === null) record({ kind: 'missing', path: p, reference: nodeLabel(refNode.children[ri]), candidate: null }, refNode.children[ri]);
      else compareNodes(refNode.children[ri], candNode.children[ci], p);
    }
  }

  compareNodes(refRoot, candRoot, nodeLabel(refRoot));
  return diffs;
}

// ---- Output -----------------------------------------------------------------------

function dumpTree(node, depth = 0, out = []) {
  const pad = '  '.repeat(depth);
  if (node.tag === undefined) {
    out.push(`${pad}"${node.text}"`);
    return out;
  }
  const attrs = Object.keys(node.attrs)
    .sort()
    .map((name) => {
      const a = node.attrs[name];
      if (a.kind === 'class') return `class="${a.tokens.join(' ')}"`;
      if (a.kind === 'style') return `style="${Object.keys(a.decls).sort().map((k) => `${k}: ${a.decls[k]}`).join('; ')}"`;
      return `${name}="${a.value}"`;
    })
    .join(' ');
  out.push(`${pad}<${node.tag}${attrs ? ` ${attrs}` : ''}>`);
  for (const child of node.children) dumpTree(child, depth + 1, out);
  return out;
}

function short(value, max = 120) {
  if (value === null || value === undefined) return '—';
  const s = String(value).replace(/\s+/g, ' ');
  return s.length > max ? `${s.slice(0, max)}…` : s;
}

async function writeReport(rel, body) {
  const file = path.join(reportDir, `${rel}.json`);
  await fs.mkdir(path.dirname(file), { recursive: true });
  await fs.writeFile(file, `${JSON.stringify(body, null, 2)}\n`);
  return file;
}

// ---- Main run -----------------------------------------------------------------------

async function main() {
  const refRoot = resolveHome(referenceArg);
  const pagesDir = await referencePagesDir(refRoot);
  let pages = await findPages(pagesDir);
  if (!pages.length) throw new Error(`No ${domName} found under ${pagesDir}.`);
  if (onlyFilter) {
    const wanted = onlyFilter.split(',').map((s) => s.trim()).filter(Boolean);
    pages = pages.filter((p) => wanted.some((w) => p === w || p.endsWith(`/${w}`) || p.includes(w)));
    if (!pages.length) throw new Error(`--only ${onlyFilter} matches no reference page.`);
  }

  const classMap = {};
  for (const file of mapFiles) Object.assign(classMap, await readJson(resolveHome(file)));
  delete classMap._comment;
  const allowEntries = [];
  for (const file of allowFiles) {
    const raw = await readJson(resolveHome(file));
    allowEntries.push(...(Array.isArray(raw) ? raw : raw.entries ?? []));
  }
  let allow;
  try {
    allow = prepareAllow(allowEntries);
  } catch (err) {
    usage(err.message);
  }
  const scopeSteps = scopeSelector ? parseScope(scopeSelector) : null;

  const browser = await launchBrowser();

  await fs.mkdir(reportDir, { recursive: true });
  const unmapped = new Map();
  const allowedSeen = [];
  let red = 0;
  let green = 0;

  try {
    for (const rel of pages) {
      const refFile = path.join(pagesDir, rel, domName);
      const cand = await candidateSource(rel);
      if (cand.kind === 'missing') {
        red += 1;
        console.log(`✗ ${rel} – candidate missing: ${cand.ref}`);
        await writeReport(rel, { page: rel, error: `candidate missing: ${cand.ref}`, diffs: [] });
        continue;
      }

      let refTree;
      let candTree;
      try {
        refTree = normalise(await loadTree(browser, { kind: 'file', ref: refFile }, { runScripts: false }));
        candTree = normalise(
          await loadTree(browser, cand, { runScripts: cand.kind === 'url' ? true : candidateFileJs }),
        );
      } catch (err) {
        red += 1;
        console.log(`✗ ${rel} – loading failed: ${err.message}`);
        await writeReport(rel, { page: rel, error: err.message, diffs: [] });
        continue;
      }

      if (dumpFile && (onlyFilter || pages.length === 1)) {
        const tree = dumpSide === 'candidate' ? candTree.root : refTree.root;
        await fs.writeFile(resolveHome(dumpFile), `${dumpTree(tree).join('\n')}\n`);
        console.log(`Tree (${dumpSide}) of ${rel} written: ${resolveHome(dumpFile)}`);
      }

      let refScope = refTree.root;
      let candScope = candTree.root;
      if (scopeSteps) {
        refScope = findScope(refTree.root, scopeSteps);
        candScope = findScope(candTree.root, scopeSteps);
        if (!refScope || !candScope) {
          red += 1;
          const side = !refScope ? 'reference' : 'candidate';
          console.log(`✗ ${rel} – --scope "${scopeSelector}" matches no node on the ${side} side`);
          await writeReport(rel, { page: rel, error: `--scope "${scopeSelector}" without a match on the ${side} side`, diffs: [] });
          continue;
        }
      }
      renumberIds(refScope, allow);
      renumberIds(candScope, allow);

      const diffs = compareTrees(refScope, candScope, { classMap, allow, unmapped });
      const blocking = diffs.filter((d) => !d.allowed);
      for (const d of diffs) if (d.allowed) allowedSeen.push({ page: rel, ...d });

      const reportFile = await writeReport(rel, {
        page: rel, reference: refFile, candidate: cand.ref, dom: domName, viewport: `${VIEWPORT.width}x${VIEWPORT.height}`, hydrationIds: refTree.ids.size, diffs,
      });

      if (blocking.length) {
        red += 1;
        console.log(`✗ ${rel} – ${blocking.length} difference(s)`);
        for (const d of blocking.slice(0, 10)) {
          const attr = d.attribute ? ` [${d.attribute}${d.property ? `/${d.property}` : ''}]` : '';
          console.log(`    ${d.kind}${attr} ${d.path}`);
          console.log(`      reference: ${short(d.reference)}`);
          console.log(`      candidate: ${short(d.candidate)}`);
        }
        if (blocking.length > 10) console.log(`    … ${blocking.length - 10} more, see ${reportFile}`);
      } else {
        green += 1;
        console.log(`✓ ${rel}`);
      }
    }
  } finally {
    await browser.close();
  }

  if (allowedSeen.length) {
    console.log(`\nAllowed differences (${allowedSeen.length}) – they don't fail, but they are always shown:`);
    for (const d of allowedSeen) {
      const attr = d.attribute ? ` [${d.attribute}${d.property ? `/${d.property}` : ''}]` : '';
      console.log(`  ${d.page}: ${d.kind}${attr} ${d.path} – ${d.reason || 'no reason given'}`);
    }
  }

  if (unmapped.size) {
    const file = path.join(reportDir, '_unmapped-classes.json');
    const sorted = [...unmapped.entries()].sort((a, b) => b[1] - a[1]);
    await fs.writeFile(file, `${JSON.stringify(Object.fromEntries(sorted.map(([k, v]) => [k, v])), null, 2)}\n`);
    console.log(`\nUnmapped classes: ${unmapped.size} distinct class lists without an entry in ${mapFiles.join(', ')} → ${file}`);
    for (const [key, count] of sorted.slice(0, 10)) console.log(`  ${count}×  ${short(key, 100)}`);
  }

  console.log(`\nResult: ${green} green, ${red} red, ${green + red} pages. Reports: ${reportDir}`);
  process.exitCode = red ? 1 : 0;
}

main().catch((err) => {
  console.error(err.stack || err.message);
  process.exit(2);
});
