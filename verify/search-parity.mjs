#!/usr/bin/env node
// Search parity: theme/js/search.js against Fumadocs search.
//
// Three modes:
//
//   --selftest
//     The splitter regexes and the diacritics folding in search.js against zbsearch 4.0.0 from
//     verify/node_modules: same regex source and flags per profile, same membership for every
//     BMP code point, same tokens for every BMP character and a set of sample strings.
//
//   --oracle --content <dir> --base-url <url> --queries <file.json> [--tokenizer <name>] [--order <file.json>]
//     Builds the index with verify/tools/build-search-index.php, then builds the real Fumadocs
//     search server (fumadocs-core `initAdvancedSearch` over zbsearch, same language) from the
//     same pages and compares both answers for every query. Needs no reference export.
//
//   --reference <dir> --content <dir> --base-url <url> [--tokenizer <name>] [--order <file.json>]
//     Compares against the frozen search API answers of a reference export: <dir>/search/index.json
//     maps each query to a JSON file of hits in <dir>/search/.
//
// Common options:
//   --extensions <list>  page file extensions for the index build, default "md" (e.g. "md,mdx")
//   --frontmatter-alias <from=to>
//                        frontmatter key alias for the index build, repeatable
//   --query <text>       check only this query
//   --php <bin>          PHP binary, default "php"
//
// Every hit list must match field by field: same length, same order, same id, type, url,
// breadcrumbs and content (including <mark>).
//
// Exit codes: 0 all identical, 1 a mismatch, 2 usage or input error.

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { createSearch, SPLITTERS, TOKENIZERS, tokenize } from '../theme/js/search.js';

const verifyDir = path.dirname(fileURLToPath(import.meta.url));

const argv = process.argv.slice(2);

function usage(message) {
  console.error(
    `${message}\nUsage:\n`
      + '  node verify/search-parity.mjs --selftest\n'
      + '  node verify/search-parity.mjs --oracle --content <dir> --base-url <url> --queries <file.json>'
      + ' [--tokenizer english|german] [--extensions <list>] [--frontmatter-alias <from=to>]... [--order <file.json>]'
      + ' [--query <text>] [--php <bin>]\n'
      + '  node verify/search-parity.mjs --reference <dir> --content <dir> --base-url <url>'
      + ' [--tokenizer english|german] [--extensions <list>] [--frontmatter-alias <from=to>]... [--order <file.json>]'
      + ' [--query <text>] [--php <bin>]',
  );
  process.exit(2);
}

const FLAGS = new Set(['selftest', 'oracle']);
const VALUES = new Set(['reference', 'content', 'base-url', 'queries', 'tokenizer', 'extensions', 'order', 'query', 'php']);
const REPEATED = new Set(['frontmatter-alias']);
const options = {};
for (let i = 0; i < argv.length; i++) {
  const arg = argv[i];
  if (!arg.startsWith('--')) usage(`Unexpected argument: ${arg}`);
  const name = arg.slice(2);
  if (FLAGS.has(name)) {
    options[name] = true;
  } else if (REPEATED.has(name)) {
    if (argv[i + 1] === undefined) usage(`Missing value for ${arg}`);
    (options[name] ??= []).push(argv[++i]);
  } else if (VALUES.has(name)) {
    if (argv[i + 1] === undefined) usage(`Missing value for ${arg}`);
    options[name] = argv[++i];
  } else {
    usage(`Unknown option: ${arg}`);
  }
}

const modes = ['selftest', 'oracle', 'reference'].filter((mode) => options[mode] !== undefined);
if (modes.length !== 1) usage('Choose exactly one of --selftest, --oracle, --reference');

// ------------------------------------------------------------------ selftest

// zbsearch doesn't export SPLITTERS; the module file is loaded from the installed package.
async function zbsearchModule(relative) {
  const entry = fileURLToPath(import.meta.resolve('zbsearch'));
  return import(pathToFileURL(path.join(path.dirname(entry), relative)).href);
}

async function selftest() {
  const { SPLITTERS: upstream } = await zbsearchModule('components/tokenizer/languages.js');
  const { tokenizer: tokenizerComponent } = await import('zbsearch/components');
  let failed = 0;
  let passed = 0;
  const check = (name, ok, detail = '') => {
    if (ok) passed++;
    else failed++;
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${!ok && detail ? `\n     ${detail}` : ''}`);
  };

  check('profiles are english and german', JSON.stringify(TOKENIZERS) === '["english","german"]', JSON.stringify(TOKENIZERS));

  // Mixed scripts on purpose: accents that fold, letters only one alphabet lists, the × and ÷
  // symbols inside the folding range, apostrophes, hyphens and underscores.
  const samples = [
    "Don't re-index the user_id field",
    'Größe, Übersicht, Straße',
    'naïve café, façade; São Paulo',
    'e-mail@example.com 1.x v2.4.0 a|b ~/notes',
    'Ä × Ö ÷ Ζεύς Москва ٱ',
    "l'amour, rock'n'roll -- --flag __init__",
  ];

  for (const language of TOKENIZERS) {
    const ours = SPLITTERS[language];
    const theirs = upstream[language];
    check(`${language}: splitter exists in zbsearch`, theirs instanceof RegExp);
    if (!(theirs instanceof RegExp)) continue;
    check(`${language}: splitter source equals zbsearch`, ours.source === theirs.source, `${ours.source} vs ${theirs.source}`);
    check(`${language}: splitter flags equal zbsearch`, ours.flags === theirs.flags, `${ours.flags} vs ${theirs.flags}`);

    const a = new RegExp(ours.source, ours.flags.replace('g', ''));
    const b = new RegExp(theirs.source, theirs.flags.replace('g', ''));
    let membership = 0;
    let firstMembership = null;
    for (let code = 0; code <= 0xffff; code++) {
      const char = String.fromCharCode(code);
      if (a.test(char) !== b.test(char)) {
        membership++;
        firstMembership ??= code;
      }
    }
    check(
      `${language}: splitter membership equal for all BMP code points`,
      membership === 0,
      membership ? `${membership} differ, first U+${firstMembership.toString(16).toUpperCase().padStart(4, '0')}` : '',
    );

    const upstreamTokenizer = tokenizerComponent.createTokenizer({ language });
    const inputs = [...samples];
    for (let code = 0; code <= 0xffff; code++) inputs.push(`x${String.fromCharCode(code)}y`);
    let tokens = 0;
    let firstToken = null;
    for (const input of inputs) {
      const left = JSON.stringify(tokenize(input, language));
      const right = JSON.stringify(upstreamTokenizer.tokenize(input));
      if (left !== right) {
        tokens++;
        firstToken ??= `${JSON.stringify(input)}: ${left} vs ${right}`;
      }
    }
    check(
      `${language}: tokens equal zbsearch for every BMP character and ${samples.length} samples`,
      tokens === 0,
      tokens ? `${tokens} differ, first ${firstToken}` : '',
    );
  }

  let unknownThrows = false;
  try {
    tokenize('x', 'french');
  } catch {
    unknownThrows = true;
  }
  check('unknown tokenizer throws', unknownThrows);

  console.log(`\n${passed}/${passed + failed} checks passed`);
  return failed === 0;
}

// --------------------------------------------------------------- index build

function buildIndex() {
  if (!options.content || !options['base-url']) usage(`Missing: ${options.content ? '--base-url' : '--content'}`);
  const tokenizer = options.tokenizer ?? 'english';
  if (!TOKENIZERS.includes(tokenizer)) usage(`--tokenizer must be one of ${TOKENIZERS.join(', ')}, got: ${tokenizer}`);

  const target = fs.mkdtempSync(path.join(os.tmpdir(), 'pholio-search-'));
  const indexFile = path.join(target, 'search-index.json');
  const args = [
    path.join(verifyDir, 'tools/build-search-index.php'),
    '--content', path.resolve(options.content),
    '--base-url', options['base-url'],
    '--tokenizer', tokenizer,
  ];
  if (options.extensions) args.push('--extensions', options.extensions);
  for (const alias of options['frontmatter-alias'] ?? []) args.push('--frontmatter-alias', alias);
  if (options.order) args.push('--order', path.resolve(options.order));
  args.push(indexFile);

  try {
    execFileSync(options.php ?? 'php', args, { stdio: ['ignore', 'inherit', 'inherit'] });
  } catch (err) {
    fs.rmSync(target, { recursive: true, force: true });
    console.error(`Index build failed: ${err.message}`);
    process.exit(2);
  }
  const index = JSON.parse(fs.readFileSync(indexFile, 'utf8'));
  fs.rmSync(target, { recursive: true, force: true });
  if (index.tokenizer !== tokenizer) {
    console.error(`The index records tokenizer ${JSON.stringify(index.tokenizer)}, expected ${JSON.stringify(tokenizer)}`);
    process.exit(2);
  }
  return index;
}

// ------------------------------------------------------------------- compare

const FIELDS = ['id', 'type', 'url', 'content', 'breadcrumbs'];

function differingField(a, b) {
  for (const field of FIELDS) {
    const left = a?.[field];
    const right = b?.[field];
    if (Array.isArray(left) || Array.isArray(right)) {
      if (JSON.stringify(left ?? null) !== JSON.stringify(right ?? null)) return field;
      continue;
    }
    if ((left ?? null) !== (right ?? null)) return field;
  }
  return null;
}

async function compareAll(queries, expectedFor, search) {
  const only = options.query;
  let ok = 0;
  let failed = 0;
  let empty = 0;

  for (const query of queries) {
    if (only !== undefined && query !== only) continue;
    const expected = await expectedFor(query);
    const actual = search(query);
    if (expected.length === 0) empty++;

    const problems = [];
    if (expected.length !== actual.length) problems.push(`length ${actual.length}, expected ${expected.length}`);
    const max = Math.max(expected.length, actual.length);
    for (let i = 0; i < max; i++) {
      const field = differingField(actual[i], expected[i]);
      if (field === null) continue;
      problems.push(
        `#${i} ${field}:\n      expected ${JSON.stringify(expected[i]?.[field] ?? null)}\n      actual   ${JSON.stringify(actual[i]?.[field] ?? null)}`,
      );
      if (problems.length > 6) break;
    }

    if (problems.length === 0) {
      ok++;
      console.log(`ok   ${query} (${actual.length})`);
    } else {
      failed++;
      console.log(`FAIL ${query}`);
      for (const problem of problems) console.log(`     ${problem}`);
    }
  }

  if (ok + failed === 0) usage(only !== undefined ? `Query not found: ${only}` : 'No queries');
  console.log(`\n${ok}/${ok + failed} queries identical (${empty} without hits)`);
  return failed === 0;
}

// -------------------------------------------------------------------- oracle

// Turns the compact index back into the per-page `indexes` Fumadocs passes to `buildDocuments`,
// which then emits the same documents in the same order with the same ids. The index lists a
// page's headings before its contents, so a text document that precedes the page's first heading
// document can only be the description. Without headings a description is indistinguishable
// from a leading content block, and both produce the same document.
function fumadocsIndexes(index) {
  const pages = index.pages.map((page) => ({
    id: page.u,
    title: page.t,
    url: page.u,
    breadcrumbs: page.b ?? undefined,
    structuredData: { headings: [], contents: [] },
  }));
  const docs = index.docs;
  for (let i = 0; i < docs.length; i++) {
    const [pageIndex, type, number, anchor, content] = docs[i];
    const page = pages[pageIndex];
    if (type === 0) continue;
    if (type === 1) {
      page.structuredData.headings.push({ id: anchor, content });
      continue;
    }
    const next = docs[i + 1];
    const precedesHeading = number === 0 && next !== undefined && next[0] === pageIndex && next[1] === 1;
    if (precedesHeading) {
      page.description = content;
      continue;
    }
    page.structuredData.contents.push({ heading: anchor ?? undefined, content });
  }
  return pages;
}

async function oracle() {
  if (!options.queries) usage('Missing: --queries');
  const queries = JSON.parse(fs.readFileSync(options.queries, 'utf8'));
  if (!Array.isArray(queries) || queries.length === 0 || queries.some((query) => typeof query !== 'string')) {
    usage(`--queries must be a non-empty JSON list of strings: ${options.queries}`);
  }

  const index = buildIndex();
  const { search } = createSearch(index);

  const { initAdvancedSearch } = await import('fumadocs-core/search/server');
  const server = initAdvancedSearch({ indexes: fumadocsIndexes(index), language: index.tokenizer });

  // Same call as the search API endpoint: no tag, no limit, full-text mode.
  const expectedFor = (query) => server.search(query, {});
  return compareAll(queries, expectedFor, search);
}

// ----------------------------------------------------------------- reference

async function reference() {
  const root = path.resolve(options.reference);
  const mapFile = path.join(root, 'search', 'index.json');
  if (!fs.existsSync(mapFile)) {
    console.error(`Reference answers not found: ${mapFile} (--reference must be the root of a reference export)`);
    process.exit(2);
  }
  const map = JSON.parse(fs.readFileSync(mapFile, 'utf8'));

  const index = buildIndex();
  const { search } = createSearch(index);

  const expectedFor = (query) => JSON.parse(fs.readFileSync(path.join(root, 'search', map[query]), 'utf8'));
  return compareAll(Object.keys(map), expectedFor, search);
}

// ---------------------------------------------------------------------- main

const run = { selftest, oracle, reference }[modes[0]];
process.exit((await run()) ? 0 : 1);
