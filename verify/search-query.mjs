#!/usr/bin/env node
// Runs one query against a built search index and prints the pages the search dialog
// shows, in the dialog's order: title, breadcrumbs and the best matching heading.
// No browser and no dependencies: it loads theme/js/search.js, the engine the dialog uses.
//
// Usage:
//   node verify/search-query.mjs --index <search-index.json> [--limit <n>] [--json] "query"
//
// Options:
//   --index <file>  a search-index.json written by `pholio build` (required)
//   --limit <n>     pages to print, default 8
//   --json          print the result as JSON: pages with url, title, breadcrumbs, headings,
//                   tier and score
//
// Output, one block per page:
//    1. Chat-Export  [tier 6, score 123.4]
//       Handbuch › Chat
//       # Export als PDF
//       /manuals/chat/export
//
// Exit codes: 0 printed (also when nothing matched), 2 usage or input error.

import fs from 'node:fs';
import process from 'node:process';

import { createSearch } from '../theme/js/search.js';

function usage(message) {
  console.error(`${message}\nUsage: node verify/search-query.mjs --index <search-index.json> [--limit <n>] [--json] "query"`);
  process.exit(2);
}

const argv = process.argv.slice(2);
let indexFile = null;
let limit = 8;
let json = false;
const words = [];
for (let i = 0; i < argv.length; i++) {
  const arg = argv[i];
  if (arg === '--index') {
    indexFile = argv[++i] ?? usage('Missing value for --index');
  } else if (arg === '--limit') {
    limit = Number(argv[++i]);
    if (!Number.isInteger(limit) || limit < 1) usage('--limit expects a positive integer');
  } else if (arg === '--json') {
    json = true;
  } else if (arg.startsWith('--')) {
    usage(`Unknown option: ${arg}`);
  } else {
    words.push(arg);
  }
}
if (indexFile === null) usage('Missing: --index');
if (words.length === 0) usage('Missing: the query');
const query = words.join(' ');

let engine;
try {
  engine = createSearch(JSON.parse(fs.readFileSync(indexFile, 'utf8')));
} catch (err) {
  console.error(`Cannot load the index: ${err.message}`);
  process.exit(2);
}

// The dialog's rows: a page row followed by its section rows.
const rows = engine.search(query, { pages: limit });
const ranks = new Map(engine.rankPages(query).map((page) => [page.url, page]));
const pages = [];
for (const row of rows) {
  if (row.type === 'page') {
    const rank = ranks.get(row.url);
    pages.push({ url: row.url, title: row.content, breadcrumbs: row.breadcrumbs ?? [], headings: [], tier: rank?.tier, score: rank?.score });
  } else {
    pages.at(-1).headings.push(row.content);
  }
}

if (json) {
  console.log(JSON.stringify({ query, pages }, null, 2));
} else if (pages.length === 0) {
  console.log(`No results for ${JSON.stringify(query)}`);
} else {
  pages.forEach((page, i) => {
    console.log(`${String(i + 1).padStart(2)}. ${page.title}  [tier ${page.tier}, score ${page.score?.toFixed(1)}]`);
    if (page.breadcrumbs.length) console.log(`    ${page.breadcrumbs.join(' › ')}`);
    for (const heading of page.headings) console.log(`    # ${heading}`);
    console.log(`    ${page.url}`);
  });
}
