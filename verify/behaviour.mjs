#!/usr/bin/env node
// Behaviour and motion comparison: a Fumadocs reference app against a Pholio build.
//
//   node verify/behaviour.mjs --reference http://127.0.0.1:3000 --candidate http://127.0.0.1:4000 \
//        --scenarios verify/fixtures/demo/behaviour-scenarios-overlays.json --rewrites <file.json>
//   node verify/behaviour.mjs --reference <base> --candidate <base> --scenarios <file> --only search-open
//   node verify/behaviour.mjs … --report .build/behaviour            store traces as JSON
//   node verify/behaviour.mjs … --no-rewrite                         self comparison
//   node verify/behaviour.mjs … --rewrite /reference=/docs           one more path rule
//   node verify/behaviour.mjs … --repeat 3                           capture the reference three times
//
// --scenarios is required (repeatable; the scenarios of every file run in order).
//
// --repeat <n> (default 1): the reference is captured n times independently, the candidate
// once. A sample point of the candidate counts as equal when it matches at least one
// reference capture at that sample point completely (the whole sample point, no mix of
// variants). If it matches none, the differences to the nearest variant are shown.
// If the reference itself fluctuates, that is always printed: the scenario shows as `ok~`
// instead of `ok`, with the list of fluctuating sample points and the number of variants,
// and the closing line counts these scenarios separately. A flaky scenario is therefore
// visible, never silently green. The exit code stays 0 as long as the candidate matches a
// reference variant everywhere.
//
// Both sides get the same action sequence and are sampled at the same points. Compared are
// attributes, selected computed styles, running animations (getAnimations), focus and the
// scroll lock on <body>.
// Exit code 1 as soon as a scenario differs, 2 on usage errors.
//
// ---------------------------------------------------------------------------
// Scenario file format (JSON, either an array or { "scenarios": [...] })
// ---------------------------------------------------------------------------
//
// {
//   "name":  "search-open",               unique, also used by the --only filter
//   "page":  "/docs/guide",               reference path; the candidate path is rewritten
//   "width": 1440,                         default 1440
//   "height": 900,                         default 900
//   "theme": "light",                      "light" | "dark", default "light"
//   "actions": [ … ],
//   "observe": {
//     "selectors":  ["#fd-search-dialog-content", "button[data-search-full]"],
//     "attributes": ["data-open", "data-starting-style", "aria-expanded"],
//     "styleProps": ["opacity", "transform"],
//     "animations": true,                  getAnimations() per observed element
//     "focus": true,                       document.activeElement as a CSS path
//     "scrollLocked": true,                attributes and inline style of <body>
//     "html": true,                        class/style of <html> (theme)
//     "storage": ["theme"],                localStorage keys
//     "text": false,                       textContent of the observed elements
//     "icons": false                       lucide icon name per element (`lucide-link`),
//                                          independent of every other class
//   },
//
// Class lists differ between the reference (Tailwind utilities) and Pholio (nd- component
// classes). Scenarios for both sides therefore don't observe `class` but what the classes
// do: computed styles (styleProps), animations, state attributes and, with `icons`, the
// displayed icon.
//   "sample": ["frame0", "frame1", "animationend", "settled"]
// }
//
// Actions (in order; each may carry "sample": true):
//   { "click":    "css" }                  real mouse click
//   { "hover":    "css" }
//   { "press":    "Meta+k" }               Playwright key notation
//   { "type":     "archive", "selector": "css" }   optionally focus first
//   { "focus":    "css" }
//   { "wait":     250 }                    milliseconds
//   { "waitFor":  "css" }                  wait for a visible element
//   { "scroll":   "css" | null, "y": 400 }  scroll the window or an element
//   { "evaluate": "document.title = 'x'" }  in the page context, the return value is discarded
//
// If no action carries "sample", the last action is sampled.
// Sample points:
//   frame0        microtask right after the triggering event (before rendering)
//   frame1        one requestAnimationFrame later (Base UI removes data-starting-style here)
//   animationend  after the first animation of an observed element ends
//                 (or after 1500 ms, so scenarios without an animation don't hang)
//   settled       no running animations any more, then 100 ms of quiet
//
// Clipboard: every capture gets clipboard-read and clipboard-write for the page origin,
// identically on both sides. Without the permission headless Chromium rejects
// `navigator.clipboard.writeText` (NotAllowedError); copy buttons would never show their
// check mark, and copy scenarios would measure "nothing" equally on both sides instead of
// checking the behaviour.
//
// Hydration ids and the next/font class token on <html> are normalised in the page (see
// RECORDER), so they never show up as differences.
//
// Requires Playwright with Chromium (see lib/browser.mjs). Otherwise Node built-ins only.

import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { arg, argAll, argNumber, buildRewrites, rewritePath, joinUrl, readJson, resolveHome } from './lib/cli.mjs';
import { launchBrowser, openPage, loadPage } from './lib/browser.mjs';

// ---- Recorder in the page context -------------------------------------------
//
// Injected as source (page.addInitScript), so it is present again after a navigation
// inside a scenario. Everything lives under window.__nb.

const RECORDER = `(() => {
  if (window.__nb) return;
  const nb = {};
  window.__nb = nb;
  nb.cfg = null;
  nb.samples = [];

  // Neutralise hydration ids and random counters: golden-dom.mjs knows the same form
  // (_R_…_), here also the Base UI frame base-ui-<id>-<part>.
  const scrub = (s) => String(s)
    .replace(/_R_[0-9a-z]*_/g, '_R_')
    .replace(/(:r[0-9a-z]+:)/g, ':r:')
    .replace(/_r_[0-9a-z]*_/g, '_r_')
    .replace(/\\bbase-ui-[0-9a-z-]*?(?=-(popup|positioner|backdrop|trigger|viewport|title|description|portal)\\b)/g, 'base-ui-');

  // Round numbers in style values to 0.5 px, so subpixels don't add noise.
  const roundPx = (v) => String(v).replace(/-?\\d+\\.\\d+/g, (m) => {
    const n = Math.round(parseFloat(m) * 2) / 2;
    return String(n);
  });

  // Unique CSS path of an element (for focus).
  nb.pathOf = (el) => {
    if (!el || el === document.body) return 'body';
    if (!(el instanceof Element)) return String(el);
    const parts = [];
    let node = el;
    while (node && node.nodeType === 1 && node !== document.documentElement) {
      let part = node.tagName.toLowerCase();
      if (node.id) { part += '#' + scrub(node.id); parts.unshift(part); break; }
      const parent = node.parentElement;
      if (parent) {
        const same = [...parent.children].filter((c) => c.tagName === node.tagName);
        if (same.length > 1) part += ':nth-of-type(' + (same.indexOf(node) + 1) + ')';
      }
      parts.unshift(part);
      node = node.parentElement;
    }
    return parts.join('>');
  };

  // withTime only at frame0: later the start times of the two sides differ by one to three
  // frames (a development server renders more slowly), which would be pure noise. Name,
  // duration, easing, delay and playState stay.
  const animationsOf = (el, withTime) => el.getAnimations({ subtree: false }).map((a) => {
    const eff = a.effect ? a.effect.getTiming() : {};
    return {
      name: a.animationName || (a.transitionProperty ? 'transition:' + a.transitionProperty : (a.constructor && a.constructor.name) || '?'),
      duration: typeof eff.duration === 'number' ? Math.round(eff.duration) : String(eff.duration ?? ''),
      easing: eff.easing || '',
      delay: Math.round(eff.delay || 0),
      fill: eff.fill || '',
      playState: a.playState,
      // 16 ms buckets: the two sides never start in the same frame.
      ...(withTime ? { currentTime: a.currentTime == null ? null : Math.round(a.currentTime / 16) } : {}),
    };
  }).sort((x, y) => (x.name + x.duration).localeCompare(y.name + y.duration));

  const observeEl = (el, cfg, label) => {
    const out = { tag: el.tagName.toLowerCase() };
    for (const name of cfg.attributes || []) {
      const v = el.getAttribute(name);
      if (v !== null) out['@' + name] = scrub(v);
    }
    if (cfg.styleProps && cfg.styleProps.length) {
      const cs = getComputedStyle(el);
      for (const prop of cfg.styleProps) out['$' + prop] = roundPx(cs.getPropertyValue(prop).trim());
    }
    if (cfg.animations) out.animations = animationsOf(el, label === 'frame0');
    if (cfg.text) out.text = (el.textContent || '').replace(/\\s+/g, ' ').trim().slice(0, 400);
    if (cfg.icons) {
      // Only the icon name counts; size and colour classes don't.
      const svg = el.tagName.toLowerCase() === 'svg' ? el : el.querySelector('svg');
      const token = svg ? [...svg.classList].find((c) => c.startsWith('lucide-')) : null;
      out.icon = token || null;
    }
    return out;
  };

  nb.snap = (label) => {
    const cfg = nb.cfg || {};
    const entry = { label, elements: {} };
    for (const sel of cfg.selectors || []) {
      const nodes = [...document.querySelectorAll(sel)];
      entry.elements[sel] = nodes.map((el) => observeEl(el, cfg, label));
    }
    if (cfg.focus) entry.focus = nb.pathOf(document.activeElement);
    if (cfg.scrollLocked) {
      entry.body = {
        attributes: [...document.body.attributes]
          .filter((a) => a.name !== 'class')
          .map((a) => a.name + '=' + roundPx(scrub(a.value)))
          .sort(),
        overflow: getComputedStyle(document.body).overflow,
        paddingRight: roundPx(getComputedStyle(document.body).paddingRight),
      };
    }
    if (cfg.html) {
      const r = document.documentElement;
      entry.html = {
        // The class next/font attaches to <html> to apply the font
        // (inter_<hash>-module__<hash>__className) exists only in the reference; Pholio
        // loads the font through CSS. Only this token is dropped, the inline style stays
        // unchanged, because differences there are real differences.
        class: [...r.classList].filter((c) => !/-module__.*__className$/.test(c)).sort().join(' '),
        style: scrub(r.getAttribute('style') || ''),
        colorScheme: getComputedStyle(r).colorScheme,
      };
    }
    if (cfg.storage && cfg.storage.length) {
      entry.storage = {};
      for (const k of cfg.storage) {
        try { entry.storage[k] = localStorage.getItem(k); } catch { entry.storage[k] = '<blocked>'; }
      }
    }
    nb.samples.push(entry);
    return entry;
  };

  const rafp = () => new Promise((r) => requestAnimationFrame(() => r()));

  // Arm before the action: the event listener on the window runs after the page's
  // handlers, the microtask before that still in the same task, and that is "frame 0".
  nb.arm = (cfg, points) => {
    nb.cfg = cfg;
    nb.samples = [];
    nb.points = points;
    nb.finished = new Promise((resolve) => {
      let fired = false;
      const run = () => {
        if (fired) return;
        fired = true;
        queueMicrotask(async () => {
          if (points.includes('frame0')) nb.snap('frame0');
          if (points.includes('frame1')) { await rafp(); nb.snap('frame1'); }
          if (points.includes('animationend')) {
            await nb.waitAnimationEnd();
            nb.snap('animationend');
          }
          if (points.includes('settled')) {
            await nb.waitSettled();
            nb.snap('settled');
          }
          resolve(nb.samples);
        });
      };
      const types = ['click', 'keydown', 'pointermove', 'input', 'focusin', 'scroll'];
      const once = () => { types.forEach((t) => window.removeEventListener(t, once, false)); run(); };
      types.forEach((t) => window.addEventListener(t, once, false));
      // Actions without an event (evaluate, wait) are triggered by the caller via nb.fire().
      nb.fire = once;
    });
    return true;
  };

  nb.elements = () => {
    const cfg = nb.cfg || {};
    const out = [];
    for (const sel of cfg.selectors || []) out.push(...document.querySelectorAll(sel));
    return out;
  };

  nb.waitAnimationEnd = () => new Promise((resolve) => {
    const timer = setTimeout(done, 1500);
    function done() { clearTimeout(timer); document.removeEventListener('animationend', onEnd, true); resolve(); }
    // Only animations of the observed elements count; other animationend events (sidebar,
    // hover) would otherwise measure at different times on the two sides.
    function onEnd(event) { if (nb.elements().includes(event.target)) done(); }
    document.addEventListener('animationend', onEnd, true);
    // Wait for already running animations of the observed elements as well.
    const running = nb.elements().flatMap((el) => el.getAnimations()).filter((a) => a.playState === 'running');
    if (running.length) Promise.race(running.map((a) => a.finished.catch(() => {}))).then(done, done);
  });

  nb.waitSettled = async () => {
    // A few frames of run-up first: the other side sometimes starts its animation one or
    // two frames later and would otherwise wrongly count as finished.
    await rafp();
    await new Promise((r) => setTimeout(r, 80));
    for (let i = 0; i < 40; i++) {
      const running = document.getAnimations().filter((a) => a.playState === 'running');
      if (!running.length) break;
      await Promise.race([
        Promise.all(running.map((a) => a.finished.catch(() => {}))),
        new Promise((r) => setTimeout(r, 100)),
      ]);
    }
    await new Promise((r) => setTimeout(r, 100));
    // Wait for latecomers (animations that only start when others finish).
    for (let i = 0; i < 10; i++) {
      const running = document.getAnimations().filter((a) => a.playState === 'running');
      if (!running.length) break;
      await Promise.race([
        Promise.all(running.map((a) => a.finished.catch(() => {}))),
        new Promise((r) => setTimeout(r, 100)),
      ]);
    }
    await rafp();
  };

  nb.done = () => nb.finished;
})();`;

// ---- Running actions -------------------------------------------------------------

async function runAction(page, action) {
  if (action.click) await page.click(action.click, { timeout: 8000 });
  else if (action.hover) await page.hover(action.hover, { timeout: 8000 });
  else if (action.press) await page.keyboard.press(action.press);
  else if (action.focus) await page.focus(action.focus, { timeout: 8000 });
  else if (action.type !== undefined) {
    if (action.selector) await page.focus(action.selector, { timeout: 8000 });
    await page.keyboard.type(String(action.type), { delay: action.delay ?? 20 });
  } else if (action.waitFor) await page.waitForSelector(action.waitFor, { timeout: 8000 });
  else if (action.wait !== undefined) await page.waitForTimeout(Number(action.wait));
  else if (action.scroll !== undefined) {
    await page.evaluate(({ sel, y }) => {
      const el = sel ? document.querySelector(sel) : null;
      if (el) el.scrollTop = y;
      else window.scrollTo(0, y);
    }, { sel: typeof action.scroll === 'string' ? action.scroll : null, y: Number(action.y ?? 0) });
  } else if (action.evaluate) await page.evaluate(action.evaluate);
  else throw new Error(`Unknown action: ${JSON.stringify(action)}`);
}

// Run one scenario on one page and return every sample series.
async function trace(browser, base, scenario, rewrites, side) {
  const pagePath = side === 'candidate' ? rewritePath(scenario.page, rewrites) : scenario.page;
  const url = joinUrl(base, pagePath);
  const { context, page } = await openPage(browser, {
    width: scenario.width ?? 1440,
    height: scenario.height ?? 900,
    theme: scenario.theme ?? 'light',
  });
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: new URL(url).origin });
  await page.addInitScript(RECORDER);
  try {
    // A reference development server compiles a page on its first request, which can take
    // longer than the default. Retry once.
    page.setDefaultNavigationTimeout(60000);
    try {
      await loadPage(page, url, { theme: scenario.theme ?? 'light', settle: 400 });
    } catch (err) {
      if (!/Timeout/i.test(String(err && err.message))) throw err;
      await loadPage(page, url, { theme: scenario.theme ?? 'light', settle: 400 });
    }
    const observe = scenario.observe ?? {};
    const points = scenario.sample ?? ['settled'];
    const actions = scenario.actions ?? [];
    const marked = actions.some((a) => a.sample);
    const out = [];
    for (let i = 0; i < actions.length; i++) {
      const action = actions[i];
      const sampled = marked ? Boolean(action.sample) : i === actions.length - 1;
      if (!sampled) {
        await runAction(page, action);
        continue;
      }
      await page.evaluate(({ cfg, pts }) => window.__nb.arm(cfg, pts), { cfg: observe, pts: points });
      await runAction(page, action);
      // If the action didn't trigger an event (evaluate, wait), start now.
      await page.evaluate(() => { window.__nb.fire?.(); });
      const samples = await page.evaluate(() => window.__nb.done());
      out.push({ step: i, action: describe(action), samples });
    }
    return { url, steps: out, errors };
  } finally {
    await context.close();
  }
}

function describe(action) {
  const key = Object.keys(action).find((k) => k !== 'sample' && k !== 'selector' && k !== 'delay');
  return `${key}:${JSON.stringify(action[key])}`;
}

// ---- Comparison -----------------------------------------------------------------

function diffValue(pathStr, a, b, out) {
  if (JSON.stringify(a) === JSON.stringify(b)) return;
  if (a && b && typeof a === 'object' && typeof b === 'object' && !Array.isArray(a) && !Array.isArray(b)) {
    for (const key of new Set([...Object.keys(a), ...Object.keys(b)])) {
      diffValue(`${pathStr}.${key}`, a[key], b[key], out);
    }
    return;
  }
  if (Array.isArray(a) && Array.isArray(b) && a.length === b.length) {
    a.forEach((v, i) => diffValue(`${pathStr}[${i}]`, v, b[i], out));
    return;
  }
  out.push({ path: pathStr, reference: a, candidate: b });
}

// Sample points of one capture as a map `step/label → sample`.
function samplePoints(traceResult) {
  const points = new Map();
  traceResult.steps.forEach((step, i) => {
    for (const sample of step.samples) points.set(`${i} ${sample.label}`, { action: step.action, sample });
  });
  return points;
}

// Candidate against n reference captures. Returns the differences (empty = equal) and the
// sample points at which the reference itself had more than one variant.
function compareToReferences(refs, cand) {
  const diffs = [];
  const variance = [];
  const refPoints = refs.map(samplePoints);
  const candPoints = samplePoints(cand);

  const stepsRef = new Set(refs.map((r) => r.steps.length));
  if (stepsRef.size > 1 || !stepsRef.has(cand.steps.length)) {
    const counts = [...stepsRef].join('/');
    if (stepsRef.size > 1) variance.push({ point: 'steps', variants: stepsRef.size, detail: `reference: ${counts} sampled steps` });
    if (!stepsRef.has(cand.steps.length)) {
      diffs.push({ path: 'steps', reference: counts, candidate: cand.steps.length });
    }
  }

  const keys = new Set([...candPoints.keys(), ...refPoints.flatMap((m) => [...m.keys()])]);
  for (const key of keys) {
    const c = candPoints.get(key);
    const label = key.split(' ')[1];
    const action = c?.action ?? refPoints.find((m) => m.has(key))?.get(key).action ?? '?';
    const pointName = `${action}@${label}`;

    // Reference variants at this sample point (a missing sample point is a variant of its own).
    const variants = [];
    for (const m of refPoints) {
      const value = m.get(key)?.sample ?? null;
      const json = JSON.stringify(value);
      const found = variants.find((v) => v.json === json);
      if (found) found.runs += 1;
      else variants.push({ json, value, runs: 1 });
    }
    if (variants.length > 1) {
      variance.push({ point: pointName, variants: variants.length, runs: variants.map((v) => v.runs) });
    }

    const candJson = JSON.stringify(c?.sample ?? null);
    if (variants.some((v) => v.json === candJson)) continue;

    // No variant matched: show the differences to the nearest one.
    let best = null;
    for (const v of variants) {
      const out = [];
      diffValue(pointName, v.value, c?.sample ?? null, out);
      if (!best || out.length < best.length) best = out;
    }
    if (variants.length > 1) {
      best.forEach((d) => { d.note = `nearest of ${variants.length} reference variants`; });
    }
    diffs.push(...best);
  }
  return { diffs, variance };
}

// ---- Main run ------------------------------------------------------------------------

async function main() {
  const reference = arg('reference', null);
  const candidate = arg('candidate', null);
  const scenarioFiles = argAll('scenarios');
  const only = arg('only', null);
  const reportDir = arg('report', null);
  if (!reference || !candidate || !scenarioFiles.length) {
    console.error('behaviour.mjs: --reference <base URL>, --candidate <base URL> and --scenarios <file.json> are required.');
    process.exit(2);
  }
  let rewrites;
  try {
    rewrites = buildRewrites();
  } catch (err) {
    console.error(err.message);
    process.exit(2);
  }
  let scenarios = [];
  for (const file of scenarioFiles) {
    const raw = await readJson(resolveHome(file));
    scenarios.push(...(Array.isArray(raw) ? raw : (raw.scenarios ?? [])));
  }
  if (only) {
    const wanted = only.split(',').map((s) => s.trim()).filter(Boolean);
    scenarios = scenarios.filter((s) => wanted.some((w) => s.name === w || s.name.includes(w)));
    if (!scenarios.length) throw new Error(`--only ${only} matches no scenario.`);
  }

  const repeat = Math.max(1, Math.floor(argNumber('repeat', 1)));
  const browser = await launchBrowser();
  let failed = 0;
  let flaky = 0;
  const report = [];
  try {
    for (const scenario of scenarios) {
      let entry;
      try {
        // The reference n times, every capture in a fresh context. Single failed captures
        // count as fluctuation, all of them failing as an error.
        const refs = [];
        const refRunErrors = [];
        for (let i = 0; i < repeat; i++) {
          try {
            refs.push(await trace(browser, reference, scenario, rewrites, 'reference'));
          } catch (err) {
            refRunErrors.push(String(err && err.message ? err.message : err));
          }
        }
        if (!refs.length) throw new Error(`reference failed in all ${repeat} captures: ${refRunErrors[0]}`);
        const cand = await trace(browser, candidate, scenario, rewrites, 'candidate');
        const { diffs, variance } = compareToReferences(refs, cand);
        if (refRunErrors.length) {
          variance.unshift({ point: 'run', variants: 2, runs: [refs.length, refRunErrors.length], detail: refRunErrors[0] });
        }
        entry = {
          name: scenario.name,
          reference: refs[0].url,
          candidate: cand.url,
          repeat,
          diffs,
          variance,
          errors: { reference: [...new Set(refs.flatMap((r) => r.errors))], candidate: cand.errors },
        };
      } catch (err) {
        entry = { name: scenario.name, repeat, error: String(err && err.message ? err.message : err), variance: [], diffs: [{ path: 'run', reference: 'ok', candidate: 'error' }] };
      }
      report.push(entry);
      const bad = entry.diffs.length || entry.errors?.candidate?.length;
      const wobbly = entry.variance.length > 0;
      if (bad) {
        failed++;
        console.log(`FAIL   ${entry.name}${wobbly ? `  (reference fluctuates at ${entry.variance.length} sample points)` : ''}`);
        if (entry.error) console.log(`  ${entry.error}`);
        for (const d of entry.diffs.slice(0, 20)) {
          console.log(`  ${d.path}${d.note ? `  [${d.note}]` : ''}\n    ref : ${JSON.stringify(d.reference)}\n    cand: ${JSON.stringify(d.candidate)}`);
        }
        if (entry.diffs.length > 20) console.log(`  … ${entry.diffs.length - 20} more differences`);
        for (const e of entry.errors?.candidate ?? []) console.log(`  JS error (candidate): ${e}`);
      } else if (wobbly) {
        flaky++;
        console.log(`ok~    ${entry.name}  (reference fluctuates at ${entry.variance.length} sample points, the candidate matches one variant each)`);
      } else {
        console.log(`ok     ${entry.name}`);
      }
      if (wobbly) {
        for (const v of entry.variance.slice(0, 10)) {
          const runs = v.runs ? ` [${v.runs.join('+')} of ${repeat} captures]` : '';
          console.log(`  ~ ${v.point}: ${v.variants} variants${runs}${v.detail ? ` – ${v.detail.slice(0, 120)}` : ''}`);
        }
        if (entry.variance.length > 10) console.log(`  ~ … ${entry.variance.length - 10} more fluctuating sample points`);
      }
    }
  } finally {
    await browser.close();
  }

  if (reportDir) {
    const dir = resolveHome(reportDir);
    await fs.mkdir(dir, { recursive: true });
    await fs.writeFile(path.join(dir, 'behaviour.json'), `${JSON.stringify(report, null, 2)}\n`);
    console.log(`Report: ${path.join(dir, 'behaviour.json')}`);
  }
  const flakyNote = flaky ? `, ${flaky} of them only against a fluctuating reference (ok~)` : '';
  const repeatNote = repeat > 1 ? ` Reference captured ${repeat} times each.` : '';
  console.log(`${report.length - failed}/${report.length} scenarios equal${flakyNote}.${repeatNote}`);
  process.exit(failed ? 1 : 0);
}

main().catch((err) => {
  console.error(err);
  process.exit(2);
});
