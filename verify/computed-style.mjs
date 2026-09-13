#!/usr/bin/env node
// Computed style comparison: a reference app against a Pholio build.
//
//   node verify/computed-style.mjs --reference http://127.0.0.1:3000 --candidate http://127.0.0.1:4000 \
//        --pages <export>/tree.json --rewrites <file.json>
//   node verify/computed-style.mjs --reference <url> --candidate <same url> --pages <tree> --no-rewrite   self comparison
//   node verify/computed-style.mjs --reference <ref> --candidate <cand> --pages <tree> \
//        --only guide/install --widths 1440,1024,390 --themes light,dark --states
//   node verify/computed-style.mjs --reference <ref> --candidate <cand> --pages <export> \
//        --props verify/computed-style-props.json --report .build/computed-style
//   node verify/computed-style.mjs --reference <ref> --candidate <cand> --pages <tree> --only '=/docs' --tolerance-px 0.5
//
// --pages is the reference export's tree.json or the export folder containing it; pages
// outside the tree come from `extraPages` of the rewrites file or --extra-pages.
// --only takes substrings like golden-dom.mjs; a leading = means exactly this path
// (`--only /docs` would match every page below /docs as a substring). --tolerance-px
// (default 0.5) only applies to the rectangle comparison, property values are checked for
// equality. --settle and --state-settle change the waiting times (500 ms after loading,
// 200 ms after a state action), --with-head includes <head> in the comparison.
//
// What is compared is neither the source nor the image but what the browser computed: for
// every element of both pages the properties from computed-style-props.json, the --fd- and
// --color-fd- variables and the rectangle from getBoundingClientRect(). Both sides are
// normalised identically (the same dropped elements as in golden-dom.mjs, including the
// Next runtime nodes) and then aligned element by element by index.
//
// With --states every entry of `states` is compared as well: `actions` hovers or focuses
// the element matched by `selector`; `steps` instead runs a sequence in the format of
// pixel-states.json ({click|hover: selector}, {press: key}, {mouse: [x, y]}, {wait: ms},
// {waitFor: selector}) for states that change the page, such as a collapsed sidebar, and
// loads the page again afterwards. `widths` limits a state to the listed widths.
//
// Structure itself is the job of golden-dom.mjs. If it differs, this tool aborts the page
// with a hard error instead of reporting a thousand follow-up differences.
//
// Colours are converted to 8-bit sRGB before comparing (lib/color.mjs), because Chromium
// returns computed colours in the colour space they were written in:
// `oklch(0.623 0.214 259.815)` and `#3b82f6` are the same colour but not the same string.
//
// Exit code 1 as soon as any difference remains, 2 on usage errors.
// Requires Playwright with Chromium (see lib/browser.mjs).

import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import { arg, flag, argList, argNumber, resolveHome, readJson, writeJson, buildRewrites, buildExtraPages } from './lib/cli.mjs';
import { loadPageList, filterPages, pageUrls } from './lib/pages.mjs';
import { COLLECT_FN, collectOptions, firstStructureMismatch } from './lib/dom-walk.mjs';
import { normaliseColorsInValue } from './lib/color.mjs';
import { launchBrowser, openPage, loadPage, settleAnimations, closeQuietly } from './lib/browser.mjs';

const verifyDir = path.dirname(fileURLToPath(import.meta.url));

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
const withStates = flag('states');
const propsFile = resolveHome(arg('props', path.join(verifyDir, 'computed-style-props.json')));
const reportDir = resolveHome(arg('report', path.join(process.cwd(), '.build/computed-style')));
const tolerancePx = argNumber('tolerance-px', 0.5);
const settleMs = argNumber('settle', 500);
const stateSettleMs = argNumber('state-settle', 200);
const withHead = flag('with-head');
const dropStyleTags = flag('drop-style-tags');
const scopeSelector = arg('scope', '');
const maxPrint = argNumber('max-print', 10);
const rewrites = buildRewrites();
const extraPages = buildExtraPages();

for (const w of widths) if (!Number.isFinite(w) || w <= 0) usage(`--widths contains no width: ${w}`);
for (const t of themes) if (t !== 'light' && t !== 'dark') usage(`--themes knows only light and dark, got: ${t}`);

// ---- Normalising values ----------------------------------------------------------

// Colours into the canonical rgba form, whitespace unified. Nothing else: every real
// difference is meant to stay visible.
function normValue(value) {
  if (typeof value !== 'string') return value;
  return normaliseColorsInValue(value).replace(/\s+/g, ' ').trim();
}

const round2 = (n) => Math.round(n * 100) / 100;

// ---- Collecting one page -----------------------------------------------------------

async function collect(page, options) {
  const result = await page.evaluate(new Function(`return ${COLLECT_FN}`)(), options);
  if (result.missingScope) throw new Error(`--scope "${options.scope}" matches no node`);
  return result;
}

// Compare two captures. Returns the list of differences or throws on a structure shift.
function compare(ref, cand, { properties, customProperties }) {
  const mismatch = firstStructureMismatch(ref.tags, cand.tags, ref.paths, cand.paths);
  if (mismatch) {
    const err = new Error(
      `Structure shift at element ${mismatch.index} (${mismatch.kind}): reference ${mismatch.reference ?? '—'}, candidate ${mismatch.candidate ?? '—'}`,
    );
    err.structure = mismatch;
    throw err;
  }

  const names = [...properties, ...customProperties];
  const base = names.length;
  const rectNames = ['rect.x', 'rect.y', 'rect.width', 'rect.height'];
  const diffs = [];

  for (let i = 0; i < ref.rows.length; i += 1) {
    const a = ref.rows[i];
    const b = cand.rows[i];
    for (let j = 0; j < names.length; j += 1) {
      const r = normValue(a[j]);
      const c = normValue(b[j]);
      if (r === c) continue;
      diffs.push({ element: i, path: ref.paths[i], prop: names[j], reference: r, candidate: c });
    }
    for (let j = 0; j < 4; j += 1) {
      const r = a[base + j];
      const c = b[base + j];
      if (Math.abs(r - c) <= tolerancePx) continue;
      diffs.push({
        element: i,
        path: ref.paths[i],
        prop: rectNames[j],
        reference: round2(r),
        candidate: round2(c),
        delta: round2(c - r),
      });
    }
  }
  return diffs;
}

// ---- States ------------------------------------------------------------------------

// A state is produced identically on both sides: the first element matching the selector
// is hovered or focused, 200 ms of waiting (transitions take ≤ 150 ms), then the same
// subtree is collected. Afterwards the page is reset so the next state starts clean.
// The hit element gets a marker so the same node is found again as the root of the subtree.
// The selector alone wouldn't do: it may contain `:visible` (the header renders several
// controls twice, one for wide and one for narrow views, and the invisible one comes first
// in the document), and only Playwright understands `:visible`, not querySelector. No rule
// of the theme targets the attribute `data-verify-state-target`, so it changes nothing
// about the appearance.
const STATE_MARKER = 'data-verify-state-target';

// Steps of a state with `steps` instead of hover or focus, in the format of pixel-states.json.
async function runSteps(page, steps) {
  for (const step of steps) {
    if (step.wait !== undefined) await page.waitForTimeout(step.wait);
    else if (step.click) await page.locator(step.click).first().click({ timeout: 8000 });
    else if (step.hover) await page.locator(step.hover).first().hover({ timeout: 8000 });
    else if (step.press) await page.keyboard.press(step.press);
    else if (step.mouse) await page.mouse.move(step.mouse[0], step.mouse[1]);
    else if (step.waitFor) await page.locator(step.waitFor).first().waitFor({ state: 'visible', timeout: 10000 });
    else throw new Error(`unknown step ${JSON.stringify(step)}`);
  }
}

async function applyState(page, state, action) {
  if ((await page.locator(state.selector).count()) === 0) return false;
  const locator = page.locator(state.selector).first();
  await page.evaluate((marker) => {
    for (const el of document.querySelectorAll(`[${marker}]`)) el.removeAttribute(marker);
  }, STATE_MARKER);
  await locator.evaluate((el, marker) => el.setAttribute(marker, ''), STATE_MARKER);
  if (action === 'steps') {
    const theme = await page.evaluate(() => (document.documentElement.classList.contains('dark') ? 'dark' : 'light'));
    page.__stepsReset = { url: page.url(), theme };
    await runSteps(page, state.steps);
  } else if (action === 'hover') {
    await locator.hover({ timeout: 5000 });
  } else {
    await locator.evaluate((el) => el.focus({ preventScroll: false }));
  }
  await page.waitForTimeout(stateSettleMs);
  await settleAnimations(page);
  return true;
}

async function resetState(page) {
  // A state with steps changed the page itself (a click); only loading it again undoes that.
  if (page.__stepsReset) {
    const { url, theme } = page.__stepsReset;
    delete page.__stepsReset;
    await loadPage(page, url, { theme, settle: settleMs });
    await settleAnimations(page);
    return;
  }
  await page.mouse.move(0, 0);
  await page.evaluate((marker) => {
    if (document.activeElement && document.activeElement !== document.body) document.activeElement.blur();
    for (const el of document.querySelectorAll(`[${marker}]`)) el.removeAttribute(marker);
  }, STATE_MARKER);
  await page.waitForTimeout(stateSettleMs);
}

// ---- Main run ------------------------------------------------------------------------

function shortValue(value, max = 110) {
  if (value === null || value === undefined) return '—';
  const s = String(value);
  return s.length > max ? `${s.slice(0, max)}…` : s;
}

async function main() {
  const propsDoc = await readJson(propsFile);
  const properties = propsDoc.properties ?? [];
  const customProperties = propsDoc.customProperties ?? [];
  const states = withStates ? (propsDoc.states ?? []) : [];
  if (!properties.length) throw new Error(`${propsFile} contains no properties.`);

  const allPages = await loadPageList(pagesSpec, extraPages);
  const pages = filterPages(allPages, onlyFilter);
  const options = collectOptions({ properties, customProperties, withHead, dropStyleTags, scope: scopeSelector });

  let browser = await launchBrowser();
  await fs.mkdir(reportDir, { recursive: true });

  const started = Date.now();
  let red = 0;
  let green = 0;
  let skippedStates = 0;
  let totalDiffs = 0;

  try {
    for (const refPath of pages) {
      const urls = pageUrls(refPath, { referenceBase, candidateBase, rewrites });
      for (const width of widths) {
        for (const theme of themes) {
          const tag = `${urls.path} @${width}/${theme}`;
          const record = { page: urls.path, width, theme, reference: urls.reference, candidate: urls.candidate, diffs: [], states: [] };

          // If Chromium crashed in between (memory, shared machine), a new one is started;
          // otherwise every following page would fail with "browser has been closed".
          if (!browser.isConnected()) browser = await launchBrowser();
          let ref = null;
          let cand = null;
          try {
            ref = await openPage(browser, { width, theme });
            cand = await openPage(browser, { width, theme });
            await loadPage(ref.page, urls.reference, { theme, settle: settleMs });
            await loadPage(cand.page, urls.candidate, { theme, settle: settleMs });
            await settleAnimations(ref.page);
            await settleAnimations(cand.page);

            const refShot = await collect(ref.page, options);
            const candShot = await collect(cand.page, options);
            record.elements = refShot.rows.length;
            record.diffs = compare(refShot, candShot, { properties, customProperties });

            for (const state of states) {
              if (state.pages && !new RegExp(state.pages).test(urls.path)) continue;
              if (state.widths && !state.widths.includes(width)) continue;
              for (const action of state.steps ? ['steps'] : (state.actions ?? ['hover'])) {
                const name = `${state.name}:${action}`;
                let okRef;
                let okCand;
                try {
                  okRef = await applyState(ref.page, state, action);
                  okCand = await applyState(cand.page, state, action);
                } catch (err) {
                  // The selector is there but the action still fails: covered, outside the
                  // viewport, not operable. That is a finding, not a reason to skip.
                  record.states.push({ name, error: `action failed: ${err.message.split('\n')[0]}` });
                  await resetState(ref.page);
                  await resetState(cand.page);
                  continue;
                }
                if (!okRef || !okCand) {
                  // If the selector is missing on both sides, the page simply doesn't have
                  // this component (no table of contents on an overview, no table in every
                  // chapter), and the state is skipped. If it is missing on one side only,
                  // that is a real difference and must be red.
                  if (!okRef && !okCand) {
                    record.states.push({ name, skipped: 'selector missing on both sides' });
                    skippedStates += 1;
                  } else {
                    record.states.push({ name, error: okRef ? 'selector missing in the candidate' : 'selector missing in the reference' });
                  }
                  await resetState(ref.page);
                  await resetState(cand.page);
                  continue;
                }
                const stateOptions = { ...options, scope: `[${STATE_MARKER}]` };
                try {
                  const a = await collect(ref.page, stateOptions);
                  const b = await collect(cand.page, stateOptions);
                  const diffs = compare(a, b, { properties, customProperties });
                  record.states.push({ name, elements: a.rows.length, diffs });
                } catch (err) {
                  record.states.push({ name, error: err.message, structure: err.structure ?? null });
                }
                await resetState(ref.page);
                await resetState(cand.page);
              }
            }
          } catch (err) {
            record.error = err.message;
            if (err.structure) record.structure = err.structure;
          } finally {
            if (ref) await closeQuietly(ref.context);
            if (cand) await closeQuietly(cand.context);
          }

          const stateDiffs = record.states.flatMap((s) => s.diffs ?? []);
          const stateErrors = record.states.filter((s) => s.error);
          const count = record.diffs.length + stateDiffs.length;
          totalDiffs += count;
          record.diffCount = count;

          const file = path.join(reportDir, urls.slug, `${width}-${theme}.json`);
          await writeJson(file, record);

          if (record.error) {
            red += 1;
            console.log(`✗ ${tag} – ${record.error}`);
          } else if (count || stateErrors.length) {
            red += 1;
            console.log(`✗ ${tag} – ${count} difference(s)${stateErrors.length ? `, ${stateErrors.length} state error(s)` : ''}`);
            for (const d of [...record.diffs, ...stateDiffs].slice(0, maxPrint)) {
              console.log(`    ${d.prop}  ${d.path}`);
              console.log(`      reference: ${shortValue(d.reference)}`);
              console.log(`      candidate: ${shortValue(d.candidate)}`);
            }
            for (const s of stateErrors) console.log(`    state ${s.name}: ${s.error}`);
            if (count > maxPrint) console.log(`    … ${count - maxPrint} more, see ${file}`);
          } else {
            green += 1;
            console.log(`✓ ${tag} – ${record.elements} elements${record.states.length ? `, ${record.states.length} states` : ''}`);
          }
        }
      }
    }
  } finally {
    await browser.close();
  }

  const seconds = Math.round((Date.now() - started) / 100) / 10;
  console.log(
    `\nResult: ${green} green, ${red} red, ${green + red} comparisons, ${totalDiffs} differences` +
      `${skippedStates ? `, ${skippedStates} states skipped` : ''}. Time ${seconds}s. Reports: ${reportDir}`,
  );
  process.exitCode = red ? 1 : 0;
}

main().catch((err) => {
  console.error(err.stack || err.message);
  process.exit(2);
});
