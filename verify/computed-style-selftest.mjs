#!/usr/bin/env node
// Selftest for computed-style.mjs: checks that the tool finds real style differences and
// ignores mere spellings.
//
//   node verify/computed-style-selftest.mjs
//
// It needs neither a reference app nor a reference export: a tiny HTTP server serves the
// same page in several variants, and the tool runs once against each.
//
//   0. colour maths    oklch/lab/hex against the values Chromium itself rasterises
//   1. same            identical page                                   → no difference
//   2. srgb            #3b82f6 against color(srgb 0.2314 0.5098 0.9647)   → no difference
//   3. colour          #3b82f6 against #ef4444                           → exactly background-color
//   4. shifted         margin-left 0 against 20px                        → margin-left and rectangle
//   5. tiny            padding-left 0 against 0.3px                      → no difference (< --tolerance-px)
//   6. structure       one extra <span>                                  → hard structure error
//   7. next runtime    nextjs-portal and next-route-announcer in the reference only → no difference
//
// Case 5 uses padding-left because the property itself isn't on the checked list: only the
// shift of the rectangle remains, and it stays below the tolerance.

import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';

import { normaliseColor, normaliseColorsInValue } from './lib/color.mjs';

const execFileAsync = promisify(execFile);
const verifyDir = path.dirname(fileURLToPath(import.meta.url));

function page(styleA, extra = '') {
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>t</title></head>
<body style="margin:0;font-family:serif">
<div id="a" style="${styleA}">First box</div>
<div id="b" style="color:#111827">Second box${extra}</div>
</body></html>`;
}

const BASE_STYLE = 'background-color:#3b82f6;color:#ffffff;display:block;width:200px';

const VARIANTS = {
  ref: page(BASE_STYLE),
  same: page(BASE_STYLE),
  srgb: page(BASE_STYLE.replace('#3b82f6', 'color(srgb 0.231372549 0.509803922 0.964705882)')),
  colour: page(BASE_STYLE.replace('#3b82f6', '#ef4444')),
  shifted: page(`${BASE_STYLE};margin-left:20px`),
  tiny: page(`${BASE_STYLE};padding-left:0.3px`),
  structure: page(BASE_STYLE, '<span>extra</span>'),
  // Reference with the runtime nodes of the Next development server at the end of <body>,
  // styled as in a Next app: the announcer is absolutely positioned and 1 × 1 px, the
  // portal is empty and keeps its content in a shadow root. Neither changes the layout.
  nextref: page(BASE_STYLE).replace('</body>', '<nextjs-portal></nextjs-portal><next-route-announcer style="position:absolute;width:1px;height:1px;overflow:hidden"><p aria-live="assertive" style="margin:0"></p></next-route-announcer></body>'),
};

const PROPS = {
  properties: ['display', 'background-color', 'color', 'margin-left', 'width', 'font-family'],
  customProperties: [],
  states: [],
};

// The conversion itself: the expected values don't come from this file but from Chromium,
// the same values painted into a <canvas> and read back. If the maths is off, the whole
// colour comparison is wrong.
const COLOR_CASES = [
  ['#3b82f6', 'rgba(59, 130, 246, 1)'],
  ['oklch(0.623 0.214 259.815)', 'rgba(43, 127, 255, 1)'],
  ['oklch(0.55 0.22 264)', 'rgba(43, 98, 239, 1)'],
  ['lab(50% 40 59.5)', 'rgba(191, 87, 0, 1)'],
  ['hsl(210 50% 40%)', 'rgba(51, 102, 153, 1)'],
  ['color(srgb 1 0 0)', 'rgba(255, 0, 0, 1)'],
  ['transparent', 'rgba(0, 0, 0, 0)'],
  ['rgba(0,0,0,.1)', 'rgba(0, 0, 0, 0.1)'],
];

function checkColours() {
  for (const [input, expected] of COLOR_CASES) {
    assert.equal(normaliseColor(input), expected, `colour conversion of ${input}`);
  }
  assert.equal(
    normaliseColorsInValue('oklch(0.55 0.22 264) 0px 1px 2px'),
    'rgba(43, 98, 239, 1) 0px 1px 2px',
    'colours inside composite values',
  );
  assert.equal(normaliseColorsInValue('url("/a/linen.png") no-repeat'), 'url("/a/linen.png") no-repeat', 'colour names inside paths stay');
  console.log(`✓ colour maths – ${COLOR_CASES.length} conversions as rasterised by Chromium`);
}

async function main() {
  checkColours();

  const tmp = await fs.mkdtemp(path.join(os.tmpdir(), 'computed-style-selftest-'));
  const treeFile = path.join(tmp, 'tree.json');
  const propsFile = path.join(tmp, 'props.json');
  await fs.writeFile(treeFile, JSON.stringify([{ page: '/p' }]));
  await fs.writeFile(propsFile, JSON.stringify(PROPS));

  // The server serves /<variant>/p as HTML; everything else is 404.
  const server = http.createServer((req, res) => {
    const m = req.url.match(/^\/([a-z]+)\/p\/?$/);
    if (!m || !VARIANTS[m[1]]) {
      res.writeHead(404).end('not found');
      return;
    }
    res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' }).end(VARIANTS[m[1]]);
  });
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const base = `http://127.0.0.1:${server.address().port}`;

  // The call has to be asynchronous: the HTTP server above lives in the same process, and
  // execFileSync would block the event loop so that it serves nothing.
  async function run(variant, refVariant = 'ref', extraArgs = []) {
    const reportDir = path.join(tmp, `report-${variant}-${refVariant}`);
    let output = '';
    let status = 0;
    try {
      const res = await execFileAsync(
        process.execPath,
        [
          path.join(verifyDir, 'computed-style.mjs'),
          '--reference', `${base}/${refVariant}`,
          '--candidate', `${base}/${variant}`,
          '--no-rewrite',
          '--pages', treeFile,
          '--only', '=/p',
          '--props', propsFile,
          '--widths', '1440',
          '--themes', 'light',
          '--settle', '60',
          '--report', reportDir,
          ...extraArgs,
        ],
        { encoding: 'utf8' },
      );
      output = res.stdout;
    } catch (err) {
      output = `${err.stdout ?? ''}${err.stderr ?? ''}`;
      status = err.code ?? 1;
    }
    return { status, output, reportDir };
  }

  async function report(reportDir) {
    return JSON.parse(await fs.readFile(path.join(reportDir, 'p', '1440-light.json'), 'utf8'));
  }

  try {
    // 0b. --pages is required: no default path.
    const noPages = await execFileAsync(process.execPath, [path.join(verifyDir, 'computed-style.mjs'), '--reference', base, '--candidate', base])
      .then(() => 0, (err) => err.code);
    assert.equal(noPages, 2, 'a missing --pages must be a usage error');
    console.log('✓ usage – --pages is required');

    // 1. identical
    let r = await run('same');
    let j = await report(r.reportDir);
    assert.equal(r.status, 0, `identical pages must be green:\n${r.output}`);
    assert.equal(j.diffs.length, 0, `identical pages without differences, got: ${JSON.stringify(j.diffs)}`);
    assert.ok(j.elements >= 3, `elements must be collected, got: ${j.elements}`);
    console.log(`✓ same – 0 differences across ${j.elements} elements`);

    // 2. the same colour, another spelling
    r = await run('srgb');
    j = await report(r.reportDir);
    assert.equal(j.diffs.length, 0, `color(srgb …) and hex of the same colour must not stand out: ${JSON.stringify(j.diffs)}`);
    assert.equal(r.status, 0);
    console.log('✓ srgb – the same colour in another spelling doesn\'t stand out');

    // 3. a real colour change
    r = await run('colour');
    j = await report(r.reportDir);
    assert.equal(r.status, 1, 'a real difference must exit with 1');
    assert.equal(j.diffs.length, 1, `exactly one difference expected: ${JSON.stringify(j.diffs, null, 2)}`);
    assert.equal(j.diffs[0].prop, 'background-color');
    assert.equal(j.diffs[0].reference, 'rgba(59, 130, 246, 1)');
    assert.equal(j.diffs[0].candidate, 'rgba(239, 68, 68, 1)');
    console.log('✓ colour – exactly background-color reported, normalised to rgba');

    // 4. shift: property and rectangle
    r = await run('shifted');
    j = await report(r.reportDir);
    assert.equal(r.status, 1);
    const props = j.diffs.filter((d) => d.prop === 'margin-left');
    const rects = j.diffs.filter((d) => d.prop === 'rect.x');
    assert.equal(props.length, 1, `margin-left must stand out: ${JSON.stringify(j.diffs)}`);
    assert.equal(props[0].candidate, '20px');
    assert.ok(rects.length >= 1, `the shift must also show up in the rectangle: ${JSON.stringify(j.diffs)}`);
    assert.equal(rects[0].delta, 20);
    console.log(`✓ shifted – margin-left and ${rects.length} rectangle difference(s)`);

    // 5. below the tolerance
    r = await run('tiny');
    j = await report(r.reportDir);
    assert.equal(j.diffs.length, 0, `0.3 px must not stand out with --tolerance-px 0.5: ${JSON.stringify(j.diffs)}`);
    assert.equal(r.status, 0);
    console.log('✓ tiny – 0.3 px stay below the tolerance');

    // 6. a structure shift is a hard error, not a flood of style differences
    r = await run('structure');
    j = await report(r.reportDir);
    assert.equal(r.status, 1);
    assert.ok(j.error && /Structure shift/.test(j.error), `structure shift expected, got: ${JSON.stringify(j.error)}`);
    assert.equal(j.diffs.length, 0, 'no style differences may be reported on a structure shift');
    assert.ok(j.structure, 'the report must name the location');
    console.log(`✓ structure – hard error instead of a flood: ${j.error}`);

    // 7. Next runtime nodes in the reference only
    r = await run('same', 'nextref');
    j = await report(r.reportDir);
    assert.equal(j.error, undefined, `runtime nodes must not cause a structure shift: ${j.error}`);
    assert.equal(j.diffs.length, 0, `no difference expected: ${JSON.stringify(j.diffs)}`);
    assert.equal(r.status, 0);
    console.log('✓ next runtime – nextjs-portal and next-route-announcer are dropped before pairing');
  } finally {
    server.close();
    await fs.rm(tmp, { recursive: true, force: true });
  }

  console.log('\nSelftest passed: real style and layout differences are reported; equal colours in another spelling and shifts below the tolerance are not; a structure shift aborts the page.');
}

main().catch((err) => {
  console.error(err.stack || err.message);
  process.exit(1);
});
