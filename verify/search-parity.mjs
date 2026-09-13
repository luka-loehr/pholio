#!/usr/bin/env node
// Search checks: theme/js/search.js against src/lib/SearchIndex.php, relevance and budgets.
//
// Four modes:
//
//   --selftest
//     PHP and JavaScript share VERSION, TOKENIZERS, FOLDING, ALPHABET and the field flags,
//     and `words()` agrees in both profiles for a set of sample strings and for every BMP
//     code point placed between letters, next to "e" and inside a hyphen chain. The posting
//     and word list codecs round-trip, and PHP decodes what JavaScript encodes.
//
//   --consistency --content <dir> --base-url <url> [--tokenizer <name>]
//     Builds the index and the page texts with verify/tools/build-search-index.php, rebuilds
//     pages, words and postings in JavaScript from the texts and requires identical results.
//
//   --relevance --fixture <file.json> (--index <search-index.json> | --content <dir> --base-url <url> [--tokenizer <name>])
//     Runs every case of the fixture against the ranked pages.
//
//   --perf --fixture <file.json> (--index <file> | --content <dir> --base-url <url> [--tokenizer <name>] [--copies <n>])
//          [--max-bytes <n>] [--max-gzip <n>] [--max-load-ms <n>] [--max-p95-ms <n>] [--max-ms <n>]
//     Index size (raw and gzip -9), engine start (JSON.parse plus createSearch, median of 7)
//     and the time per keystroke: every prefix of every fixture query, once on a fresh engine
//     and five times warm. --copies builds the content that many times side by side, for a
//     larger corpus. Each given budget must hold; p95 is over the warm keystrokes, the maximum
//     is the slowest keystroke's median over its six runs (cold and warm), so one garbage
//     collection pause does not count but a query that is always slow does.
//
// Common options:
//   --extensions <list>  page file extensions for the index build, default "md"
//   --frontmatter-alias <from=to>
//                        frontmatter key alias for the index build, repeatable
//   --verbose            relevance: print the top three pages of every case
//   --php <bin>          PHP binary, default "php"
//
// Fixture:
//   { "description": "…", "cases": [
//       { "query": "install", "top1": "/guide/installation" },
//       { "query": "tabs", "top3": ["/guide/tabs-and-accordions"] },
//       { "query": "zebra", "none": true } ] }
//   top1  the first page must be this URL
//   top3  every listed URL must be among the first three pages
//   none  no page may match
//   A case may combine top1 and top3 and carry a "note". Unknown URLs are a fixture error.
//
// Exit codes: 0 all checks pass, 1 a check failed, 2 usage or input error.

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';

import {
  ALPHABET, FIELD_DESCRIPTION, FIELD_HEADING, FIELD_KEYWORDS, FIELD_PATH, FIELD_TITLE, FOLDING, INDEX_VERSION, TOKENIZERS,
  createSearch, decodePostings, decodeWords, encodePostings, encodeWords, words,
} from '../theme/js/search.js';

const verifyDir = path.dirname(fileURLToPath(import.meta.url));
const pholioRoot = path.resolve(verifyDir, '..');
const indexLibrary = path.join(pholioRoot, 'src/lib/SearchIndex.php');
const indexTool = path.join(verifyDir, 'tools/build-search-index.php');

const argv = process.argv.slice(2);

function usage(message) {
  console.error(
    `${message}\nUsage:\n`
      + '  node verify/search-parity.mjs --selftest\n'
      + '  node verify/search-parity.mjs --consistency --content <dir> --base-url <url> [--tokenizer english|german]\n'
      + '  node verify/search-parity.mjs --relevance --fixture <file.json> (--index <file> | --content <dir> --base-url <url>'
      + ' [--tokenizer english|german]) [--verbose]\n'
      + '  node verify/search-parity.mjs --perf --fixture <file.json> (--index <file> | --content <dir> --base-url <url>'
      + ' [--tokenizer english|german] [--copies <n>]) [--max-bytes <n>] [--max-gzip <n>] [--max-load-ms <n>]'
      + ' [--max-p95-ms <n>] [--max-ms <n>]\n'
      + '  Build options: [--extensions <list>] [--frontmatter-alias <from=to>]... [--php <bin>]',
  );
  process.exit(2);
}

const FLAGS = new Set(['selftest', 'consistency', 'relevance', 'perf', 'verbose']);
const VALUES = new Set([
  'content', 'base-url', 'tokenizer', 'extensions', 'index', 'fixture', 'php', 'copies',
  'max-bytes', 'max-gzip', 'max-load-ms', 'max-p95-ms', 'max-ms',
]);
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

const modes = ['selftest', 'consistency', 'relevance', 'perf'].filter((mode) => options[mode]);
if (modes.length !== 1) usage('Choose exactly one of --selftest, --consistency, --relevance, --perf');

const php = options.php ?? 'php';

function number(name) {
  if (options[name] === undefined) return null;
  const value = Number(options[name]);
  if (!Number.isFinite(value) || value < 0) usage(`--${name} expects a non-negative number, got: ${options[name]}`);
  return value;
}

function checker() {
  let passed = 0;
  let failed = 0;
  return {
    check(name, ok, detail = '') {
      if (ok) passed++;
      else failed++;
      console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${!ok && detail ? `\n     ${detail}` : ''}`);
      return ok;
    },
    summary(what) {
      console.log(`\n${passed}/${passed + failed} ${what} passed`);
      return failed === 0;
    },
  };
}

function runPhp(code, input = '') {
  const prelude = `require '${indexLibrary.replace(/[\\']/g, (c) => `\\${c}`)}'; use Pholio\\SearchIndex;`;
  return execFileSync(php, ['-r', prelude + code], { input, encoding: 'utf8', maxBuffer: 512 * 1024 * 1024 });
}

// ------------------------------------------------------------------ selftest

function selftest() {
  const { check, summary } = checker();

  const constants = JSON.parse(runPhp(
    'echo json_encode(["version" => SearchIndex::VERSION, "tokenizers" => SearchIndex::TOKENIZERS,'
      + ' "folding" => SearchIndex::FOLDING, "alphabet" => SearchIndex::ALPHABET,'
      + ' "fields" => [SearchIndex::FIELD_TITLE, SearchIndex::FIELD_PATH, SearchIndex::FIELD_HEADING,'
      + ' SearchIndex::FIELD_DESCRIPTION, SearchIndex::FIELD_KEYWORDS]], JSON_UNESCAPED_UNICODE);',
  ));
  check('index version equals', constants.version === INDEX_VERSION, `${constants.version} vs ${INDEX_VERSION}`);
  check('tokenizer profiles equal', JSON.stringify(constants.tokenizers) === JSON.stringify(TOKENIZERS));
  check('folding table equals', constants.folding === FOLDING);
  check('posting alphabet equals', constants.alphabet === ALPHABET);
  check('field flags equal', JSON.stringify(constants.fields) === JSON.stringify([FIELD_TITLE, FIELD_PATH, FIELD_HEADING, FIELD_DESCRIPTION, FIELD_KEYWORDS]));

  const combining = String.fromCharCode(0x301);
  const samples = [
    'Größe, Übersicht, Straße',
    'Passwörter und Zurücksetzen · passwoerter-und-zuruecksetzen',
    'Chat-Export, chatexport, CHAT_EXPORT',
    'naïve café, façade; São Paulo',
    `Cafe${combining} and re${combining}sume${combining}`,
    'e-mail@example.com 1.x v2.4.0 a|b ~/notes',
    "Don't re-index the user_id field",
    'ÆSIR œuvre Øresund ĳssel Łódź',
    'İstanbul ΣΊΣΥΦΟΣ Ǆemal Ⅻ ﬁle',
    'two  spaces\ttab\nnewline',
    'a-b-c-d chain_of_words --flag __init__ -x- trailing-',
    'Zwei-Faktor-Authentifizierung 2FA Queue Quelle',
  ];
  const inputs = [...samples];
  for (let code = 0; code <= 0xffff; code++) {
    if (code >= 0xd800 && code <= 0xdfff) continue;
    const char = String.fromCharCode(code);
    inputs.push(`A${char}e-x${char}Y ${char}`);
  }
  const phpWords = JSON.parse(runPhp(
    '$out = []; foreach (json_decode(stream_get_contents(STDIN), true) as $s) {'
      + ' $out[] = [implode(" ", SearchIndex::words($s, "english")), implode(" ", SearchIndex::words($s, "german"))]; }'
      + ' echo json_encode($out);',
    JSON.stringify(inputs),
  ));
  for (const [profile, column] of [['english', 0], ['german', 1]]) {
    let differences = 0;
    let first = null;
    inputs.forEach((input, i) => {
      const ours = words(input, profile).join(' ');
      if (ours !== phpWords[i][column]) {
        differences++;
        first ??= `${JSON.stringify(input)}: js ${JSON.stringify(ours)} vs php ${JSON.stringify(phpWords[i][column])}`;
      }
    });
    check(
      `${profile}: words() equal in PHP and JavaScript for ${samples.length} samples and every BMP code point`,
      differences === 0,
      differences ? `${differences} differ, first ${first}` : '',
    );
  }

  let seed = 7;
  const random = () => {
    seed = (seed * 1103515245 + 12345) % 2147483648;
    return seed / 2147483648;
  };
  const entries = [];
  for (let slot = -1, i = 0; i < 500; i++) {
    slot += 1 + Math.floor(random() ** 4 * 40000);
    entries.push([slot, Math.floor(random() * 64)]);
  }
  const encoded = encodePostings(entries);
  check('posting codec round-trips', JSON.stringify([...decodePostings(encoded)]) === JSON.stringify(entries.flat()));
  const phpDecoded = JSON.parse(runPhp('echo json_encode(SearchIndex::decodePostings(stream_get_contents(STDIN)));', encoded));
  check('PHP decodes JavaScript postings', JSON.stringify(Object.entries(phpDecoded).map(([s, f]) => [Number(s), f])) === JSON.stringify(entries));
  const list = [...new Set(words(samples.join(' '), 'german'))].sort();
  list.push(`${'x'.repeat(40)}a`, `${'x'.repeat(40)}b`);
  list.sort();
  check('word list codec round-trips', JSON.stringify(decodeWords(encodeWords(list))) === JSON.stringify(list));

  let unknown = false;
  try {
    createSearch({ v: INDEX_VERSION, tokenizer: 'french', base: '/', crumbs: [], pages: [], lengths: [], words: '', postings: [] });
  } catch {
    unknown = true;
  }
  check('unknown tokenizer throws', unknown);

  return summary('checks');
}

// --------------------------------------------------------------- index build

function contentOptions() {
  if (!options.content || !options['base-url']) usage(`Missing: ${options.content ? '--base-url' : '--content'}`);
  const tokenizer = options.tokenizer ?? 'english';
  if (!TOKENIZERS.includes(tokenizer)) usage(`--tokenizer must be one of ${TOKENIZERS.join(', ')}, got: ${tokenizer}`);
  return { content: path.resolve(options.content), baseUrl: options['base-url'], tokenizer };
}

// Content built <copies> times side by side under copy-1 … copy-<n>.
function replicate(content, copies) {
  const target = fs.mkdtempSync(path.join(os.tmpdir(), 'pholio-search-copies-'));
  const names = [];
  for (let n = 1; n <= copies; n++) {
    const name = `copy-${n}`;
    names.push(name);
    fs.cpSync(content, path.join(target, name), { recursive: true });
    for (const file of fs.readdirSync(path.join(target, name), { recursive: true })) {
      if (path.basename(file) !== 'meta.json') continue;
      const metaFile = path.join(target, name, file);
      const meta = JSON.parse(fs.readFileSync(metaFile, 'utf8'));
      delete meta.root;
      if (file === 'meta.json') meta.title = `Copy ${n}`;
      fs.writeFileSync(metaFile, JSON.stringify(meta));
    }
  }
  fs.writeFileSync(path.join(target, 'meta.json'), JSON.stringify({ pages: names }));
  return target;
}

function buildIndex({ texts = false, copies = 1 } = {}) {
  const { content, baseUrl, tokenizer } = contentOptions();
  const target = fs.mkdtempSync(path.join(os.tmpdir(), 'pholio-search-'));
  const source = copies > 1 ? replicate(content, copies) : content;
  const indexFile = path.join(target, 'search-index.json');
  const textsFile = path.join(target, 'texts.json');
  const args = [indexTool, '--content', source, '--base-url', baseUrl, '--tokenizer', tokenizer];
  if (options.extensions) args.push('--extensions', options.extensions);
  for (const alias of options['frontmatter-alias'] ?? []) args.push('--frontmatter-alias', alias);
  if (texts) args.push('--texts', textsFile);
  args.push(indexFile);

  try {
    execFileSync(php, args, { stdio: ['ignore', 'ignore', 'inherit'] });
    const bytes = fs.readFileSync(indexFile);
    return {
      bytes,
      index: JSON.parse(bytes.toString('utf8')),
      documents: texts ? JSON.parse(fs.readFileSync(textsFile, 'utf8')) : null,
      tokenizer,
      baseUrl,
    };
  } catch (err) {
    console.error(`Index build failed: ${err.message}`);
    process.exit(2);
  } finally {
    fs.rmSync(target, { recursive: true, force: true });
    if (source !== content) fs.rmSync(source, { recursive: true, force: true });
  }
}

function loadIndex({ copies = 1 } = {}) {
  if (options.index !== undefined) {
    if (options.content !== undefined || copies > 1) usage('--index excludes --content and --copies');
    let bytes;
    try {
      bytes = fs.readFileSync(options.index);
    } catch (err) {
      console.error(`Cannot read the index: ${err.message}`);
      process.exit(2);
    }
    return { bytes, index: JSON.parse(bytes.toString('utf8')) };
  }
  return buildIndex({ copies });
}

// --------------------------------------------------------------- consistency

// The JavaScript twin of SearchIndex::slotFlags.
function slotFlags(document, baseUrl, tokenizer) {
  const mark = (flags, text, flag) => {
    for (const word of words(text, tokenizer)) flags.set(word, (flags.get(word) ?? 0) | flag);
  };
  const page = new Map();
  mark(page, document.title, FIELD_TITLE);
  if (document.heading !== null) mark(page, document.heading, FIELD_TITLE);
  if (document.description !== null) mark(page, document.description, FIELD_DESCRIPTION);
  if (document.keywords !== null) mark(page, document.keywords, FIELD_KEYWORDS);
  for (const crumb of document.crumbs ?? []) mark(page, crumb, FIELD_PATH);
  for (const segment of document.url.slice(baseUrl.length).split('/')) mark(page, segment, FIELD_PATH);

  const slots = [page];
  for (const [, heading, ...texts] of document.sections) {
    const flags = new Map();
    if (heading !== null) mark(flags, heading, FIELD_HEADING);
    const counts = new Map();
    for (const text of texts) {
      for (const word of new Set(words(text, tokenizer))) counts.set(word, (counts.get(word) ?? 0) + 1);
    }
    for (const [word, count] of counts) flags.set(word, (flags.get(word) ?? 0) | (Math.min(count, 7) << 3));
    slots.push(flags);
  }
  return slots;
}

function rebuild(documents, baseUrl, tokenizer) {
  const crumbs = [];
  const crumbIds = new Map();
  const pages = [];
  const lengths = [];
  const lists = new Map();
  let slot = 0;
  for (const document of documents) {
    lengths.push(document.sections.reduce((sum, section) => sum + section.length - 2, 0));
    let crumbId = -1;
    if (document.crumbs !== null) {
      const key = JSON.stringify(document.crumbs);
      if (!crumbIds.has(key)) {
        crumbIds.set(key, crumbs.length);
        crumbs.push(document.crumbs);
      }
      crumbId = crumbIds.get(key);
    }
    pages.push([document.url, document.title, document.heading, crumbId, ...document.sections.flatMap(([anchor, heading]) => [anchor, heading])]);
    slotFlags(document, baseUrl, tokenizer).forEach((flags, offset) => {
      for (const [word, value] of flags) {
        if (!lists.has(word)) lists.set(word, []);
        lists.get(word).push([slot + offset, value]);
      }
    });
    slot += 1 + document.sections.length;
  }
  // Words hold only a–z and 0–9, so code unit order is byte order.
  const sorted = [...lists.keys()].sort();
  return { crumbs, pages, lengths, words: encodeWords(sorted), postings: sorted.map((word) => encodePostings(lists.get(word))) };
}

function consistency() {
  const { check, summary } = checker();
  const { index, documents, tokenizer, baseUrl } = buildIndex({ texts: true });
  const rebuilt = rebuild(documents, baseUrl, tokenizer);

  check('header: version, base and tokenizer', index.v === INDEX_VERSION && index.base === baseUrl && index.tokenizer === tokenizer);
  check('breadcrumb lists equal', JSON.stringify(index.crumbs) === JSON.stringify(rebuilt.crumbs));
  check('page lengths equal', JSON.stringify(index.lengths) === JSON.stringify(rebuilt.lengths));
  const page = index.pages.findIndex((entry, i) => JSON.stringify(entry) !== JSON.stringify(rebuilt.pages[i]));
  check(
    `${index.pages.length} page entries equal`,
    index.pages.length === rebuilt.pages.length && page === -1,
    page >= 0 ? `page ${page}: php ${JSON.stringify(index.pages[page])}\n     js  ${JSON.stringify(rebuilt.pages[page])}` : '',
  );
  const phpWords = decodeWords(index.words);
  const jsWords = decodeWords(rebuilt.words);
  const word = phpWords.findIndex((w, i) => w !== jsWords[i]);
  check(
    `${phpWords.length} words equal`,
    index.words === rebuilt.words,
    `php ${phpWords.length}, js ${jsWords.length}; first difference at ${word}: php ${JSON.stringify(phpWords[word])} vs js ${JSON.stringify(jsWords[word])}`,
  );
  const posting = index.postings.findIndex((p, i) => p !== rebuilt.postings[i]);
  check(
    `${index.postings.length} posting lists equal`,
    index.postings.length === rebuilt.postings.length && posting === -1,
    posting >= 0
      ? `word ${JSON.stringify(phpWords[posting])}: php ${JSON.stringify([...decodePostings(index.postings[posting])])}\n     js  ${JSON.stringify([...decodePostings(rebuilt.postings[posting] ?? '')])}`
      : '',
  );
  let engine = null;
  try {
    engine = createSearch(index);
  } catch (err) {
    check('search.js loads the index', false, err.message);
  }
  if (engine) check('search.js loads the index and answers', Array.isArray(engine.search(phpWords[0] ?? 'a')));

  return summary('checks');
}

// ----------------------------------------------------------------- relevance

// Unknown URLs are only checked against the real corpus, not against --copies.
function readFixture(index, checkUrls = true) {
  if (!options.fixture) usage('Missing: --fixture');
  let fixture;
  try {
    fixture = JSON.parse(fs.readFileSync(options.fixture, 'utf8'));
  } catch (err) {
    console.error(`Cannot read the fixture: ${err.message}`);
    process.exit(2);
  }
  const urls = new Set(index.pages.map((entry) => entry[0]));
  const problems = [];
  if (!Array.isArray(fixture?.cases) || fixture.cases.length === 0) problems.push('"cases" must be a non-empty list');
  for (const [i, c] of (fixture?.cases ?? []).entries()) {
    const where = `case ${i} (${JSON.stringify(c?.query)})`;
    if (typeof c?.query !== 'string' || c.query.trim() === '') problems.push(`${where}: "query" must be a non-empty string`);
    const kinds = ['top1', 'top3', 'none'].filter((key) => c?.[key] !== undefined);
    if (kinds.length === 0 || (c.none !== undefined && kinds.length > 1)) problems.push(`${where}: expects top1 and/or top3, or none`);
    if (c?.none !== undefined && c.none !== true) problems.push(`${where}: "none" must be true`);
    if (c?.top3 !== undefined && (!Array.isArray(c.top3) || c.top3.length === 0 || c.top3.length > 3)) problems.push(`${where}: "top3" must list one to three URLs`);
    for (const url of [c?.top1, ...(Array.isArray(c?.top3) ? c.top3 : [])].filter((u) => u !== undefined)) {
      if (checkUrls && !urls.has(url)) problems.push(`${where}: unknown page ${url}`);
    }
  }
  if (problems.length) {
    console.error(`Fixture errors in ${options.fixture}:\n  ${problems.join('\n  ')}`);
    process.exit(2);
  }
  return fixture;
}

function relevance() {
  const { index } = loadIndex();
  const fixture = readFixture(index);
  const engine = createSearch(index);
  let ok = 0;
  let failed = 0;
  const describe = (ranked) => ranked.slice(0, 3).map((r) => `${r.url} (tier ${r.tier})`).join(', ') || 'nothing';

  for (const c of fixture.cases) {
    const ranked = engine.rankPages(c.query);
    const top = ranked.slice(0, 3).map((r) => r.url);
    const problems = [];
    if (c.none && ranked.length > 0) problems.push('expected no hits');
    if (c.top1 !== undefined && top[0] !== c.top1) problems.push(`expected #1 ${c.top1}`);
    const missing = (c.top3 ?? []).filter((url) => !top.includes(url));
    if (missing.length) problems.push(`expected in the top 3: ${missing.join(', ')}`);

    if (problems.length === 0) {
      ok++;
      console.log(`ok   ${c.query}${options.verbose ? `  →  ${describe(ranked)}` : ''}`);
    } else {
      failed++;
      console.log(`FAIL ${c.query}: ${problems.join('; ')}\n     got ${describe(ranked)}`);
    }
  }

  console.log(`\n${ok}/${ok + failed} relevance cases pass`);
  return failed === 0;
}

// ---------------------------------------------------------------------- perf

function percentile(sorted, p) {
  return sorted.length ? sorted[Math.min(sorted.length - 1, Math.floor(p * sorted.length))] : 0;
}

function perf() {
  const copies = options.copies === undefined ? 1 : Number(options.copies);
  if (!Number.isInteger(copies) || copies < 1) usage(`--copies expects a positive integer, got: ${options.copies}`);
  const { bytes, index } = loadIndex({ copies });
  const fixture = readFixture(index, copies === 1);
  const text = bytes.toString('utf8');

  const loads = [];
  for (let run = 0; run < 7; run++) {
    const started = performance.now();
    createSearch(JSON.parse(text));
    loads.push(performance.now() - started);
  }
  loads.sort((a, b) => a - b);

  const keystrokes = fixture.cases.flatMap((c) => Array.from(c.query, (_, i) => c.query.slice(0, i + 1)));
  const cold = createSearch(JSON.parse(text));
  const coldTimes = keystrokes.map((query) => {
    const started = performance.now();
    cold.search(query);
    return performance.now() - started;
  });
  const warmTimes = [];
  const perKeystroke = coldTimes.map((time) => [time]);
  for (let round = 0; round < 5; round++) {
    keystrokes.forEach((query, k) => {
      const started = performance.now();
      cold.search(query);
      const time = performance.now() - started;
      warmTimes.push(time);
      perKeystroke[k].push(time);
    });
  }
  warmTimes.sort((a, b) => a - b);
  // A single garbage collection or JIT pause on a shared machine must not fail the budget;
  // a query that is slow every time still does.
  const medians = perKeystroke.map((times) => percentile(times.sort((a, b) => a - b), 0.5));

  const measured = {
    bytes: bytes.length,
    gzip: zlib.gzipSync(bytes, { level: 9 }).length,
    loadMs: loads[3],
    p50Ms: percentile(warmTimes, 0.5),
    p95Ms: percentile(warmTimes, 0.95),
    maxMs: Math.max(...medians),
  };
  const ms = (value) => `${value.toFixed(3)} ms`;
  console.log(
    `${index.pages.length} pages, ${index.postings.length} words, ${keystrokes.length} keystrokes\n`
      + `index      ${measured.bytes} bytes, ${measured.gzip} gzip\n`
      + `load       ${ms(measured.loadMs)} (median of 7)\n`
      + `keystroke  p50 ${ms(measured.p50Ms)}, p95 ${ms(measured.p95Ms)}, max ${ms(measured.maxMs)} (worst median of 6 runs;`
      + ` single slowest ${ms(Math.max(...coldTimes, ...warmTimes))})\n`,
  );

  const { check, summary } = checker();
  const budgets = [
    ['max-bytes', 'bytes', (v) => `${v} bytes`],
    ['max-gzip', 'gzip', (v) => `${v} bytes gzip`],
    ['max-load-ms', 'loadMs', ms],
    ['max-p95-ms', 'p95Ms', ms],
    ['max-ms', 'maxMs', ms],
  ];
  let any = false;
  for (const [option, key, format] of budgets) {
    const budget = number(option);
    if (budget === null) continue;
    any = true;
    check(`${key} ${format(measured[key])} within ${format(budget)}`, measured[key] <= budget);
  }
  if (!any) console.log('no budgets given');
  return summary('budgets');
}

// ---------------------------------------------------------------------- main

const run = { selftest, consistency, relevance, perf }[modes[0]];
process.exit(run() ? 0 : 1);
