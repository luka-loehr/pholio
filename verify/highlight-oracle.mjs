#!/usr/bin/env node
// Highlight oracle: runs the real fumadocs-core rehypeCode (Shiki 4, JavaScript regex engine, github-light and
// github-dark, defaultColor false, notation transformers, icon) from verify/node_modules and writes the expected
// <pre> HTML (hast-util-to-html) next to each sample.
//
// Every reference is produced in its own fresh Node process (--single, at most 8 in parallel). V8 14.6 matches
// modifier groups (?i:...) that contain an alternation case-sensitively on the unoptimised native RegExp path, and
// whether a pattern takes that path depends on the RegExp work the process did before (see
// highlight-samples/README.md). tokenizeTimeLimit is 0: Shiki's default of 500 ms per line cuts long lines off under
// load and leaves the rest uncoloured, which would make the reference depend on machine load.
//
// Usage:
//   node verify/highlight-oracle.mjs [--out <dir>] [--only <substring>] [--jobs <n>]
//                                    [--samples <dir>] [--node-modules <dir>]
//
// --samples defaults to verify/highlight-samples, --out to the samples directory, --node-modules to
// verify/node_modules (PHOLIO_VERIFY_NODE_MODULES overrides it), --jobs to 8 (maximum 8).
//
// Samples live at <samples>/<lang>/<name>.<ext>. The language is the directory name, the info string is empty.
// A file <name>.<ext>.meta.json overrides that: {"lang": "ts", "info": "title=\"x\"", "mode": "fence"|"dynamic"}.
// The code is the file content without exactly one trailing "\n" (like the value of a Markdown fence).
// Output: <name>.<ext>.expected.html (no trailing newline), or <name>.<ext>.expected.error.txt on errors.
// Child exit codes: 0 reference written, 2 error reference written, 1 aborted.

import { readFileSync, writeFileSync, readdirSync, statSync, mkdirSync, existsSync, rmSync } from 'node:fs';
import { join, dirname, relative, resolve } from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';

const args = process.argv.slice(2);
const opt = (name, def) => {
  const i = args.indexOf(name);
  if (i === -1) return def;
  if (i + 1 >= args.length) fail(`${name} needs a value`);
  return args[i + 1];
};
function fail(message) {
  console.error(`highlight-oracle: ${message}`);
  process.exit(1);
}

const HERE = dirname(fileURLToPath(import.meta.url));
const SAMPLES = resolve(opt('--samples', join(HERE, 'highlight-samples')));
const OUT = resolve(opt('--out', SAMPLES));
const ONLY = opt('--only', null);
const SINGLE = opt('--single', null);
const NODE_MODULES = resolve(opt('--node-modules', process.env.PHOLIO_VERIFY_NODE_MODULES ?? join(HERE, 'node_modules')));
const JOBS = Number(opt('--jobs', '8'));
if (!Number.isInteger(JOBS) || JOBS < 1 || JOBS > 8) fail('--jobs must be an integer from 1 to 8');
if (!existsSync(SAMPLES)) fail(`samples directory not found: ${SAMPLES}`);
if (!existsSync(join(NODE_MODULES, 'fumadocs-core'))) {
  fail(`fumadocs-core not found in ${NODE_MODULES} (run npm ci in verify/ or pass --node-modules)`);
}
const nm = (p) => pathToFileURL(join(NODE_MODULES, p)).href;

function walk(dir) {
  const out = [];
  for (const name of readdirSync(dir).sort()) {
    const p = join(dir, name);
    if (dir === SAMPLES && (name === 'known-differences.json' || name === 'README.md' || name.startsWith('.'))) continue;
    if (statSync(p).isDirectory()) out.push(...walk(p));
    else if (!name.endsWith('.expected.html') && !name.endsWith('.meta.json') && !name.endsWith('.error.txt')) out.push(p);
  }
  return out;
}

if (SINGLE === null) {
  const { execFile } = await import('node:child_process');
  const files = walk(SAMPLES).map((f) => relative(SAMPLES, f)).filter((rel) => !ONLY || rel.includes(ONLY));
  const passOn = ['--samples', SAMPLES, '--out', OUT, '--node-modules', NODE_MODULES];
  let ok = 0;
  let failed = 0;
  const aborted = [];
  let next = 0;
  const worker = async () => {
    while (next < files.length) {
      const rel = files[next++];
      const code = await new Promise((done) => {
        execFile(process.execPath, [...process.execArgv, fileURLToPath(import.meta.url), '--single', rel, ...passOn], (err, _stdout, stderr) => {
          if (err && err.code !== 2 && stderr) process.stderr.write(stderr);
          done(err ? (typeof err.code === 'number' ? err.code : 1) : 0);
        });
      });
      if (code === 0) ok++;
      else if (code === 2) failed++;
      else aborted.push(`${rel} (exit ${code})`);
    }
  };
  await Promise.all(Array.from({ length: Math.min(JOBS, Math.max(files.length, 1)) }, worker));
  if (files.length === 0) fail(`no samples matched${ONLY ? ` --only ${ONLY}` : ''}`);
  if (aborted.length > 0) fail(`oracle process aborted for ${aborted.join(', ')}`);
  console.log(`highlight-oracle: ${ok} written, ${failed} with error, target ${OUT} (one fresh process per sample)`);
  process.exit(0);
}

const { rehypeCode } = await import(nm('fumadocs-core/dist/mdx-plugins/rehype-code.js'));
const { parseCodeBlockAttributes } = await import(nm('fumadocs-core/dist/mdx-plugins/codeblock-utils.js'));
const { defaultShikiFactory } = await import(nm('fumadocs-core/dist/highlight/shiki/full.js'));
const { highlightHast } = await import(nm('fumadocs-core/dist/highlight/shiki/index.js'));
const { toHtml } = await import(nm('hast-util-to-html/index.js'));

// Transformer of the child process (handles exactly one sample). No per-line time limit, see above.
const transformer = rehypeCode({ tokenizeTimeLimit: 0 });

const file = join(SAMPLES, SINGLE);
if (!existsSync(file)) fail(`sample not found: ${SINGLE}`);
const metaFile = file + '.meta.json';
const cfg = existsSync(metaFile) ? JSON.parse(readFileSync(metaFile, 'utf8')) : {};
const lang = 'lang' in cfg ? cfg.lang : SINGLE.split(/[\\/]/)[0];
let code = readFileSync(file, 'utf8');
if (code.endsWith('\n')) code = code.slice(0, -1);
const target = join(OUT, SINGLE + '.expected.html');
const errTarget = join(OUT, SINGLE + '.expected.error.txt');
mkdirSync(dirname(target), { recursive: true });
try {
  let html;
  if (cfg.mode === 'dynamic') {
    const highlighter = await defaultShikiFactory.getOrInit();
    const root = await highlightHast(highlighter, code, {
      lang,
      defaultColor: false,
      themes: { light: 'github-light', dark: 'github-dark' },
      tokenizeTimeLimit: 0,
    });
    html = toHtml(root.children[0]);
  } else {
    // Info string as micromark splits it: the language is the first word, the rest is meta.
    const info = (cfg.info ?? '').trim();
    let meta = info === '' ? null : info;
    // remark-code-tab takes tab and tab-group out before rehype-code sees the rest.
    if (meta) {
      const parsed = parseCodeBlockAttributes(meta, ['tab', 'tab-group']);
      if (typeof parsed.attributes.tab === 'string') meta = parsed.rest;
    }
    const codeEl = { type: 'element', tagName: 'code', properties: {}, children: [{ type: 'text', value: code + '\n' }] };
    if (lang) codeEl.properties.className = ['language-' + lang];
    if (meta) codeEl.data = { meta };
    const tree = { type: 'root', children: [{ type: 'element', tagName: 'pre', properties: {}, children: [codeEl] }] };
    await transformer(tree, {});
    let node = tree.children[0];
    // transformerTab wraps the block in an mdxJsxFlowElement node; only the <pre> is compared.
    while (node && node.type !== 'element') node = node.children?.[0];
    html = toHtml(node);
  }
  writeFileSync(target, html);
  if (existsSync(errTarget)) rmSync(errTarget);
  process.exit(0);
} catch (e) {
  writeFileSync(errTarget, String(e && e.message ? e.message : e));
  if (existsSync(target)) rmSync(target);
  process.exit(2);
}
