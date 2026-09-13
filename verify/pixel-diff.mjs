#!/usr/bin/env node
// Pixel comparison: a reference app against a Pholio build.
//
//   node verify/pixel-diff.mjs --reference http://127.0.0.1:3000 --candidate http://127.0.0.1:4000 \
//        --pages <export>/tree.json --rewrites <file.json>
//   node verify/pixel-diff.mjs --reference <url> --candidate <same url> --pages <tree> --no-rewrite   self comparison
//   node verify/pixel-diff.mjs --reference <ref> --candidate <cand> --pages <tree> \
//        --only guide/install --widths 1440,1024,390 --themes light,dark \
//        --states verify/fixtures/demo/pixel-states.json --out .build/pixel-diff
//   node verify/pixel-diff.mjs --reference <ref> --candidate <cand> --pages <tree> --antialias --threshold 20
//   node verify/pixel-diff.mjs --reference <ref> --candidate <cand> --pages <tree> --only '=/docs' --only-diff-images
//
// --pages is the reference export's tree.json or the export folder; pages outside the tree
// come from `extraPages` of the rewrites file or --extra-pages. --only takes substrings like
// golden-dom.mjs; a leading = means exactly this path. --only-diff-images writes the three
// PNG files only for comparisons that fail; the JSON files and index.html are written for
// all of them.
//
// Full-page screenshots are compared (fullPage, deviceScaleFactor 1, caret hidden). By
// default every differing channel value counts: --threshold is the number of differing
// pixels allowed per comparison (0), --pixel-threshold the colour distance allowed per
// pixel (0, same scale as pixelmatch). --antialias adds edge detection: a pixel counts as
// antialiasing when its 3×3 neighbourhood has both clearly darker and clearly brighter
// neighbours and that neighbour has many equal neighbours in both images.
//
// Animations are not disabled (`animations: 'disabled'` would also freeze the end states
// behaviour.mjs compares). Instead every finite animation runs to its end, and only
// infinite animations (such as `animate-pulse`) are set to time 0; otherwise even the
// reference against itself would be random.
//
// Before every capture all images are loaded, decoded and painted (loadAllImages in
// lib/browser.mjs), and the Next runtime nodes are hidden on both sides.
//
// The maths (colour distance, edge detection, diff image) lives in lib/pixel.mjs, so the
// selftest can check it without a browser.
//
// --max-channel-delta 1 treats pixels that differ by at most one value in every channel as
// rasteriser rounding instead of a difference. Chromium occasionally rasterises the same
// page that way in two contexts open at the same time; rounded corners, borders and glyph
// edges are affected, roughly 14 of 1.16 million pixels on a start page at 1024 px, in about
// every third run. They are still counted and marked blue, so that a real colour error in
// Pholio isn't hidden among them.
//
// Output per comparison: reference.png, candidate.png, diff.png (differences in red),
// result.json with the numbers, plus an index.html with every comparison side by side.
//
// Exit code 1 as soon as a comparison is above the threshold, 2 on usage errors.
// Requires Playwright with Chromium (see lib/browser.mjs).

import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

import { arg, flag, argList, argNumber, resolveHome, readJson, writeJson, buildRewrites, buildExtraPages } from './lib/cli.mjs';
import { loadPageList, filterPages, pageUrls } from './lib/pages.mjs';
import { decodePng, encodePng } from './lib/png.mjs';
import { diffImages } from './lib/pixel.mjs';
import { NEXT_RUNTIME_TAGS } from './lib/dom-walk.mjs';
import { launchBrowser, openPage, loadPage, settleAnimations, closeQuietly, loadAllImages } from './lib/browser.mjs';

// ---- cli ------------------------------------------------------------------

function usage(message) {
  console.error(message);
  process.exit(2);
}

const referenceBase = arg('reference', '');
const candidateBase = arg('candidate', '');
if (!referenceBase || !candidateBase) usage('Missing: --reference <base URL> and --candidate <base URL>');
const pagesSpec = arg('pages', '');
if (!pagesSpec) usage('Missing: --pages <tree.json or reference export folder>');

const onlyFilter = arg('only', '');
const widths = argList('widths', ['1440', '1024', '390']).map(Number);
const themes = argList('themes', ['light', 'dark']);
const statesFile = arg('states', '');
const outDir = resolveHome(arg('out', path.join(process.cwd(), '.build/pixel-diff')));
const maxPixels = argNumber('threshold', 0);
const pixelThreshold = argNumber('pixel-threshold', 0);
const antialias = flag('antialias');
const maxChannelDelta = argNumber('max-channel-delta', 0);
const onlyDiffImages = flag('only-diff-images');
const settleMs = argNumber('settle', 500);
const imageTimeout = argNumber('image-timeout', 30000);
const stableTries = argNumber('stable-tries', 8);
const stableGap = argNumber('stable-gap', 250);
const rewrites = buildRewrites();
const extraPages = buildExtraPages();

for (const w of widths) if (!Number.isFinite(w) || w <= 0) usage(`--widths contains no width: ${w}`);
for (const t of themes) if (t !== 'light' && t !== 'dark') usage(`--themes knows only light and dark, got: ${t}`);

// ---- Preparing the capture -------------------------------------------------------

// Everything that could look different between two loads of the same document is pinned
// down here; otherwise not even the reference against itself is reproducible.
// The dev tools badge of the Next development server (a round button at the bottom left of
// the first viewport, rendered in <nextjs-portal>) exists only in the reference and made
// every page red with around 3000 pixels. Before every capture, in scenarios too, the
// runtime nodes get visibility:hidden on both sides. That changes no layout: the portal is
// fixed, the announcer is invisible anyway. The style element has an id of its own and is
// inserted once.
async function hideNextRuntime(page) {
  await page.evaluate((tags) => {
    if (document.getElementById('verify-hide-next-runtime')) return;
    const style = document.createElement('style');
    style.id = 'verify-hide-next-runtime';
    style.textContent = `${tags.join(', ')} { visibility: hidden !important; }`;
    document.head.appendChild(style);
  }, NEXT_RUNTIME_TAGS);
}

async function prepareForShot(page, { keepScroll = false } = {}) {
  await hideNextRuntime(page);
  // Before the capture, walk the whole page once more until every image has been
  // rasterised in the viewport (see loadAllImages in lib/browser.mjs). Scenarios keep their
  // scroll position, everything else goes back to the top.
  if (!keepScroll) await page.evaluate(() => window.scrollTo(0, 0));
  const unloaded = await loadAllImages(page, { timeout: imageTimeout });
  // A clear error is better than a screenshot with half-loaded images.
  if (unloaded.length) {
    throw new Error(`${unloaded.length} image(s) not loaded, the capture would be random: ${unloaded.slice(0, 3).join(', ')}`);
  }
  await settleAnimations(page);
  // Pin infinite animations to time 0; finite ones have run to their end above.
  await page.evaluate(() => {
    for (const a of document.getAnimations()) {
      const iterations = a.effect && a.effect.getTiming ? a.effect.getTiming().iterations : 1;
      if (iterations === Infinity) {
        a.currentTime = 0;
        a.pause();
      }
    }
  });
  await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))));
}

// Wait until the page is calm, then capture the full page once.
//
// Loaded doesn't mean painted in a development server: on a start page the first section
// card sometimes settles on its final width a few hundred milliseconds after
// `networkidle`. So viewport images are taken until two consecutive ones are equal.
// Full-page images are no good for that: the capture stretches the viewport to the full
// height, which triggers IntersectionObserver (active table of contents entry) and sticky
// elements again, and two full-page captures in a row are never byte-identical on content
// pages, even though each one is reproducible by itself.
async function shootStable(page, { keepScroll = false, tries = 8, gap = 250 } = {}) {
  await prepareForShot(page, { keepScroll });
  let previous = null;
  let calm = false;
  for (let i = 0; i < tries; i += 1) {
    const buffer = await page.screenshot({ type: 'png', caret: 'hide' });
    if (previous && previous.equals(buffer)) {
      calm = true;
      break;
    }
    previous = buffer;
    await page.waitForTimeout(gap);
  }
  if (!calm) throw new Error(`the image isn't calm after ${tries} captures; the page is still painting`);
  return decodePng(await page.screenshot({ fullPage: true, type: 'png', caret: 'hide' }));
}

// ---- Scenarios -------------------------------------------------------------------

async function runActions(page, actions) {
  for (const action of actions ?? []) {
    if (action.wait !== undefined) await page.waitForTimeout(action.wait);
    else if (action.click) await page.locator(action.click).first().click({ timeout: 8000 });
    else if (action.hover) await page.locator(action.hover).first().hover({ timeout: 8000 });
    else if (action.type) await page.locator(action.type).first().fill(action.text ?? '', { timeout: 8000 });
    else if (action.press) await page.keyboard.press(action.press);
    else if (action.mouse) await page.mouse.move(action.mouse[0], action.mouse[1]);
    else if (action.waitFor) await page.locator(action.waitFor).first().waitFor({ state: 'visible', timeout: 10000 });
    else if (action.evaluate) await page.evaluate(new Function(action.evaluate));
    else throw new Error(`Unknown action: ${JSON.stringify(action)}`);
  }
}

// ---- Report ------------------------------------------------------------------------

function escapeHtml(s) {
  return String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
}

function indexHtml(rows, summary) {
  const body = rows
    .map((r) => {
      const images = r.images
        ? `<div class="imgs">
      <figure><figcaption>Reference</figcaption><img loading="lazy" src="${escapeHtml(r.dir)}/reference.png"></figure>
      <figure><figcaption>Candidate</figcaption><img loading="lazy" src="${escapeHtml(r.dir)}/candidate.png"></figure>
      <figure><figcaption>Difference</figcaption><img loading="lazy" src="${escapeHtml(r.dir)}/diff.png"></figure>
    </div>`
        : '<p class="note">No images written (--only-diff-images).</p>';
      return `<section class="${r.ok ? 'ok' : 'bad'}">
    <h2>${escapeHtml(r.title)}</h2>
    <p>${r.ok ? '✓ equal' : '✗ different'} — ${r.different} of ${r.total} pixels (${(r.ratio * 100).toFixed(4)} %)${r.antialiased ? `, ${r.antialiased} counted as antialiasing` : ''}${r.rounding ? `, ${r.rounding} counted as rounding` : ''}${r.sameSize ? '' : ' — <strong>different image sizes</strong>'}${r.error ? ` — <strong>${escapeHtml(r.error)}</strong>` : ''}</p>
    ${images}
  </section>`;
    })
    .join('\n');
  return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Pixel comparison</title>
<style>
 body { font: 14px/1.5 system-ui, sans-serif; margin: 0; padding: 2rem; background: #fafafa; color: #111; }
 h1 { font-size: 1.4rem; }
 section { border: 1px solid #ddd; border-radius: 10px; padding: 1rem; margin: 1rem 0; background: #fff; }
 section.bad { border-color: #d33; }
 section.ok > .imgs { display: none; }
 section.ok::after { content: ''; }
 h2 { font-size: 1rem; margin: 0 0 .25rem; font-family: ui-monospace, monospace; }
 .imgs { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
 figure { margin: 0; }
 figcaption { font-size: .8rem; color: #666; margin-bottom: .25rem; }
 img { width: 100%; border: 1px solid #eee; background: #fff; }
 .note { color: #888; }
 .summary { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 1rem; }
</style></head><body>
<h1>Pixel comparison</h1>
<div class="summary">${escapeHtml(summary)}</div>
${body}
</body></html>
`;
}

// ---- Main run ------------------------------------------------------------------------

async function main() {
  const statesDoc = statesFile ? await readJson(resolveHome(statesFile)) : { states: [] };
  const allStates = statesDoc.states ?? [];

  const allPages = await loadPageList(pagesSpec, extraPages);
  const pages = filterPages(allPages, onlyFilter);

  let browser = await launchBrowser();
  await fs.mkdir(outDir, { recursive: true });

  const started = Date.now();
  const rows = [];
  let red = 0;
  let green = 0;

  // One comparison: load both pages, optionally run a scenario, capture, diff.
  async function compareOne({ urls, width, theme, state }) {
    const name = state ? `${width}-${theme}-${state.name}` : `${width}-${theme}`;
    const dir = path.join(urls.slug, name);
    const absDir = path.join(outDir, dir);
    const title = `${urls.path} @${width}/${theme}${state ? ` · ${state.name}` : ''}`;
    const row = { title, dir: dir.split(path.sep).join('/'), ok: false, different: 0, total: 0, ratio: 0, antialiased: 0, rounding: 0, sameSize: true, images: false, error: null };

    // One page after the other, and the context is closed before the next one opens. Two
    // contexts open at the same time occasionally rasterise the same page differently,
    // sometimes by one value of 255 at edges, sometimes by a whole pixel for composited
    // layers. Computed styles and rectangles are provably equal meanwhile (the computed
    // style stage is green on the same pages), so it is pure rasteriser noise. Measured one
    // after the other, it doesn't occur.
    async function capture(url) {
      // If Chromium crashed in between, a new one is started instead of every following
      // comparison failing with "browser has been closed".
      if (!browser.isConnected()) browser = await launchBrowser();
      const { context, page } = await openPage(browser, { width, theme });
      try {
        await loadPage(page, url, { theme, settle: settleMs });
        // Before the actions: otherwise an open dialog locks scrolling and the images below
        // the fold never get a source.
        await loadAllImages(page, { timeout: imageTimeout });
        if (state) {
          await runActions(page, state.actions);
          // After a click the mouse stays where it clicked, and often another element lies
          // under it afterwards whose hover transition sometimes applies and sometimes
          // doesn't. `park` in the scenario moves the mouse to a neutral point so nothing is
          // hovered. Scenarios that hover on purpose leave it out.
          if (Array.isArray(state.park)) await page.mouse.move(state.park[0], state.park[1]);
          await page.waitForTimeout(state.settle ?? 300);
        }
        return await shootStable(page, { keepScroll: Boolean(state), tries: stableTries, gap: stableGap });
      } finally {
        await closeQuietly(context);
      }
    }

    try {
      const refImg = await capture(urls.reference);
      const candImg = await capture(urls.candidate);
      const result = diffImages(refImg, candImg, { pixelThreshold, antialias, maxChannelDelta });

      row.different = result.different;
      row.antialiased = result.antialiased;
      row.rounding = result.rounding;
      row.total = result.total;
      row.ratio = result.ratio;
      row.sameSize = result.sameSize;
      row.ok = result.different <= maxPixels;

      if (!row.ok || !onlyDiffImages) {
        await fs.mkdir(absDir, { recursive: true });
        await fs.writeFile(path.join(absDir, 'reference.png'), encodePng(refImg));
        await fs.writeFile(path.join(absDir, 'candidate.png'), encodePng(candImg));
        await fs.writeFile(path.join(absDir, 'diff.png'), encodePng(result.diff));
        row.images = true;
      }
      await writeJson(path.join(absDir, 'result.json'), {
        page: urls.path, width, theme, state: state?.name ?? null,
        reference: urls.reference, candidate: urls.candidate,
        referenceSize: [refImg.width, refImg.height], candidateSize: [candImg.width, candImg.height],
        different: result.different, antialiased: result.antialiased, rounding: result.rounding,
        total: result.total, ratio: result.ratio, threshold: maxPixels, pixelThreshold, maxChannelDelta, ok: row.ok,
      });
    } catch (err) {
      row.error = err.message.split('\n')[0];
      row.ok = false;
      await writeJson(path.join(absDir, 'result.json'), { page: urls.path, width, theme, state: state?.name ?? null, error: row.error });
    }

    rows.push(row);
    if (row.ok) {
      green += 1;
      console.log(`✓ ${title} – ${row.total} pixels equal${row.rounding ? `, ${row.rounding} of them only rounded` : ''}`);
    } else {
      red += 1;
      console.log(`✗ ${title} – ${row.error ?? `${row.different} differing pixels (${(row.ratio * 100).toFixed(4)} %)${row.sameSize ? '' : ', different image sizes'}`}`);
    }
  }

  try {
    for (const refPath of pages) {
      const urls = pageUrls(refPath, { referenceBase, candidateBase, rewrites });
      for (const width of widths) {
        for (const theme of themes) {
          await compareOne({ urls, width, theme });
          for (const state of allStates) {
            if (state.pages && !new RegExp(state.pages).test(urls.path)) continue;
            if (state.widths && !state.widths.includes(width)) continue;
            if (state.themes && !state.themes.includes(theme)) continue;
            await compareOne({ urls, width, theme, state });
          }
        }
      }
    }
  } finally {
    await browser.close();
  }

  const seconds = Math.round((Date.now() - started) / 100) / 10;
  const summary = `${green} green, ${red} red, ${green + red} comparisons. Threshold: ${maxPixels} pixels per comparison, colour tolerance ${pixelThreshold}${maxChannelDelta ? `, channel rounding ${maxChannelDelta}` : ''}${antialias ? ', antialiasing detection on' : ''}. Time ${seconds}s.`;
  await fs.writeFile(path.join(outDir, 'index.html'), indexHtml(rows, summary));
  console.log(`\nResult: ${summary} Report: ${path.join(outDir, 'index.html')}`);
  process.exitCode = red ? 1 : 0;
}

main().catch((err) => {
  console.error(err.stack || err.message);
  process.exit(2);
});
