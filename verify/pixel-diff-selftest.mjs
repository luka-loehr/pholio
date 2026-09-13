#!/usr/bin/env node
// Selftest for pixel-diff.mjs: checks the maths without a browser and the tool as a whole
// against a tiny HTTP server.
//
//   node verify/pixel-diff-selftest.mjs
//
//   1. PNG            writing and reading back yields the same pixels
//   2. maths          equal → 0; one pixel → 1; other dimensions → the overhang counts
//   3. tolerance      a small difference disappears with --pixel-threshold
//   4. rounding       ±1 per channel is counted and marked blue with --max-channel-delta 1, ±2 stays
//   5. edges          a pixel on an edge counts as antialiasing with --antialias,
//                     the same pixel inside a flat area doesn't
//   6. tool           identical pages → 0 differing pixels, exit code 0
//   7. tool           a red 10 × 10 box → 100 differing pixels, exit code 1
//   8. tool           a lazily loaded image far below the fold, served only after 1.5 s →
//                     fully painted in both captures
//   9. tool           a red badge in <nextjs-portal> in the candidate only → hidden, 0 pixels
//  10. tool           --pages is required

import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';

import { decodePng, encodePng, blankImage } from './lib/png.mjs';
import { diffImages, antialiased } from './lib/pixel.mjs';

const verifyDir = path.dirname(fileURLToPath(import.meta.url));
const execFileAsync = promisify(execFile);

// ---- 1. PNG ---------------------------------------------------------------

function checkPng() {
  const img = blankImage(37, 19, [10, 20, 30, 255]);
  for (let i = 0; i < img.data.length; i += 4) {
    img.data[i] = (i / 4) % 256;
    img.data[i + 3] = 255 - ((i / 4) % 200);
  }
  const back = decodePng(encodePng(img));
  assert.equal(back.width, img.width);
  assert.equal(back.height, img.height);
  assert.deepEqual([...back.data], [...img.data], 'the PNG round trip must be equal pixel by pixel');
  console.log('✓ png – writing and reading back yields the same pixels');
}

// ---- 2.–4. Maths --------------------------------------------------------------

function checkDiff() {
  const a = blankImage(20, 20, [255, 255, 255, 255]);
  const b = blankImage(20, 20, [255, 255, 255, 255]);
  assert.equal(diffImages(a, b).different, 0, 'equal images');

  const p = (5 * 20 + 5) * 4;
  b.data[p] = 0; b.data[p + 1] = 0; b.data[p + 2] = 0;
  const one = diffImages(a, b);
  assert.equal(one.different, 1, 'one differing pixel');
  assert.equal(one.diff.data[p], 255, 'differing pixels become red');
  assert.equal(one.diff.data[p + 1], 0);
  assert.equal(one.ratio, 1 / 400);

  const taller = blankImage(20, 25, [255, 255, 255, 255]);
  const size = diffImages(a, taller);
  assert.equal(size.sameSize, false);
  assert.equal(size.different, 100, 'the overhang counts as different in full');
  console.log('✓ maths – equal 0, one pixel 1, overhang on other dimensions');

  // Small colour difference: visible without tolerance, gone with it.
  const c = blankImage(20, 20, [255, 255, 255, 255]);
  const d = blankImage(20, 20, [255, 255, 255, 255]);
  d.data[p] = 250; d.data[p + 1] = 250; d.data[p + 2] = 250;
  assert.equal(diffImages(c, d).different, 1, 'without tolerance even a small difference stands out');
  assert.equal(diffImages(c, d, { pixelThreshold: 0.1 }).different, 0, 'with tolerance it no longer does');
  console.log('✓ tolerance – --pixel-threshold swallows exactly the small differences');

  // Rounding: ±1 per channel is counted with --max-channel-delta 1 but not treated as a
  // difference; ±2 stays a difference.
  const e = blankImage(20, 20, [200, 200, 200, 255]);
  const f = blankImage(20, 20, [200, 200, 200, 255]);
  f.data[p] = 201; f.data[p + 1] = 199;
  const q = (6 * 20 + 6) * 4;
  f.data[q] = 202;
  assert.equal(diffImages(e, f).different, 2, 'without rounding tolerance both pixels count');
  const rounded = diffImages(e, f, { maxChannelDelta: 1 });
  assert.equal(rounded.different, 1, 'with --max-channel-delta 1 only the ±2 pixel remains');
  assert.equal(rounded.rounding, 1, 'the ±1 pixel is counted as rounding');
  assert.deepEqual([rounded.diff.data[p], rounded.diff.data[p + 1], rounded.diff.data[p + 2]], [0, 80, 255], 'rounding is marked blue');
  console.log('✓ rounding – ±1 per channel is counted and marked blue, ±2 stays a difference');
}

// ---- 5. Edge detection ----------------------------------------------------------

// An image with a vertical edge: white on the left, column 2 grey, black on the right.
function edgeImage(width, height, mid) {
  const img = blankImage(width, height, [255, 255, 255, 255]);
  for (let y = 0; y < height; y += 1) {
    for (let x = 0; x < width; x += 1) {
      const o = (y * width + x) * 4;
      const v = x < 2 ? 255 : x === 2 ? mid : 0;
      img.data[o] = v; img.data[o + 1] = v; img.data[o + 2] = v;
    }
  }
  return img;
}

function checkAntialias() {
  const ref = edgeImage(5, 5, 128);
  const cand = edgeImage(5, 5, 128);
  const o = (2 * 5 + 2) * 4;
  cand.data[o] = 140; cand.data[o + 1] = 140; cand.data[o + 2] = 140;

  assert.equal(antialiased(ref.data, 2, 2, 5, 5, cand.data), true, 'a pixel on an edge must be recognised as antialiasing');
  assert.equal(diffImages(ref, cand).different, 1, 'without --antialias it counts as a difference');
  const aa = diffImages(ref, cand, { antialias: true });
  assert.equal(aa.different, 0, 'with --antialias it no longer does');
  assert.equal(aa.antialiased, 1);
  assert.equal(aa.diff.data[o], 255, 'pixels counted as antialiasing become yellow');
  assert.equal(aa.diff.data[o + 1], 255);

  // The same pixel inside a flat area is no edge.
  const flatRef = blankImage(5, 5, [128, 128, 128, 255]);
  const flatCand = blankImage(5, 5, [128, 128, 128, 255]);
  flatCand.data[o] = 140; flatCand.data[o + 1] = 140; flatCand.data[o + 2] = 140;
  assert.equal(antialiased(flatRef.data, 2, 2, 5, 5, flatCand.data), false, 'the inside of an area is no edge');
  assert.equal(diffImages(flatRef, flatCand, { antialias: true }).different, 1, 'and therefore stays a difference');
  console.log('✓ edges – edge pixels count as antialiasing, flat pixels don\'t');
}

// ---- 6.–10. The tool --------------------------------------------------------------

function page(extra = '') {
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>t</title>
<style>body{margin:0;background:#fff;height:400px}#m{position:absolute;left:20px;top:20px;width:10px;height:10px;background:#f00}</style>
</head><body><p style="font:16px serif;margin:60px 0 0 20px">A sentence to rasterise.</p>${extra}</body></html>`;
}

// A page with an image 2000 px below the fold: loading="lazy" and delayed by the server,
// exactly the case in which a screenshot can catch a loaded but not yet painted image.
const LATE_PAGE = `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>t</title>
<style>body{margin:0;background:#fff}img{display:block}</style></head>
<body><div style="height:2000px"></div><img src="/late.png" loading="lazy" width="100" height="100" alt=""></body></html>`;
const LATE_DELAY_MS = 1500;
const LATE_COLOR = [0, 160, 60, 255];

const VARIANTS = {
  ref: page(),
  same: page(),
  spot: page('<div id="m"></div>'),
  late: LATE_PAGE,
  latecand: LATE_PAGE,
  // Candidate with a red 40 × 40 badge in <nextjs-portal>, like the dev tools badge of a Next development server.
  portal: page('<nextjs-portal style="display:block;position:fixed;left:10px;top:100px;width:40px;height:40px;background:#f00"></nextjs-portal>'),
};

async function checkTool() {
  const tmp = await fs.mkdtemp(path.join(os.tmpdir(), 'pixel-diff-selftest-'));
  const treeFile = path.join(tmp, 'tree.json');
  await fs.writeFile(treeFile, JSON.stringify([{ page: '/p' }]));

  const latePng = encodePng(blankImage(100, 100, LATE_COLOR));
  const server = http.createServer((req, res) => {
    if (req.url.startsWith('/late.png')) {
      setTimeout(() => res.writeHead(200, { 'content-type': 'image/png', 'cache-control': 'no-store' }).end(latePng), LATE_DELAY_MS);
      return;
    }
    const m = req.url.match(/^\/([a-z]+)\/p\/?$/);
    if (!m || !VARIANTS[m[1]]) {
      res.writeHead(404).end('not found');
      return;
    }
    res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' }).end(VARIANTS[m[1]]);
  });
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const base = `http://127.0.0.1:${server.address().port}`;

  // Asynchronous, because the server runs in the same process.
  async function run(variant, refVariant = 'ref') {
    const out = path.join(tmp, `out-${variant}`);
    let status = 0;
    let output = '';
    try {
      const res = await execFileAsync(
        process.execPath,
        [
          path.join(verifyDir, 'pixel-diff.mjs'),
          '--reference', `${base}/${refVariant}`,
          '--candidate', `${base}/${variant}`,
          '--no-rewrite',
          '--pages', treeFile,
          '--only', '=/p',
          '--widths', '800',
          '--themes', 'light',
          '--settle', '60',
          '--out', out,
        ],
        { encoding: 'utf8' },
      );
      output = res.stdout;
    } catch (err) {
      output = `${err.stdout ?? ''}${err.stderr ?? ''}`;
      status = err.code ?? 1;
    }
    const result = JSON.parse(await fs.readFile(path.join(out, 'p', '800-light', 'result.json'), 'utf8'));
    return { status, output, out, result };
  }

  try {
    const noPages = await execFileAsync(process.execPath, [path.join(verifyDir, 'pixel-diff.mjs'), '--reference', base, '--candidate', base])
      .then(() => 0, (err) => err.code);
    assert.equal(noPages, 2, 'a missing --pages must be a usage error');
    console.log('✓ tool/usage – --pages is required');

    let r = await run('same');
    assert.equal(r.status, 0, `identical pages must be green:\n${r.output}`);
    assert.equal(r.result.different, 0, 'identical pages have no differing pixel');
    assert.ok(r.result.total > 100000, `the capture must cover the whole page: ${r.result.total}`);
    const index = await fs.readFile(path.join(r.out, 'index.html'), 'utf8');
    assert.ok(index.includes('Pixel comparison'), 'index.html must be written');
    assert.ok(index.includes('diff.png'), 'index.html must link the images');
    console.log(`✓ tool/same – 0 of ${r.result.total} pixels differ, exit code 0`);

    r = await run('spot');
    assert.equal(r.status, 1, 'a difference must exit with 1');
    assert.equal(r.result.different, 100, `a 10 × 10 box makes 100 pixels, got: ${r.result.different}`);
    const diff = decodePng(await fs.readFile(path.join(r.out, 'p', '800-light', 'diff.png')));
    const o = (25 * diff.width + 25) * 4;
    assert.deepEqual([diff.data[o], diff.data[o + 1], diff.data[o + 2]], [255, 0, 0], 'the box is red in the diff image');
    console.log('✓ tool/spot – exactly 100 differing pixels, red in the diff image, exit code 1');

    r = await run('latecand', 'late');
    assert.equal(r.status, 0, `the late image must not produce a difference:\n${r.output}`);
    assert.equal(r.result.different, 0);
    for (const side of ['reference', 'candidate']) {
      const shot = decodePng(await fs.readFile(path.join(r.out, 'p', '800-light', `${side}.png`)));
      assert.ok(shot.height >= 2100, `${side}: the capture must reach below the image, height ${shot.height}`);
      for (const [x, y] of [[5, 2005], [50, 2050], [95, 2095]]) {
        const p = (y * shot.width + x) * 4;
        assert.deepEqual(
          [shot.data[p], shot.data[p + 1], shot.data[p + 2]],
          LATE_COLOR.slice(0, 3),
          `${side}: the delayed image must be painted at (${x}, ${y})`,
        );
      }
    }
    console.log(`✓ tool/late – image 2000 px below the fold, delayed ${LATE_DELAY_MS} ms, painted in both captures`);

    r = await run('portal');
    assert.equal(r.status, 0, `a Next portal must not stand out:\n${r.output}`);
    assert.equal(r.result.different, 0, `the portal must be hidden, got: ${r.result.different}`);
    console.log('✓ tool/portal – the badge in <nextjs-portal> is hidden in both captures');
  } finally {
    server.close();
    await fs.rm(tmp, { recursive: true, force: true });
  }
}

async function main() {
  checkPng();
  checkDiff();
  checkAntialias();
  await checkTool();
  console.log('\nSelftest passed: PNG round trip, pixel maths, colour tolerance, rounding, edge detection and the tool from capture to report.');
}

main().catch((err) => {
  console.error(err.stack || err.message);
  process.exit(1);
});
