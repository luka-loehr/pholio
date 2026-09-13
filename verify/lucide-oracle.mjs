#!/usr/bin/env node
// Lucide oracle: renders lucide icons with lucide-react itself (react-dom/server,
// renderToStaticMarkup), so src/lib/Icons.php is checked against the original
// rather than against its own expectations.
//
//   echo '[{"name":"archive","className":"size-4"},{"name":"moon","fill":"currentColor"}]' \
//     | node verify/lucide-oracle.mjs [--package <dir>/node_modules/lucide-react]
//   node verify/lucide-oracle.mjs --check [--sample 50] [--seed <n>] [--php <binary>]
//
// Default package: verify/node_modules/lucide-react (pinned in verify/package.json).
//
// Render mode (stdin): a list of { name (kebab-case or alias), className?, fill? }.
// Output (stdout): a list of the same length of { markup } or { error }.
// Empty child elements (`<path …></path>`) are written as `<path …/>`, as in the
// reference export and in Icons.php; the DOM is the same.
//
// Check mode: draws a seeded random sample of names from Icons::names(), renders each
// with PHP (Icons::svg) and with lucide-react, and compares them character by character.
// Prints "lucide oracle: <passed>/<total>" and exits 1 on any difference.

import { spawnSync } from 'node:child_process';
import path from 'node:path';
import process from 'node:process';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';

const pholioRoot = path.resolve(fileURLToPath(new URL('..', import.meta.url)));

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`);
  return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}
const pkgDir = path.resolve(arg('package', path.join(pholioRoot, 'verify/node_modules/lucide-react')));

const require = createRequire(path.join(pkgDir, 'package.json'));
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');
const lucide = await import(pathToFileURL(path.join(pkgDir, 'dist/esm/lucide-react.mjs')).href);
const { default: dynamicIconImports } = await import(pathToFileURL(path.join(pkgDir, 'dist/esm/dynamicIconImports.mjs')).href);

const toKebabCase = (s) => s.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();
// Re-export names without the `Lucide` prefix and the `Icon` suffix → component.
const byExportName = new Map();
for (const [exportName, component] of Object.entries(lucide)) {
  if (typeof component !== 'object' && typeof component !== 'function') continue;
  let base = exportName;
  if (base.startsWith('Lucide') && base.length > 6 && lucide[base.slice(6)]) base = base.slice(6);
  else if (base.endsWith('Icon') && base.length > 4 && lucide[base.slice(0, -4)]) base = base.slice(0, -4);
  const kebab = toKebabCase(base);
  if (!byExportName.has(kebab)) byExportName.set(kebab, component);
}

async function resolve(name) {
  if (Object.prototype.hasOwnProperty.call(dynamicIconImports, name)) return (await dynamicIconImports[name]()).default;
  return byExportName.get(name) ?? null;
}

async function render(requests) {
  const results = [];
  for (const req of requests) {
    const Component = await resolve(req.name);
    if (!Component) {
      results.push({ error: `lucide-react does not know "${req.name}"` });
      continue;
    }
    const props = {};
    if (req.className) props.className = req.className;
    if (req.fill) props.fill = req.fill;
    const markup = renderToStaticMarkup(React.createElement(Component, props)).replace(
      /<([a-z]+)((?:\s[^<>]*)?)><\/\1>/g,
      '<$1$2/>',
    );
    results.push({ markup });
  }
  return results;
}

// PHP side of check mode: stdin { names?: true, render?: [{name, className}] } → JSON.
const PHP_RENDER = `
require ${JSON.stringify(path.join(pholioRoot, 'src/lib/Icons.php'))};
$in = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$out = ['names' => Pholio\\Icons::names(), 'markup' => []];
foreach ($in['render'] ?? [] as $r) {
    try {
        $out['markup'][] = Pholio\\Icons::svg($r['name'], $r['className']);
    } catch (Throwable $e) {
        $out['markup'][] = 'error: ' . $e->getMessage();
    }
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
`;

function php(input) {
  const result = spawnSync(arg('php', 'php'), ['-r', PHP_RENDER], { input: JSON.stringify(input), encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  if (result.status !== 0) {
    console.error(`php failed (exit ${result.status}):\n${result.stderr}${result.error ?? ''}`);
    process.exit(1);
  }
  return JSON.parse(result.stdout);
}

// mulberry32: small seeded generator, so a failing sample can be repeated.
function random(seed) {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

if (process.argv.includes('--check')) {
  const size = Number(arg('sample', '50'));
  const seed = Number(arg('seed', String(Math.floor(Math.random() * 2 ** 31))));
  if (!Number.isInteger(size) || size < 1 || !Number.isInteger(seed)) {
    console.error('usage: node verify/lucide-oracle.mjs --check [--sample <n>] [--seed <n>]');
    process.exit(2);
  }
  const pool = [...php({}).names];
  const next = random(seed);
  const extraClasses = ['', 'size-4', 'size-5 -me-0.5 fill-(--callout-color) text-fd-card', 'lucide  twice twice', 'nd-home-card-icon'];
  const requests = [];
  while (requests.length < size && pool.length > 0) {
    const name = pool.splice(Math.floor(next() * pool.length), 1)[0];
    requests.push({ name, className: extraClasses[requests.length % extraClasses.length] });
  }
  const expected = await render(requests);
  const actual = php({ render: requests }).markup;
  let passed = 0;
  requests.forEach((req, i) => {
    const want = expected[i].markup ?? `error: ${expected[i].error}`;
    if (actual[i] === want) {
      passed += 1;
      return;
    }
    console.error(`differs: ${req.name} [${req.className}]\n  lucide-react: ${want}\n  Icons.php:    ${actual[i]}`);
  });
  console.log(`lucide oracle: ${passed}/${requests.length} (seed ${seed}, lucide-react ${require(path.join(pkgDir, 'package.json')).version})`);
  process.exit(passed === requests.length ? 0 : 1);
}

// Read stdin as a stream: readFileSync(0) fails on non-blocking pipes (EAGAIN).
let input = '';
process.stdin.setEncoding('utf8');
for await (const chunk of process.stdin) input += chunk;
process.stdout.write(`${JSON.stringify(await render(JSON.parse(input)))}\n`);
