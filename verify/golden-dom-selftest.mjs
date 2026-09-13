#!/usr/bin/env node
// Selftest for golden-dom.mjs: checks that the tool finds real differences and ignores
// mere normalisation differences.
//
//   node verify/golden-dom-selftest.mjs
//
// It needs no reference export: every page is generated into a temporary directory, shaped
// like a hydrated Fumadocs page (hydration ids, a viewport meta, a navigation, a drawer at
// the narrow width). The tool then runs against these pages in a separate process.
//
// 1. A reference page is copied and changed in four ways:
//      a. attribute changed      (lang="en" → lang="fr" on <html>)   must be reported
//      b. node removed           (the "Changelog" link in the navigation) must be reported
//      c. hydration ids swapped  (base-ui-_R_…_ → other random values) must NOT be reported
//      d. attribute order turned (<meta name content> → <meta content name>) must NOT be reported
//    The report must contain exactly the first two changes and nothing else.
//
// 2. Width case (--dom, --candidate-dom, --viewport) and "kind" in the allow list: the
//    drawer aside#nd-sidebar-mobile exists only in dom-390.html, not in dom.html, and
//    carries a hydration id of its own. The candidate lacks it at 390 px and has it
//    additionally at 1440 px. With the same allow file ("kind": "extra"), 390 px must be red
//    and 1440 px green. Counter checks: "kind": "missing" makes 1440 px red, an entry
//    without "kind" allows both directions (390 px green), a #node entry doesn't waive a
//    text difference, and an invalid "kind" aborts with exit code 2. The allow entry is
//    passed as a second --allow after an empty file, so --allow is proven repeatable.
//
// 3. Hydration ids with uppercase letters (for example `base-ui-_R_…H1_` on catalogue
//    components): a small page with a trigger (aria-controls) and a panel (id). Both sides
//    carry other random values with uppercase letters; that must be green. If aria-controls
//    points at the wrong id in the candidate, exactly that one difference must be reported.
//
// 4. Rewrites from a --rewrites file through --rewrite-probe, including rules that end in /.
//
// The runs use --ignore-classes: structure, attributes and ids are checked. Class mapping
// is proven by the real run against a Pholio build, not by this selftest.

import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const verifyDir = path.dirname(fileURLToPath(import.meta.url));
const tool = path.join(verifyDir, 'golden-dom.mjs');
const PAGE = 'docs/guide';

// A hydrated page. Markup without whitespace between tags keeps the text nodes out of
// the way.
function referencePage({ drawer = false, linkText = 'Changelog' } = {}) {
  const aside = drawer
    ? '<aside id="nd-sidebar-mobile" data-state="closed"><div id="base-ui-_R_d4k_" data-id="base-ui-_R_d4k_-viewport"><a href="/docs/guide">Guide</a></div></aside>'
    : '';
  return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width, initial-scale=1">' +
    '<meta name="description" content="Selftest page"></head><body>' +
    aside +
    '<header id="nd-nav"><nav><a href="/docs">Docs</a>' +
    `<a href="https://example.test/changelog">${linkText}</a></nav>` +
    '<button type="button" id="base-ui-_R_1abc_" aria-controls="base-ui-_R_2def_" aria-expanded="false">Menu</button>' +
    '<div id="base-ui-_R_2def_" hidden="">Panel</div></header>' +
    '<main id="nd-page"><h1>Guide</h1><p>Some <strong>text</strong> here.</p></main>' +
    '</body></html>';
}

async function write(file, content) {
  await fs.mkdir(path.dirname(file), { recursive: true });
  await fs.writeFile(file, content);
  return file;
}

function run(args) {
  let status = 0;
  let output;
  try {
    output = execFileSync(process.execPath, [tool, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  } catch (err) {
    status = err.status ?? 1;
    output = `${err.stdout ?? ''}${err.stderr ?? ''}`;
  }
  return { status, output };
}

async function readReport(reportDir, page) {
  const json = JSON.parse(await fs.readFile(path.join(reportDir, `${page}.json`), 'utf8'));
  return { json, blocking: json.diffs.filter((d) => !d.allowed), allowed: json.diffs.filter((d) => d.allowed) };
}

async function main() {
  const tmp = await fs.mkdtemp(path.join(os.tmpdir(), 'golden-dom-selftest-'));
  const emptyAllow = await write(path.join(tmp, 'allow-empty.json'), '[]\n');
  try {
    // ---- 1. Changes and normalisation ---------------------------------------------
    const referenceRoot = path.join(tmp, 'reference');
    const original = referencePage();
    await write(path.join(referenceRoot, 'pages', PAGE, 'dom.html'), original);
    await write(path.join(referenceRoot, 'pages', PAGE, 'dom-390.html'), referencePage({ drawer: true }));

    let mutated = original.replace('<html lang="en"', '<html lang="fr"');
    const removed = mutated.match(/<a [^>]*>Changelog<\/a>/);
    assert.ok(removed, 'the generated page lacks the Changelog link');
    mutated = mutated.replace(removed[0], '');
    const ids = [...new Set(original.match(/_R_[0-9a-zA-Z]*_/g) ?? [])];
    assert.ok(ids.length >= 2, 'the generated page lacks hydration ids');
    ids.forEach((id, i) => {
      mutated = mutated.split(id).join(`_R_selftest${i}q_`);
    });
    const viewport = '<meta name="viewport" content="width=device-width, initial-scale=1">';
    assert.ok(mutated.includes(viewport));
    mutated = mutated.replace(viewport, '<meta content="width=device-width, initial-scale=1" name="viewport">');

    // The client form of React ids (`_r_<n>_`, for example on the dialog portal) doesn't
    // appear in a frozen export because no overlay is open there. So that it doesn't slip
    // through when open overlays are compared, the pattern is checked directly.
    const source = await fs.readFile(tool, 'utf8');
    const pattern = source.match(/^const HYDRATION_ID = (\/.+\/)([a-z]*);$/m);
    assert.ok(pattern, 'HYDRATION_ID not found in golden-dom.mjs');
    const hydrationId = new RegExp(pattern[1].slice(1, -1), pattern[2]);
    for (const sample of ['base-ui-_R_15knel9etb_', 'base-ui-_r_3_', '_r_2_', 'base-ui-_R_1bH1_', '_R_aH2_', 'base-ui-_r_Qz_']) {
      hydrationId.lastIndex = 0;
      assert.ok(hydrationId.test(sample), `HYDRATION_ID doesn't recognise ${sample}`);
    }

    const candidateRoot = path.join(tmp, 'candidate');
    await write(path.join(candidateRoot, 'pages', PAGE, 'dom.html'), mutated);
    const report1 = path.join(tmp, 'report');
    const first = run(['--reference', referenceRoot, '--candidate', candidateRoot, '--no-rewrite', '--only', PAGE, '--ignore-classes', '--report', report1]);
    process.stdout.write(first.output);
    assert.equal(first.status, 1, 'golden-dom.mjs must exit with 1 when a page is red');
    const r1 = await readReport(report1, PAGE);
    const lang = r1.blocking.filter((d) => d.kind === 'attr' && d.attribute === 'lang');
    const missing = r1.blocking.filter((d) => d.kind === 'missing');
    const rest = r1.blocking.filter((d) => !lang.includes(d) && !missing.includes(d));
    assert.equal(lang.length, 1, `exactly one lang difference expected, got: ${JSON.stringify(lang)}`);
    assert.equal(lang[0].reference, 'en');
    assert.equal(lang[0].candidate, 'fr');
    assert.equal(missing.length, 1, `exactly one missing node expected, got: ${JSON.stringify(missing)}`);
    assert.ok(missing[0].reference.startsWith('a'), `the missing node should be an <a>: ${missing[0].reference}`);
    assert.equal(rest.length, 0, `no further differences expected, got: ${JSON.stringify(rest, null, 2)}`);
    console.log('✓ changes – lang and the removed link reported; swapped hydration ids and attribute order not');

    // ---- 2. Width case and "kind" -----------------------------------------------------
    const ref1440 = referencePage();
    const ref390 = referencePage({ drawer: true });
    const drawer = ref390.match(/<aside id="nd-sidebar-mobile"[\s\S]*?<\/aside>/)[0];
    const widthDir = path.join(tmp, 'width');
    // 390 px: reference with drawer, candidate without.
    await write(path.join(widthDir, 'pages', PAGE, 'dom-390.html'), ref390.replace(drawer, ''));
    // 1440 px: reference without drawer, candidate with an extra drawer at the start of <body>.
    await write(path.join(widthDir, 'pages', PAGE, 'dom.html'), ref1440.replace('<body>', `<body>${drawer}`));
    // Text check: 1440 px without drawer, only one link text changed.
    await write(path.join(widthDir, 'pages', PAGE, 'dom-text.html'), referencePage({ linkText: 'Release notes' }));

    async function allowFile(name, extra) {
      const entry = { selector: 'aside#nd-sidebar-mobile', attribute: '#node', ...extra, reason: `selftest (${name})` };
      return write(path.join(tmp, `allow-${name}.json`), `${JSON.stringify([entry], null, 2)}\n`);
    }
    const allowExtra = await allowFile('extra', { kind: 'extra' });
    const allowMissing = await allowFile('missing', { kind: 'missing' });
    const allowAbsent = await allowFile('without-kind', {});

    async function runWidth({ name, dom, candidateDom, viewport, allow }) {
      const report = path.join(tmp, `report-${name}`);
      const result = run([
        '--reference', referenceRoot, '--candidate', widthDir, '--no-rewrite', '--only', PAGE, '--ignore-classes',
        '--allow', emptyAllow, '--allow', allow,
        '--dom', dom, '--candidate-dom', candidateDom ?? dom, '--viewport', viewport, '--report', report,
      ]);
      process.stdout.write(`\n[${name}]\n${result.output}`);
      return { status: result.status, ...(await readReport(report, PAGE)) };
    }
    const isDrawer = (label) => typeof label === 'string' && label.startsWith('aside#nd-sidebar-mobile');

    // a) kind extra, 390 px: the missing drawer must be red.
    const a = await runWidth({ name: 'extra-390', dom: 'dom-390.html', viewport: '390x844', allow: allowExtra });
    assert.equal(a.status, 1, 'kind extra: at 390 px the missing drawer must make the page red');
    assert.equal(a.json.dom, 'dom-390.html');
    assert.equal(a.json.viewport, '390x844');
    assert.equal(a.blocking.length, 1, `kind extra, 390 px: exactly one difference expected, got: ${JSON.stringify(a.blocking, null, 2)}`);
    assert.equal(a.blocking[0].kind, 'missing');
    assert.ok(isDrawer(a.blocking[0].reference), `the missing node should be the drawer: ${a.blocking[0].reference}`);
    assert.equal(a.allowed.length, 0, 'kind extra must not allow the missing drawer');

    // b) kind extra, 1440 px: the extra drawer must be green and listed as allowed.
    const b = await runWidth({ name: 'extra-1440', dom: 'dom.html', viewport: '1440x900', allow: allowExtra });
    assert.equal(b.status, 0, `kind extra: at 1440 px the extra drawer must be allowed: ${JSON.stringify(b.blocking, null, 2)}`);
    assert.equal(b.json.dom, 'dom.html');
    assert.equal(b.blocking.length, 0);
    assert.equal(b.allowed.length, 1, `kind extra, 1440 px: exactly one allowed difference expected, got: ${JSON.stringify(b.allowed, null, 2)}`);
    assert.equal(b.allowed[0].kind, 'extra');
    assert.ok(isDrawer(b.allowed[0].candidate), `the allowed node should be the drawer: ${b.allowed[0].candidate}`);

    // c) counter check kind missing, 1440 px: the extra drawer must be red.
    const c = await runWidth({ name: 'missing-1440', dom: 'dom.html', viewport: '1440x900', allow: allowMissing });
    assert.equal(c.status, 1, 'kind missing must not allow an extra drawer');
    assert.equal(c.blocking.length, 1, `kind missing, 1440 px: exactly one difference expected, got: ${JSON.stringify(c.blocking, null, 2)}`);
    assert.equal(c.blocking[0].kind, 'extra');
    assert.ok(isDrawer(c.blocking[0].candidate));

    // d) without kind the entry allows the missing drawer as well.
    const d = await runWidth({ name: 'without-kind-390', dom: 'dom-390.html', viewport: '390x844', allow: allowAbsent });
    assert.equal(d.status, 0, `without kind the missing drawer must be allowed: ${JSON.stringify(d.blocking, null, 2)}`);
    assert.equal(d.allowed.length, 1);
    assert.equal(d.allowed[0].kind, 'missing');

    // e) a #node entry doesn't waive a text difference.
    const e = await runWidth({ name: 'text-1440', dom: 'dom.html', candidateDom: 'dom-text.html', viewport: '1440x900', allow: allowAbsent });
    assert.equal(e.status, 1, 'a text difference must not be allowed by a #node entry');
    assert.equal(e.blocking.length, 1, `text check: exactly one difference expected, got: ${JSON.stringify(e.blocking, null, 2)}`);
    assert.equal(e.blocking[0].kind, 'text');
    assert.equal(e.blocking[0].reference, 'Changelog');
    assert.equal(e.blocking[0].candidate, 'Release notes');
    assert.equal(e.allowed.length, 0);

    // f) an invalid kind aborts the run.
    const badAllow = await write(path.join(tmp, 'allow-bad.json'), `${JSON.stringify([{ selector: 'aside', attribute: '#node', kind: 'additional', reason: 'selftest' }])}\n`);
    const bad = run(['--reference', referenceRoot, '--candidate', widthDir, '--no-rewrite', '--only', PAGE, '--ignore-classes', '--allow', emptyAllow, '--allow', badAllow, '--report', path.join(tmp, 'report-bad')]);
    assert.equal(bad.status, 2, `an invalid kind must abort the run with exit code 2:\n${bad.output}`);
    console.log('\n✓ width and kind – extra/missing/both as documented, #node never waives text, invalid kind exits with 2, --allow repeatable');

    // ---- 3. Hydration ids with uppercase letters -----------------------------------------
    const upperPage = (trigger, controls, panel) =>
      '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"></head><body><main>' +
      `<button type="button" id="base-ui-${trigger}" aria-controls="base-ui-${controls}" aria-expanded="true">Group</button>` +
      `<div id="base-ui-${panel}" role="region" aria-labelledby="base-ui-${trigger}">Content</div>` +
      '<div id="base-ui-_R_9xH2_">Second panel</div>' +
      '</main></body></html>';
    const upperRef = path.join(tmp, 'upper', 'ref');
    const upperCand = path.join(tmp, 'upper', 'cand');
    const upperCandWrong = path.join(tmp, 'upper', 'cand-wrong');
    await write(path.join(upperRef, 'pages', 'x', 'dom.html'), upperPage('_R_4kH1_', '_R_7mH2_', '_R_7mH2_'));
    // other random values, also with uppercase letters: must be equal
    await write(path.join(upperCand, 'pages', 'x', 'dom.html'), upperPage('_R_zQ1_', '_R_bW2_', '_R_bW2_'));
    // aria-controls points at the second panel instead of its own: must be red
    await write(path.join(upperCandWrong, 'pages', 'x', 'dom.html'), upperPage('_R_zQ1_', '_R_9xH2_', '_R_bW2_'));

    async function runUpper(name, candidate) {
      const report = path.join(tmp, 'upper', `report-${name}`);
      const result = run(['--reference', upperRef, '--candidate', candidate, '--no-rewrite', '--ignore-classes', '--allow', emptyAllow, '--report', report]);
      process.stdout.write(`\n[${name}]\n${result.output}`);
      return { status: result.status, ...(await readReport(report, 'x')) };
    }
    const upperOk = await runUpper('uppercase-equal', upperCand);
    assert.equal(upperOk.status, 0, `hydration ids with uppercase letters must be normalised: ${JSON.stringify(upperOk.blocking, null, 2)}`);
    assert.equal(upperOk.blocking.length, 0);
    const upperBad = await runUpper('uppercase-wrong-reference', upperCandWrong);
    assert.equal(upperBad.status, 1, 'aria-controls pointing at the wrong id must be red');
    assert.equal(upperBad.blocking.length, 1, `exactly one difference expected, got: ${JSON.stringify(upperBad.blocking, null, 2)}`);
    assert.equal(upperBad.blocking[0].kind, 'attr');
    assert.equal(upperBad.blocking[0].attribute, 'aria-controls');
    console.log('\n✓ uppercase ids – normalised together with aria-controls, a wrong reference stays red');

    // ---- 4. Rewrites --------------------------------------------------------------------
    const rewritesFile = await write(path.join(tmp, 'rewrites.json'), `${JSON.stringify({
      rewrites: [['/ref/docs', '/docs'], ['/ref', '/docs'], ['/ref-assets/', '/docs/'], ['/brand/', '/docs/assets/brand/']],
      extraPages: ['/ref/docs', '/ref'],
    })}\n`);
    const expectedRewrites = {
      '/ref-assets/assets/images/x.png': '/docs/assets/images/x.png',
      '/brand/app-icon.webp': '/docs/assets/brand/app-icon.webp',
      '/ref/docs/guide': '/docs/guide',
      '/ref': '/docs',
      '/ref/docs#top': '/docs#top',
      '/other': '/extra',
    };
    const probe = run(['--rewrites', rewritesFile, '--rewrite', '/other=/extra', ...Object.keys(expectedRewrites).flatMap((value) => ['--rewrite-probe', value])]);
    assert.equal(probe.status, 0, probe.output);
    const probed = Object.fromEntries(probe.output.trim().split('\n').map((line) => line.split('\t')));
    for (const [input, expected] of Object.entries(expectedRewrites)) {
      assert.equal(probed[input], expected, `rewrite of ${input}`);
    }
    const noRules = run(['--no-rewrite', '--rewrites', rewritesFile, '--rewrite-probe', '/ref/docs/guide']);
    assert.equal(noRules.output.trim(), '/ref/docs/guide\t/ref/docs/guide', '--no-rewrite turns off every rule');
    const missingFile = run(['--rewrites', path.join(tmp, 'none.json'), '--rewrite-probe', '/x']);
    assert.equal(missingFile.status, 2, 'an unreadable --rewrites file is a usage error');
    console.log('✓ rewrites – file rules in order, trailing-slash prefixes, --rewrite appended, --no-rewrite, unreadable file exits with 2');

    const usageRun = run(['--candidate', tmp]);
    assert.equal(usageRun.status, 2, 'a missing --reference is a usage error');
  } finally {
    await fs.rm(tmp, { recursive: true, force: true });
  }

  console.log('\nSelftest passed: attribute change and missing node reported, swapped hydration ids and attribute order not; "kind" extra/missing/both work as documented with repeatable --allow; #node entries never waive text; hydration ids with uppercase letters are normalised together with aria-controls, a wrong reference stays red; rewrite files apply in order.');
}

main().catch((err) => {
  console.error(err.stack || err.message);
  process.exit(1);
});
