#!/usr/bin/env node
/**
 * css-values.mjs — checks the theme stylesheets in theme/css.
 *
 * Mode 1, values (`--reference`): proves that tokens.css, animations.css,
 * notebook.css and prose.css carry the same values as the compiled Tailwind
 * stylesheet of the reference build.
 *
 *   1. Every custom property the reference stylesheet sets on one of the token
 *      selectors (:root, :root, :host, .dark, #nd-sidebar, .dark #nd-sidebar,
 *      [dir="rtl"], *, ::before, ::after, ::backdrop) must appear in tokens.css
 *      with the same final value, and vice versa.
 *      Final value = last declaration in document order, so the
 *      @supports (color: lab(…)) and (color-mix) follow-ups count correctly.
 *      The site palette is inserted at the `@pholio:palette` marker first
 *      (`--palette`), because the reference was built with one.
 *   2. Every @property registration with an identical body.
 *   3. Every @keyframes with an identical, normalised body.
 *   4. The layer order. The reference doesn't declare it as a list but through
 *      the order of first appearance of the @layer rules; notebook.css must carry
 *      exactly this order as an @layer statement. If `properties` dropped out,
 *      tokens.css would open the layer itself and it would be the strongest
 *      instead of the weakest.
 *   5. Every rule prose.css takes over from the reference (.prose,
 *      .prose-no-margin, .fd-step, .fd-steps, .fd-scroll-container, .shiki), with
 *      an identical selector AND an identical at-rule context. This catches
 *      mistakes when copying line ranges: a forgotten closing brace leaves the
 *      file valid but moves rules into an @media or @supports block, where they
 *      only apply sometimes.
 *
 * Mode 2, compare (`--compare`): proves that the stylesheet the build delivers
 * equals an existing delivered notebook.css rule for rule. The candidate is
 * theme/css joined the way the builder joins it (@import lines replaced by the
 * parts, the palette marker replaced by `--palette`), or a delivered file given
 * with `--candidate`. Both sides lose their comments and blank lines and have
 * their lines trimmed; the expected side also gets the class renames of
 * RENAMES applied, so a stylesheet from before a rename still compares.
 *
 * Usage:
 *   node verify/css-values.mjs --reference <reference.css> [--palette <palette.css>]
 *   node verify/css-values.mjs --compare <notebook.css> [--palette <palette.css>] [--candidate <notebook.css>]
 * Both modes can run in one call. Exit code 0 = no difference, 1 = difference,
 * 2 = usage error.
 */

import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { arg } from './lib/cli.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const themeCss = resolve(here, '../theme/css');

/** Marker line in tokens.css that the builder replaces with the site palette. */
const PALETTE_MARKER = '/* @pholio:palette */';

/** Class renames between an older delivered stylesheet and this theme. */
const RENAMES = [['nd-tocpop-panel', 'nd-collapsible-panel']];

const referencePath = arg('reference', null);
const comparePath = arg('compare', null);
const palettePath = arg('palette', null);
const candidatePath = arg('candidate', null);

if (referencePath === null && comparePath === null) {
  console.error(
    'usage: node verify/css-values.mjs --reference <reference.css> [--palette <palette.css>]\n' +
      '       node verify/css-values.mjs --compare <notebook.css> [--palette <palette.css>] [--candidate <notebook.css>]',
  );
  process.exit(2);
}

const palette = palettePath === null ? '' : readFileSync(palettePath, 'utf8');
const themeFile = (name) => readFileSync(resolve(themeCss, name), 'utf8');

/** Inserts the palette at the marker, as the builder does. */
function withPalette(css) {
  return css.includes(PALETTE_MARKER) ? css.replaceAll(PALETTE_MARKER, palette.trimEnd()) : css;
}

/** theme/css joined into one stylesheet, as the builder delivers it. */
function assemble() {
  const joined = themeFile('notebook.css').replace(
    /^@import\s+"\.\/([^"]+)";[ \t]*$/gm,
    (_, part) => `/* ---- ${part} ---- */\n${themeFile(part).trimEnd()}\n`,
  );
  return withPalette(joined);
}

/** Selectors whose custom properties count as theme tokens. */
const TOKEN_SELECTORS = new Set([
  ':root',
  ':root,:host',
  '.dark',
  '#nd-sidebar',
  '.dark #nd-sidebar',
  '[dir="rtl"]',
  '*,:before,:after,::backdrop',
]);

const norm = (s) => s.replace(/\s+/g, ' ').trim();
const normSelector = (s) => norm(s).replace(/\s*,\s*/g, ',');
const normBody = (s) => norm(s).replace(/\s*([{};:,])\s*/g, '$1').replace(/;}/g, '}');

/** Splits CSS into a flat list of rules and at-rules. */
function parse(rawCss) {
  const css = stripComments(rawCss);
  const rules = [];   // { selector, decls: [[prop, value]] }
  const atRules = []; // { name, prelude, body }
  const layers = [];  // layer names in the order of first appearance

  /** Records the names of an @layer rule or statement, without duplicates. */
  const noteLayers = (list) => {
    for (const raw of list.split(',')) {
      const name = raw.trim();
      if (name && !layers.includes(name)) layers.push(name);
    }
  };

  /** Index of the closing brace of the block that opens at `start`. */
  const readBlock = (start) => {
    let depth = 0;
    for (let j = start; j < css.length; j++) {
      if (css[j] === '{') depth++;
      else if (css[j] === '}') {
        depth--;
        if (depth === 0) return j;
      }
    }
    return css.length;
  };

  /** Walks [from, to) of the complete stylesheet. */
  const walk = (from, to) => {
    let pos = from;
    while (pos < to) {
      let brace = css.indexOf('{', pos);
      if (brace === -1 || brace >= to) {
        // No block left: only statements, e.g. the @layer list in notebook.css
        // or @import. They still have to be read.
        let rest = pos;
        while (rest < to) {
          const s2 = css.indexOf(';', rest);
          if (s2 === -1 || s2 >= to) break;
          const statement = css.slice(rest, s2).trim();
          if (statement.startsWith('@layer')) noteLayers(statement.slice('@layer'.length));
          rest = s2 + 1;
        }
        break;
      }
      const semi = css.indexOf(';', pos);
      if (semi !== -1 && semi < brace) {
        const statement = css.slice(pos, semi).trim();
        if (statement.startsWith('@layer')) noteLayers(statement.slice('@layer'.length));
        pos = semi + 1;
        continue;
      }
      const prelude = css.slice(pos, brace).trim();
      const end = readBlock(brace);
      if (prelude.startsWith('@')) {
        const name = prelude.split(/[\s({]/)[0];
        if (name === '@keyframes' || name === '@property') {
          atRules.push({
            name,
            prelude: norm(prelude),
            body: normBody(css.slice(brace + 1, end)),
          });
        } else {
          if (name === '@layer') noteLayers(prelude.slice('@layer'.length));
          walk(brace + 1, end);
        }
      } else {
        const decls = [];
        for (const part of css.slice(brace + 1, end).split(';')) {
          const c = part.indexOf(':');
          if (c === -1) continue;
          const prop = part.slice(0, c).trim();
          if (!prop.startsWith('--')) continue;
          if (part.includes('{')) continue;
          decls.push([prop, norm(part.slice(c + 1))]);
        }
        if (decls.length) rules.push({ selector: normSelector(prelude), decls });
      }
      pos = end + 1;
    }
  };

  walk(0, css.length);
  return { rules, atRules, layers };
}

/**
 * Every style rule as { selector, context }. `context` is the path of the
 * enclosing at-rules without @layer, because layer membership is checked in
 * point 4 and prose.css knows only one layer.
 */
function styleRules(rawCss) {
  const css = stripComments(rawCss);
  const out = [];
  const close = (i) => {
    let d = 0;
    for (let j = i; j < css.length; j++) {
      if (css[j] === '{') d++;
      else if (css[j] === '}') { d--; if (d === 0) return j; }
    }
    return css.length;
  };
  const walk = (from, to, ctx) => {
    let pos = from;
    while (pos < to) {
      const brace = css.indexOf('{', pos);
      if (brace === -1 || brace >= to) break;
      const semi = css.indexOf(';', pos);
      if (semi !== -1 && semi < brace) { pos = semi + 1; continue; }
      const prelude = norm(css.slice(pos, brace));
      const end = close(brace);
      if (prelude.startsWith('@')) {
        const isLayer = prelude.startsWith('@layer');
        const isFrames = prelude.startsWith('@keyframes') || prelude.startsWith('@property');
        if (!isFrames) walk(brace + 1, end, isLayer ? ctx : [...ctx, prelude]);
      } else {
        out.push({ selector: normSelector(prelude), context: ctx.join(' > ') });
      }
      pos = end + 1;
    }
  };
  walk(0, css.length, []);
  return out;
}

/** The rules prose.css takes over from the reference. */
const PROSE_RULE = /\.(prose|fd-step|fd-steps|fd-scroll-container|shiki)/;
function proseRules(css) {
  const map = new Map();
  const seen = new Map();
  for (const rule of styleRules(css)) {
    if (!PROSE_RULE.test(rule.selector)) continue;
    // figure.shiki belongs to the code block component, not to prose.css
    if (rule.selector.startsWith('figure.shiki')) continue;
    // Repeated selectors (e.g. .prose four times) get a counter, otherwise a
    // lost repetition would go unnoticed.
    const base = `${rule.context} || ${rule.selector}`;
    const n = (seen.get(base) ?? 0) + 1;
    seen.set(base, n);
    map.set(`${base} #${n}`, rule.selector);
  }
  return map;
}

function stripComments(css) {
  return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

/** Final values per token selector, resolved in document order. */
function tokenMap(parsed) {
  const out = new Map();
  for (const rule of parsed.rules) {
    if (!TOKEN_SELECTORS.has(rule.selector)) continue;
    for (const [prop, value] of rule.decls) {
      out.set(`${rule.selector} | ${prop}`, value);
    }
  }
  return out;
}

function atMap(parsed, name) {
  const out = new Map();
  for (const rule of parsed.atRules) {
    if (rule.name !== name) continue;
    out.set(rule.prelude, rule.body);
  }
  return out;
}

function diff(label, expected, actual) {
  const problems = [];
  for (const [key, value] of expected) {
    if (!actual.has(key)) problems.push(`${label}: missing in the theme  ${key}`);
    else if (actual.get(key) !== value)
      problems.push(`${label}: value differs  ${key}\n    reference: ${value}\n    theme    : ${actual.get(key)}`);
  }
  for (const key of actual.keys()) {
    if (!expected.has(key)) problems.push(`${label}: extra in the theme  ${key}`);
  }
  return problems;
}

/** Checks the layer order of notebook.css against the reference. */
function diffLayers(expected, actual) {
  if (expected.join(', ') === actual.join(', ')) return [];
  return [
    '@layer: order differs\n' +
      `    reference (first appearance): ${expected.join(', ')}\n` +
      `    notebook.css                : ${actual.join(', ') || '(no @layer statement)'}`,
  ];
}

/** Rule lines without comments, blank lines and surrounding whitespace. */
function ruleLines(css) {
  return stripComments(css)
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean);
}

/** Compares a delivered notebook.css with the one built from theme/css. */
function compare(expectedCss, candidateCss) {
  let renamed = expectedCss;
  for (const [from, to] of RENAMES) renamed = renamed.replaceAll(from, to);
  const expected = ruleLines(renamed);
  const actual = ruleLines(candidateCss);
  const problems = [];
  const n = Math.max(expected.length, actual.length);
  for (let i = 0; i < n && problems.length < 20; i++) {
    if (expected[i] === actual[i]) continue;
    problems.push(
      `compare: rule line ${i + 1} differs\n` +
        `    expected : ${expected[i] ?? '(end of file)'}\n` +
        `    candidate: ${actual[i] ?? '(end of file)'}`,
    );
    break;
  }
  if (!problems.length && expected.length !== actual.length) {
    problems.push(`compare: ${expected.length} expected rule lines, ${actual.length} in the candidate`);
  }
  return { problems, lines: expected.length };
}

const problems = [];

if (referencePath !== null) {
  const referenceCss = readFileSync(referencePath, 'utf8');
  const reference = parse(referenceCss);
  const tokens = parse(withPalette(themeFile('tokens.css')));
  const animations = parse(themeFile('animations.css'));
  const notebook = parse(themeFile('notebook.css'));
  const proseCss = themeFile('prose.css');

  problems.push(
    ...diff('Token', tokenMap(reference), tokenMap(tokens)),
    ...diff('@property', atMap(reference, '@property'), atMap(tokens, '@property')),
    ...diff('@keyframes', atMap(reference, '@keyframes'), atMap(animations, '@keyframes')),
    ...diffLayers(reference.layers, notebook.layers),
    ...diff('Prose rule', proseRules(referenceCss), proseRules(proseCss)),
  );

  const counts = {
    Token: tokenMap(reference).size,
    '@property': atMap(reference, '@property').size,
    '@keyframes': atMap(reference, '@keyframes').size,
  };
  console.log(`Reference: ${referencePath}`);
  console.log(`  Palette: ${palettePath ?? '(none)'}`);
  for (const [k, v] of Object.entries(counts)) console.log(`  ${k}: ${v} checked`);
  console.log(`  @layer: ${reference.layers.join(', ')}`);
  console.log(`  Prose rules: ${proseRules(referenceCss).size} checked (selector and at-rule context)`);
}

if (comparePath !== null) {
  const candidateCss = candidatePath === null ? assemble() : readFileSync(candidatePath, 'utf8');
  const result = compare(readFileSync(comparePath, 'utf8'), candidateCss);
  problems.push(...result.problems);
  console.log(`Compare: ${comparePath}`);
  console.log(`  Candidate: ${candidatePath ?? `theme/css joined, palette ${palettePath ?? '(none)'}`}`);
  console.log(`  Rule lines: ${result.lines} expected`);
}

if (problems.length) {
  console.error(`\n${problems.length} difference(s):`);
  for (const p of problems) console.error('  ' + p);
  process.exit(1);
}
console.log('\n0 differences.');
