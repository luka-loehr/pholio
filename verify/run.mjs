#!/usr/bin/env node
// Runs every Node check of Pholio in order and reports each one.
//
//   node verify/run.mjs [--skip-browsers]
//
// Needs PHP on PATH, `npm ci` in verify/ and, unless --skip-browsers, the Playwright browsers
// (`npx playwright install chromium-headless-shell firefox webkit`).
//
//   search-check.mjs --selftest      search.js and SearchIndex.php normalise, split and encode identically
//   search-check.mjs --consistency   search.js rebuilds the demo index from the page texts
//   search-check.mjs --relevance     the demo queries in fixtures/search-relevance.json rank as expected
//   search-dialog.mjs                the search dialog in Chromium, Firefox and WebKit under a subpath
//   agent-score.mjs --demo           the demo's files for AI agents score 100
//   lucide-oracle.mjs --check --all  every icon's markup matches lucide-react
//
// Exit codes: 0 green, 1 a check failed.

import { spawnSync } from 'node:child_process';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const verifyDir = path.dirname(fileURLToPath(import.meta.url));
const pholioRoot = path.resolve(verifyDir, '..');
const skipBrowsers = process.argv.includes('--skip-browsers');

const demo = ['--content', 'examples/demo/content', '--base-url', '/', '--tokenizer', 'english'];
const checks = [
  ['search-check.mjs', ['--selftest']],
  ['search-check.mjs', ['--consistency', ...demo]],
  ['search-check.mjs', ['--relevance', ...demo, '--fixture', 'verify/fixtures/search-relevance.json']],
  ...(skipBrowsers ? [] : [['search-dialog.mjs', []]]),
  ['agent-score.mjs', ['--demo', '--min-score', '100']],
  // An explicit seed fixes the class names paired with each icon, so a failure reproduces.
  ['lucide-oracle.mjs', ['--check', '--all', '--seed', '1']],
];

let failed = 0;
for (const [tool, args] of checks) {
  const name = `verify/${tool}${args.length ? ` ${args.join(' ')}` : ''}`;
  console.log(`\n== ${name}`);
  const result = spawnSync(process.execPath, [path.join(verifyDir, tool), ...args], { cwd: pholioRoot, stdio: 'inherit' });
  const ok = result.status === 0 && !result.error;
  if (!ok) failed += 1;
  console.log(`${ok ? 'ok  ' : 'FAIL'}  ${name}`);
}

console.log(`\n${checks.length - failed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
