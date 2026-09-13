#!/usr/bin/env node
// Agent readiness of a published documentation site, modelled on the checks of `mint score`.
//
// Usage:
//   node verify/agent-score.mjs --base <url> [--page <path>] [--json] [--min-score <0-100>]
//   node verify/agent-score.mjs --demo [--php <bin>] [--json] [--min-score <0-100>]
//
// --base scores a running site; <url> is where its start page is served, for example
// https://docs.example.org or http://127.0.0.1:8080/manuals. --page picks the page for the
// negotiation and header checks (default: the first page listed in llms.txt).
//
// --demo builds examples/demo into a temporary directory, with site.url set to the address it is
// served from, serves it with PHP's built-in server and src/DevServer.php (the router `pholio dev`
// runs) and scores that. The demo's content links assume the site root, so it is served at /;
// tests/AgentsTest.php covers a site under a base path. Needs PHP on PATH or --php.
//
// Checks, with their weight:
//   llmsTxtExists (10)            <base>/llms.txt answers 200 with text, not HTML
//   llmsTxtValid (5)              an H1, a blockquote summary and Markdown links
//   llmsTxtSize (3)               at most 100,000 characters
//   llmsTxtLinksResolve (5)       every same-origin link resolves, /_llms/ indexes followed recursively
//   llmsTxtLinksMarkdown (5)      every same-origin page link points at a .md file
//   llmsTxtDirective (3)          the first listed page starts with the directive to fetch llms.txt
//   llmsTxtFullExists (5)         <base>/llms-full.txt answers 200 with text
//   llmsTxtFullSize (2)           at most 10 MB
//   llmsTxtFullValid (2)          headings, not HTML
//   llmsTxtFullLinksResolve (3)   same-origin links in it resolve
//   skillMd (5)                   <base>/skill.md with name and description; digests in
//                                 .well-known/agent-skills/index.json match, when present
//   contentNegotiationMarkdown (10)  the page with Accept: text/markdown answers Markdown
//   contentNegotiationPlaintext (5)  the page with Accept: text/plain answers text/plain
//   contentTypeOpenAI (5)         OpenAI's agents (ChatGPT-User, OAI-SearchBot, GPTBot), which reject
//                                 text/markdown, get the page's .md URL and the negotiated page as text/plain
//   robotsTxtAllowsAI (5)         robots.txt (below base, else at the host root) blocks no AI crawler
//   sitemapExists (5)             sitemap.xml below base or named in robots.txt
//   structuredData (5)            JSON-LD on the start page
//   discoveryLinkHeader (5)       the page sends Link with rel="llms-txt"
//   discoveryLinkHeader404 (3)    a missing page answers 404 and still sends that Link header
//   responseLatency (5)           median of five start page requests under 1000 ms
//
// A check that depends on a failed one fails too. The score is the passed weight as a percentage
// of the total. Exit codes: 0 score at least --min-score (default 0), 1 below, 2 usage or setup error.

import { spawn, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const verifyDir = path.dirname(fileURLToPath(import.meta.url));
const pholioRoot = path.resolve(verifyDir, '..');

const LLMS_LIMIT = 100000;
const FULL_LIMIT = 10 * 1024 * 1024;
const LATENCY_MS = 1000;
const AI_AGENTS = ['*', 'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'Claude-User', 'Claude-SearchBot', 'anthropic-ai',
  'PerplexityBot', 'Perplexity-User', 'Google-Extended', 'Google-Agent', 'CCBot', 'MistralAI-User', 'DuckAssistBot', 'cohere-ai'];

function usage(message) {
  console.error(`${message}\nUsage: node verify/agent-score.mjs --base <url> [--page <path>] [--json] [--min-score <n>]\n`
    + '       node verify/agent-score.mjs --demo [--php <bin>] [--json] [--min-score <n>]');
  process.exit(2);
}

const argv = process.argv.slice(2);
const options = { base: null, page: null, json: false, demo: false, php: 'php', minScore: 0 };
for (let i = 0; i < argv.length; i++) {
  const value = () => argv[++i] ?? usage(`Missing value for ${argv[i - 1]}`);
  if (argv[i] === '--base') options.base = value();
  else if (argv[i] === '--page') options.page = value();
  else if (argv[i] === '--php') options.php = value();
  else if (argv[i] === '--min-score') options.minScore = Number(value());
  else if (argv[i] === '--json') options.json = true;
  else if (argv[i] === '--demo') options.demo = true;
  else usage(`Unknown option: ${argv[i]}`);
}
if (options.demo === Boolean(options.base)) usage('Pass either --base <url> or --demo');
if (!(options.minScore >= 0 && options.minScore <= 100)) usage('--min-score must be a number from 0 to 100');

// ------------------------------------------------------------------ HTTP

async function get(url, headers = {}) {
  const started = performance.now();
  try {
    const response = await fetch(url, { headers, redirect: 'follow', signal: AbortSignal.timeout(15000) });
    const body = await response.text();
    return { url, status: response.status, headers: response.headers, body, ms: performance.now() - started };
  } catch (err) {
    return { url, status: 0, headers: new Headers(), body: '', ms: performance.now() - started, error: err.message };
  }
}

const isHtml = (text) => /^\s*<(!doctype|html)/i.test(text);
const contentType = (response) => response.headers.get('content-type') ?? '';

/** Text without fenced code blocks (``` and ~~~) and inline code spans, whose link syntax is only an example. */
function withoutCode(text) {
  const lines = [];
  let fence = null;
  for (const line of text.split('\n')) {
    const marker = /^\s{0,3}(`{3,}|~{3,})/.exec(line)?.[1];
    if (fence === null && marker) {
      fence = marker;
    } else if (fence !== null) {
      if (marker && marker[0] === fence[0] && marker.length >= fence.length && line.trim() === marker) fence = null;
    } else {
      lines.push(line.replace(/(`+)[\s\S]*?\1/g, ''));
    }
  }
  return lines.join('\n');
}

/** Markdown link targets of a text outside code, resolved against its URL, without fragments. */
function links(text, from) {
  const out = [];
  for (const match of withoutCode(text).matchAll(/\]\(<?([^)\s>]+)>?(?:\s+"[^"]*")?\)/g)) {
    if (match[1].startsWith('#') || match[1].startsWith('mailto:')) continue;
    try {
      const url = new URL(match[1], from);
      url.hash = '';
      out.push(url.href);
    } catch { /* not a URL */ }
  }
  return [...new Set(out)];
}

/** Fetch every URL (a few at a time); returns the failures as "status url". */
async function unresolved(urls, limit = 8) {
  const failures = [];
  const queue = [...urls];
  await Promise.all(Array.from({ length: limit }, async () => {
    while (queue.length) {
      const url = queue.shift();
      const response = await get(url);
      if (response.status !== 200) failures.push(`${response.status || response.error} ${url}`);
    }
  }));
  return failures;
}

// ------------------------------------------------------------------ checks

async function score(base) {
  base = base.replace(/\/+$/, '');
  const origin = new URL(base).origin;
  const sameOrigin = (url) => new URL(url).origin === origin;
  const results = [];
  const passed = new Set();
  const record = (id, weight, pass, detail = '', dependsOn = []) => {
    const blocked = dependsOn.find((dependency) => !passed.has(dependency));
    const ok = pass && !blocked;
    if (ok) passed.add(id);
    results.push({ id, weight, pass: ok, detail: blocked ? `needs ${blocked}` : detail });
  };

  // llms.txt
  const llms = await get(`${base}/llms.txt`);
  record('llmsTxtExists', 10, llms.status === 200 && llms.body.trim() !== '' && !isHtml(llms.body), `HTTP ${llms.status || llms.error}`);
  const llmsLinks = links(llms.body, llms.url);
  record('llmsTxtValid', 5, /^# \S/m.test(llms.body) && /^> \S/m.test(llms.body) && llmsLinks.length > 0,
    `${llmsLinks.length} links`, ['llmsTxtExists']);
  record('llmsTxtSize', 3, llms.body.length <= LLMS_LIMIT, `${llms.body.length} characters`, ['llmsTxtExists']);

  // Follow the split indexes: every same-origin link below /_llms/ is an index to read.
  const pageLinks = [];
  const indexes = new Set();
  const pending = llmsLinks.filter(sameOrigin);
  while (pending.length) {
    const url = pending.shift();
    if (new URL(url).pathname.includes('/_llms/')) {
      if (indexes.has(url)) continue;
      indexes.add(url);
      const index = await get(url);
      pending.push(...links(index.body, url).filter(sameOrigin));
    } else if (!pageLinks.includes(url)) {
      pageLinks.push(url);
    }
  }
  const llmsFailures = await unresolved([...indexes, ...pageLinks]);
  record('llmsTxtLinksResolve', 5, llmsFailures.length === 0,
    llmsFailures.length ? llmsFailures.slice(0, 5).join(', ') : `${pageLinks.length} pages, ${indexes.size} indexes`, ['llmsTxtExists']);
  const notMarkdown = pageLinks.filter((url) => !new URL(url).pathname.endsWith('.md'));
  record('llmsTxtLinksMarkdown', 5, pageLinks.length > 0 && notMarkdown.length === 0,
    notMarkdown.length ? `not .md: ${notMarkdown.slice(0, 3).join(', ')}` : `${pageLinks.length} .md links`, ['llmsTxtExists']);
  const firstPage = pageLinks[0] ? await get(pageLinks[0]) : null;
  record('llmsTxtDirective', 3, firstPage !== null && /llms\.txt/.test(firstPage.body.slice(0, 1000)),
    firstPage ? pageLinks[0] : 'no page listed', ['llmsTxtExists']);

  // llms-full.txt
  const full = await get(`${base}/llms-full.txt`);
  record('llmsTxtFullExists', 5, full.status === 200 && full.body.trim() !== '' && !isHtml(full.body), `HTTP ${full.status || full.error}`);
  record('llmsTxtFullSize', 2, full.body.length <= FULL_LIMIT, `${full.body.length} characters`, ['llmsTxtFullExists']);
  record('llmsTxtFullValid', 2, /^# \S/m.test(full.body) && !isHtml(full.body), `${(full.body.match(/^# /gm) ?? []).length} top-level headings`, ['llmsTxtFullExists']);
  const fullFailures = await unresolved(links(full.body, full.url).filter(sameOrigin));
  record('llmsTxtFullLinksResolve', 3, fullFailures.length === 0, fullFailures.slice(0, 5).join(', '), ['llmsTxtFullExists']);

  // skill.md and the discovery index
  const skill = await get(`${base}/skill.md`);
  const front = /^---\n([\s\S]*?)\n---/.exec(skill.body)?.[1] ?? '';
  const problems = [];
  if (skill.status !== 200) problems.push(`HTTP ${skill.status || skill.error}`);
  if (!/^name:\s*\S/m.test(front) || !/^description:\s*\S/m.test(front)) problems.push('frontmatter without name and description');
  const skillsIndex = await get(`${base}/.well-known/agent-skills/index.json`);
  let skillCount = 0;
  if (skillsIndex.status === 200) {
    try {
      for (const entry of JSON.parse(skillsIndex.body).skills ?? []) {
        skillCount++;
        const artifact = await fetch(new URL(entry.url, skillsIndex.url), { signal: AbortSignal.timeout(15000) });
        const digest = `sha256:${createHash('sha256').update(Buffer.from(await artifact.arrayBuffer())).digest('hex')}`;
        if (digest !== entry.digest) problems.push(`digest mismatch for ${entry.name}`);
      }
    } catch (err) {
      problems.push(`agent-skills index: ${err.message}`);
    }
  }
  record('skillMd', 5, problems.length === 0, problems.join('; ') || `${skillCount} skills in the discovery index`);

  // The page for negotiation and headers.
  const page = options.page
    ? new URL(options.page, `${base}/`).href
    : pageLinks[0]
      ? pageLinks[0].replace(/\/index\.md$/, '').replace(/\.md$/, '')
      : `${base}/`;
  const markdown = await get(page, { Accept: 'text/markdown' });
  record('contentNegotiationMarkdown', 10, markdown.status === 200 && contentType(markdown).includes('text/markdown') && !isHtml(markdown.body),
    `${page}: ${markdown.status} ${contentType(markdown)}`);
  const plain = await get(page, { Accept: 'text/plain' });
  record('contentNegotiationPlaintext', 5, plain.status === 200 && contentType(plain).includes('text/plain') && !isHtml(plain.body),
    `${plain.status} ${contentType(plain)}`);
  const openai = [];
  const markdownUrl = pageLinks[0] ?? `${page.replace(/\/+$/, '')}.md`;
  for (const agent of ['ChatGPT-User/1.0', 'OAI-SearchBot/1.0', 'GPTBot/1.1']) {
    const ua = `Mozilla/5.0 (compatible; ${agent}; +https://openai.com/bot)`;
    const direct = await get(markdownUrl, { 'User-Agent': ua });
    if (direct.status !== 200 || !contentType(direct).includes('text/plain')) openai.push(`${agent} ${markdownUrl}: ${direct.status} ${contentType(direct)}`);
    const negotiated = await get(page, { 'User-Agent': ua, Accept: 'text/markdown' });
    if (negotiated.status !== 200 || !contentType(negotiated).includes('text/plain') || isHtml(negotiated.body)) {
      openai.push(`${agent} ${page}: ${negotiated.status} ${contentType(negotiated)}`);
    }
  }
  record('contentTypeOpenAI', 5, openai.length === 0, openai.join('; ') || 'text/plain for ChatGPT-User, OAI-SearchBot and GPTBot');

  // robots.txt and the sitemap
  let robots = await get(`${base}/robots.txt`);
  if (robots.status !== 200 && `${origin}/robots.txt` !== `${base}/robots.txt`) robots = await get(`${origin}/robots.txt`);
  const blocked = robots.status === 200 ? blockedAgents(robots.body) : [];
  record('robotsTxtAllowsAI', 5, blocked.length === 0,
    robots.status !== 200 ? 'no robots.txt, nothing blocked' : blocked.length ? `blocks ${blocked.join(', ')}` : robots.url);
  const named = robots.status === 200 ? /^sitemap:\s*(\S+)/im.exec(robots.body)?.[1] : null;
  const sitemap = await get(named ?? `${base}/sitemap.xml`);
  record('sitemapExists', 5, sitemap.status === 200 && /<(urlset|sitemapindex)\b/.test(sitemap.body),
    `${sitemap.url}: HTTP ${sitemap.status || sitemap.error}, ${(sitemap.body.match(/<loc>/g) ?? []).length} URLs`);

  // JSON-LD on the start page
  const home = await get(`${base}/`);
  const types = [];
  let blocks = 0;
  for (const match of home.body.matchAll(/<script[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi)) {
    try {
      const data = JSON.parse(match[1]);
      blocks++;
      for (const node of [data, ...(data['@graph'] ?? [])]) if (node['@type']) types.push(node['@type']);
    } catch { /* invalid block */ }
  }
  record('structuredData', 5, blocks > 0, `${blocks} blocks: ${types.join(', ') || 'none'}`);

  // Discovery headers, also on a 404
  const html = await get(page, { Accept: 'text/html' });
  const link = html.headers.get('link') ?? '';
  record('discoveryLinkHeader', 5, /rel="?llms-txt"?/.test(link), link || 'no Link header');
  const missing = await get(`${base}/agent-score-missing-page-${Date.now()}`, { Accept: 'text/html' });
  const missingLink = missing.headers.get('link') ?? '';
  record('discoveryLinkHeader404', 3, missing.status === 404 && /rel="?llms-txt"?/.test(missingLink),
    `HTTP ${missing.status}${missingLink ? '' : ', no Link header'}`);

  // Latency
  const times = [];
  for (let i = 0; i < 5; i++) times.push((await get(`${base}/`)).ms);
  times.sort((a, b) => a - b);
  record('responseLatency', 5, times[2] < LATENCY_MS, `median ${Math.round(times[2])} ms`);

  const total = results.reduce((sum, r) => sum + r.weight, 0);
  const earned = results.reduce((sum, r) => sum + (r.pass ? r.weight : 0), 0);
  return { base, score: Math.round((earned / total) * 100), passed: results.filter((r) => r.pass).length, results };
}

/** User agents among AI_AGENTS whose robots.txt group disallows the whole site. */
function blockedAgents(text) {
  const blocked = [];
  let agents = [];
  let inRules = false;
  for (const raw of text.split(/\r?\n/)) {
    const line = raw.replace(/#.*/, '').trim();
    const match = /^([a-z-]+)\s*:\s*(.*)$/i.exec(line);
    if (!match) continue;
    const [, field, value] = match;
    if (field.toLowerCase() === 'user-agent') {
      if (inRules) agents = [];
      inRules = false;
      agents.push(value);
    } else {
      inRules = true;
      if (field.toLowerCase() === 'disallow' && value === '/') {
        for (const agent of agents) {
          if (AI_AGENTS.some((ai) => ai.toLowerCase() === agent.toLowerCase())) blocked.push(agent);
        }
      }
    }
  }
  return [...new Set(blocked)];
}

// ------------------------------------------------------------------ demo

function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address();
      server.close(() => resolve(port));
    });
  });
}

async function serveDemo() {
  const port = await freePort();
  const origin = `http://127.0.0.1:${port}`;
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'pholio-agent-score-'));
  process.on('exit', () => fs.rmSync(root, { recursive: true, force: true }));
  const build = spawnSync(options.php, [
    path.join(pholioRoot, 'bin/pholio'), 'build', '--config', path.join(pholioRoot, 'examples/demo/pholio.config.php'),
    '--out', root, '--set', `site.url=${origin}`, '--quiet',
  ], { stdio: ['ignore', 'inherit', 'inherit'] });
  if (build.status !== 0) {
    console.error(`Demo build failed${build.error ? `: ${build.error.message}` : ''}`);
    process.exit(2);
  }
  const server = spawn(options.php, ['-S', `127.0.0.1:${port}`, '-t', root, path.join(pholioRoot, 'src/DevServer.php')], {
    stdio: 'ignore',
    env: { ...process.env, PHOLIO_HOME_URL: '/' },
  });
  process.on('exit', () => server.kill());
  for (let i = 0; i < 100; i++) {
    if ((await get(`${origin}/llms.txt`)).status === 200) return origin;
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  console.error('The demo server did not start');
  process.exit(2);
}

// ------------------------------------------------------------------ main

const base = options.demo ? await serveDemo() : options.base;
const report = await score(base);
if (options.json) {
  console.log(JSON.stringify(report, null, 2));
} else {
  console.log(`Agent score for ${report.base}\n`);
  for (const r of report.results) {
    console.log(`${r.pass ? 'ok  ' : 'FAIL'}  ${r.id.padEnd(28)} ${String(r.weight).padStart(2)}  ${r.detail}`);
  }
  console.log(`\nScore: ${report.score}/100 (${report.passed} of ${report.results.length} checks passed)`);
}
process.exit(report.score >= options.minScore ? 0 : 1);
