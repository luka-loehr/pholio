// The page list of the verification: every address of the reference export.
//
// The source is the frozen page tree of the reference export
// (`reference/<commit>/tree.json`). It lists the document pages; pages that don't
// appear in the tree but are checked as well (a docs root, a start page) are passed in
// as `extraPages`, taken from the rewrites file or --extra-pages (see cli.mjs).
//
// The candidate page is derived from the same rewrite table as the links in
// golden-dom.mjs, so `--rewrite` and `--rewrites` act the same everywhere.

import path from 'node:path';
import { readJson, resolveHome, exists, isUrl, rewritePath, joinUrl } from './cli.mjs';

// tree.json is given either directly or as the export root folder.
export async function resolveTreeFile(spec) {
  const p = resolveHome(spec);
  if (p.endsWith('.json')) return p;
  const inRoot = path.join(p, 'tree.json');
  if (await exists(inRoot)) return inRoot;
  throw new Error(`No tree.json found under ${p}.`);
}

// Every `page` and `index` address of the tree, in tree order, without duplicates.
export function pagesFromTree(tree) {
  const out = [];
  const seen = new Set();
  const push = (url) => {
    if (typeof url !== 'string' || !url || seen.has(url)) return;
    seen.add(url);
    out.push(url);
  };
  (function walk(nodes) {
    for (const node of nodes ?? []) {
      if (!node || typeof node !== 'object') continue;
      push(node.page);
      push(node.index);
      if (Array.isArray(node.children)) walk(node.children);
    }
  })(Array.isArray(tree) ? tree : (tree.children ?? []));
  return out;
}

// Complete reference list: the extra pages first, then the tree.
export async function loadPageList(treeSpec, extraPages = []) {
  const file = await resolveTreeFile(treeSpec);
  const pages = pagesFromTree(await readJson(file));
  const all = [...extraPages, ...pages];
  return [...new Set(all)];
}

// --only accepts a comma list of full paths, trailing segments or substrings, the same
// selection as in golden-dom.mjs. A leading `=` additionally means "exactly this path":
// `--only /docs` would match every page below /docs as a substring, `--only =/docs`
// matches only that page.
export function filterPages(pages, only) {
  if (!only) return pages;
  const wanted = only.split(',').map((s) => s.trim()).filter(Boolean);
  const hit = pages.filter((p) =>
    wanted.some((w) => (w.startsWith('=') ? p === w.slice(1) : p === w || p.endsWith(`/${w}`) || p.includes(w))),
  );
  if (!hit.length) throw new Error(`--only ${only} matches no reference page.`);
  return hit;
}

// Short name for report files: /docs/guide/install → docs/guide/install,
// the root page is called `index`.
export function pageSlug(refPath) {
  const rel = refPath.replace(/^\/+|\/+$/g, '');
  return rel || 'index';
}

// A page pair: both full URLs.
export function pageUrls(refPath, { referenceBase, candidateBase, rewrites }) {
  if (!isUrl(referenceBase)) throw new Error(`--reference needs an http(s) base URL, got: ${referenceBase}`);
  if (!isUrl(candidateBase)) throw new Error(`--candidate needs an http(s) base URL, got: ${candidateBase}`);
  return {
    path: refPath,
    slug: pageSlug(refPath),
    reference: joinUrl(referenceBase, refPath),
    candidate: joinUrl(candidateBase, rewritePath(refPath, rewrites)),
  };
}
