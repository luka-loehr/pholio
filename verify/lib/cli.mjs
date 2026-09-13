// Shared command-line and path helpers of the verify tools.
//
// computed-style.mjs, pixel-diff.mjs, behaviour.mjs and golden-dom.mjs use them so
// that every tool has the same call syntax: the same --flags, the same reference →
// candidate path rewrites, the same ~ expansion.
//
// Rewrites are site data, so there are no built-in rules. They come from one or more
// JSON files (`--rewrites <file.json>`) and from single `--rewrite from=to` flags.
// A rewrites file is either a list of [from, to] pairs or an object:
//
//   { "rewrites": [["/reference/docs", "/docs"]], "extraPages": ["/reference/docs"] }
//
// `extraPages` lists reference paths that are checked in addition to the page tree
// (see pages.mjs).
//
// No dependencies beyond Node built-ins.

import fsSync from 'node:fs';
import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

// ---- Arguments ------------------------------------------------------------

// --name value → value, otherwise fallback.
export function arg(name, fallback, argv = process.argv) {
  const i = argv.indexOf(`--${name}`);
  return i >= 0 && argv[i + 1] ? argv[i + 1] : fallback;
}

// Is --name present?
export function flag(name, argv = process.argv) {
  return argv.includes(`--${name}`);
}

// All values of a repeated --name.
export function argAll(name, argv = process.argv) {
  const out = [];
  argv.forEach((a, i) => {
    if (a === `--${name}` && argv[i + 1]) out.push(argv[i + 1]);
  });
  return out;
}

// Comma list after --name; empty entries are dropped.
export function argList(name, fallback, argv = process.argv) {
  const raw = arg(name, null, argv);
  if (raw === null) return fallback;
  return raw.split(',').map((s) => s.trim()).filter(Boolean);
}

// Number after --name, validated.
export function argNumber(name, fallback, argv = process.argv) {
  const raw = arg(name, null, argv);
  if (raw === null) return fallback;
  const n = Number(raw);
  if (!Number.isFinite(n)) throw new Error(`--${name} needs a number, got: ${raw}`);
  return n;
}

// ---- Paths and files ------------------------------------------------------

export function resolveHome(p) {
  return path.resolve(p.replace(/^~/, process.env.HOME ?? '~'));
}

export async function readJson(file, fallback) {
  try {
    return JSON.parse(await fs.readFile(file, 'utf8'));
  } catch (err) {
    if (err.code === 'ENOENT' && fallback !== undefined) return fallback;
    throw new Error(`${file} is not readable: ${err.message}`);
  }
}

export async function writeJson(file, value) {
  await fs.mkdir(path.dirname(file), { recursive: true });
  await fs.writeFile(file, `${JSON.stringify(value, null, 2)}\n`);
}

export async function exists(file) {
  try {
    await fs.access(file);
    return true;
  } catch {
    return false;
  }
}

export function isUrl(value) {
  return /^https?:\/\//i.test(value);
}

// ---- Rewrites reference → candidate ---------------------------------------

function isStringList(value) {
  return Array.isArray(value) && value.every((v) => typeof v === 'string');
}

// Read and validate one rewrites file. Returns { rewrites, extraPages }.
export function loadRewritesFile(file) {
  const resolved = resolveHome(file);
  let doc;
  try {
    doc = JSON.parse(fsSync.readFileSync(resolved, 'utf8'));
  } catch (err) {
    throw new Error(`--rewrites ${file} is not readable: ${err.message}`);
  }
  const rewrites = Array.isArray(doc) ? doc : doc?.rewrites ?? [];
  const extraPages = Array.isArray(doc) ? [] : doc?.extraPages ?? [];
  if (!Array.isArray(rewrites) || !rewrites.every((r) => isStringList(r) && r.length === 2)) {
    throw new Error(`--rewrites ${file}: expected a list of [from, to] string pairs.`);
  }
  if (!isStringList(extraPages)) {
    throw new Error(`--rewrites ${file}: extraPages must be a list of strings.`);
  }
  return { rewrites: rewrites.map(([from, to]) => [from, to]), extraPages };
}

// --no-rewrite turns everything off (comparing the reference with itself).
// Otherwise the rules of every --rewrites file apply in the order given, followed by
// every --rewrite from=to.
export function buildRewrites(argv = process.argv) {
  if (flag('no-rewrite', argv)) return [];
  return [
    ...argAll('rewrites', argv).flatMap((file) => loadRewritesFile(file).rewrites),
    ...argAll('rewrite', argv).map((r) => {
      const i = r.indexOf('=');
      if (i < 0) throw new Error(`--rewrite needs the form from=to, got: ${r}`);
      return [r.slice(0, i), r.slice(i + 1)];
    }),
  ];
}

// Reference paths checked in addition to the page tree: `extraPages` of every
// --rewrites file, then the comma list of --extra-pages. Order kept, duplicates dropped.
export function buildExtraPages(argv = process.argv) {
  return [
    ...new Set([
      ...argAll('rewrites', argv).flatMap((file) => loadRewritesFile(file).extraPages),
      ...argList('extra-pages', [], argv),
    ]),
  ];
}

// Rewrite a path (with a leading /) according to the rules.
export function rewritePath(value, rewrites) {
  for (const [from, to] of rewrites) {
    if (value === from) return to;
    // Rules ending in / (`/assets/`) are plain prefixes; without this case the lookup
    // would be for `/assets//` and never match.
    if (from.endsWith('/') && value.startsWith(from)) return to + value.slice(from.length);
    if (value.startsWith(`${from}/`) || value.startsWith(`${from}#`) || value.startsWith(`${from}?`)) {
      return to + value.slice(from.length);
    }
  }
  return value;
}

// Base URL + path to a full URL, without duplicated prefixes and without //.
// The base may already contain the target path (…example.test/docs + /docs/guide).
export function joinUrl(base, pathname) {
  const root = base.replace(/\/+$/, '');
  const basePath = new URL(root).pathname.replace(/^\/+|\/+$/g, '');
  let tail = pathname.replace(/^\/+/, '');
  if (basePath && (tail === basePath || tail.startsWith(`${basePath}/`))) {
    tail = tail.slice(basePath.length).replace(/^\/+/, '');
  }
  return `${root}${tail ? `/${tail}` : ''}`.replace(/([^:])\/\/+/g, '$1/');
}
