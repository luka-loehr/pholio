// Playwright resolution for the verify tools, first hit wins:
//
//   1. PHOLIO_PLAYWRIGHT: the playwright package directory or its index.mjs.
//   2. node_modules/playwright in verify/ and in every directory above it. This covers
//      `cd verify && npm ci` in a Pholio checkout as well as a copy of Pholio vendored
//      into another repository whose own node_modules sits further up.
//   3. The given root directory, then process.cwd(), each with node_modules/playwright.

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

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
