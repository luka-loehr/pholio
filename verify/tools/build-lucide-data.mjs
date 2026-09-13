#!/usr/bin/env node
// Developer tool, never part of a build: generates vendor-data/lucide/icons.json
// from an installed lucide-react. The PHP generator reads only that file.
//
//   node verify/tools/build-lucide-data.mjs
//   node verify/tools/build-lucide-data.mjs --package <dir>/node_modules/lucide-react --out <file>
//
// Defaults: --package verify/node_modules/lucide-react (pinned in verify/package.json),
// --out vendor-data/lucide/icons.json, both relative to the Pholio root.
//
// Sources in the package:
//   dist/esm/icons/<name>.mjs   per icon `__iconData` = { name, size, node: [[tag, attrs], …], aliases? }
//   dist/esm/lucide-react.mjs   re-exports of every React name (`Sidebar`, `SidebarIcon`, `LucideSidebar` …)
//
// Output (compact, sorted, deterministic):
//   icons:        name → child markup, exactly as React serialises it, only empty elements as `/>`
//   classAliases: name → alias list from `__iconData.aliases` (these become the extra classes)
//   aliases:      alias (kebab-case) → canonical name
// Unexpected data shapes abort instead of silently producing wrong markup.

import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath, pathToFileURL } from 'node:url';

const pholioRoot = path.resolve(fileURLToPath(new URL('../..', import.meta.url)));

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`);
  return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}
const pkgDir = path.resolve(arg('package', path.join(pholioRoot, 'verify/node_modules/lucide-react')));
const outFile = path.resolve(arg('out', path.join(pholioRoot, 'vendor-data/lucide/icons.json')));

function fail(message) {
  console.error(`error: ${message}`);
  process.exit(1);
}

// toKebabCase from lucide-react/dist/esm/shared/src/utils/toKebabCase.mjs
const toKebabCase = (s) => s.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();

// escapeTextForBrowser from react-dom: & " ' < >
const escapeAttr = (s) =>
  String(s).replace(/[&"'<>]/g, (c) => ({ '&': '&amp;', '"': '&quot;', "'": '&#x27;', '<': '&lt;', '>': '&gt;' })[c]);

const TAGS = new Set(['path', 'circle', 'rect', 'line', 'ellipse', 'polyline', 'polygon']);
const ATTR = /^[a-z][a-z0-9]*$/; // only names React emits unchanged (no camelCase rewriting)

let pkg;
try {
  pkg = JSON.parse(await fs.readFile(path.join(pkgDir, 'package.json'), 'utf8'));
} catch {
  fail(`${pkgDir}/package.json not readable (run npm ci in verify/ or pass --package)`);
}
if (pkg.name !== 'lucide-react') fail(`${pkgDir} is not a lucide-react package`);

const iconsDir = path.join(pkgDir, 'dist/esm/icons');
const files = (await fs.readdir(iconsDir)).filter((f) => f.endsWith('.mjs') && f !== 'index.mjs').sort();

const icons = {};
const classAliases = {};
for (const file of files) {
  const mod = await import(pathToFileURL(path.join(iconsDir, file)).href);
  const data = mod.__iconData;
  if (!data) continue; // alias file: `export { default } from './<canonical>.mjs'`
  const name = data.name;
  if (`${name}.mjs` !== file) fail(`${file}: name "${name}" does not match the file name`);
  if ((data.size ?? 24) !== 24 || data.width !== undefined || data.height !== undefined) fail(`${name}: size is not 24`);
  let markup = '';
  for (const child of data.node) {
    const [tag, attrs, children] = child;
    if (!TAGS.has(tag)) fail(`${name}: unknown element <${tag}>`);
    if (children !== undefined) fail(`${name}: nested child nodes are not supported`);
    let a = '';
    for (const [key, value] of Object.entries(attrs)) {
      if (key === 'key') continue;
      if (!ATTR.test(key)) fail(`${name}: attribute name "${key}" would be rewritten by React`);
      if (typeof value !== 'string' && typeof value !== 'number') fail(`${name}: attribute ${key} is not a scalar`);
      a += ` ${key}="${escapeAttr(value)}"`;
    }
    markup += `<${tag}${a}/>`;
  }
  icons[name] = markup;
  const aliases = (data.aliases ?? []).filter((x) => typeof x === 'string' && x.trim() !== '');
  if (aliases.length) classAliases[name] = aliases;
}

// Aliases: everything lucide-react.mjs re-exports under another name.
const main = await fs.readFile(path.join(pkgDir, 'dist/esm/lucide-react.mjs'), 'utf8');
const aliases = {};
const conflicts = [];
function addAlias(alias, target, source) {
  if (alias === target || icons[alias] !== undefined) return; // canonical names always win
  if (aliases[alias] !== undefined && aliases[alias] !== target) {
    conflicts.push(`${alias}: ${aliases[alias]} / ${target} (${source})`);
    return;
  }
  aliases[alias] = target;
}
const exportRe = /export \{([^}]*)\} from '\.\/icons\/([a-z0-9-]+)\.mjs';/g;
let reExports = 0;
for (const m of main.matchAll(exportRe)) {
  const target = m[2];
  if (icons[target] === undefined) fail(`re-export of unknown icon ${target}`);
  reExports += 1;
  const names = [...m[1].matchAll(/default as ([A-Za-z0-9_]+)/g)].map((x) => x[1]);
  const exported = new Set(names);
  for (const n of names) {
    let base = n;
    if (base.startsWith('Lucide') && exported.has(base.slice(6))) base = base.slice(6);
    else if (base.endsWith('Icon') && exported.has(base.slice(0, -4))) base = base.slice(0, -4);
    addAlias(toKebabCase(base), target, n);
  }
}
if (reExports !== Object.keys(icons).length) fail(`${reExports} re-export lines, but ${Object.keys(icons).length} icons`);
// The data aliases and the alias files in dist/esm/icons must be covered.
for (const [name, list] of Object.entries(classAliases)) for (const a of list) addAlias(a, name, `${name}.aliases`);
for (const file of files) {
  const n = file.slice(0, -4);
  if (icons[n] === undefined && aliases[n] === undefined) fail(`alias file ${file} without an alias entry`);
}
if (conflicts.length) fail(`ambiguous aliases:\n  ${conflicts.join('\n  ')}`);

const sortObj = (o) => Object.fromEntries(Object.keys(o).sort().map((k) => [k, o[k]]));
const data = {
  _license: `lucide-react v${pkg.version} - ISC. Icon data (c) Lucide Icons and Contributors, parts derived from Feather (MIT, Cole Bemis). See LICENSE next to this file.`,
  _generated: 'verify/tools/build-lucide-data.mjs - do not edit by hand',
  package: 'lucide-react',
  version: pkg.version,
  icons: sortObj(icons),
  classAliases: sortObj(classAliases),
  aliases: sortObj(aliases),
};
await fs.mkdir(path.dirname(outFile), { recursive: true });
const json = `${JSON.stringify(data)}\n`;
await fs.writeFile(outFile, json);
console.log(
  `${path.relative(pholioRoot, outFile)}: ${Object.keys(icons).length} icons, ${Object.keys(aliases).length} aliases, ` +
    `${Object.keys(classAliases).length} with alias classes, ${json.length} bytes, lucide-react ${pkg.version}`,
);
