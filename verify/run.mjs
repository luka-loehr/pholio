#!/usr/bin/env node
// Tiered verification runner.
//
//   node verify/run.mjs --tier 0 [--require-playwright] [--launch-browser]
//   node verify/run.mjs --tier 1
//   node verify/run.mjs --tier 2
//   node verify/run.mjs --tier 3 --reference <dir> --rewrites <file.json> --candidate <url>
//
// A tier runs every lower tier first. See docs/verification.md for what each tier needs.
//
//   0  Foundation selftest. Node only, no network, no browser: syntax of every
//      verify/lib module, argument and rewrite handling (including --rewrites files),
//      page lists, PNG round trip, pixel diff, colour normalisation, structure mismatch,
//      the static site server, and Playwright resolution (reported; required with
//      --require-playwright, launched once with --launch-browser).
//   1  PHP: scripts/check.sh.
//   2  Node tooling without a reference: the selftests of the parity tools and the
//      search and lucide oracles (need PHP on PATH).
//   3  Parity against a reference export.
//
// Exit codes: 0 green, 1 a step failed, 2 usage error.

import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const verifyDir = path.dirname(fileURLToPath(import.meta.url));
const pholioRoot = path.resolve(verifyDir, '..');
const libDir = path.join(verifyDir, 'lib');

const argv = process.argv.slice(2);
const option = (name) => {
  const i = argv.indexOf(`--${name}`);
  return i >= 0 ? argv[i + 1] : undefined;
};
const has = (name) => argv.includes(`--${name}`);

function usage(message) {
  console.error(`${message}\nUsage: node verify/run.mjs --tier <0|1|2|3> [options]`);
  process.exit(2);
}

const tierRaw = option('tier');
if (tierRaw === undefined) usage('Missing: --tier');
const tier = Number(tierRaw);
if (![0, 1, 2, 3].includes(tier)) usage(`--tier must be 0, 1, 2 or 3, got: ${tierRaw}`);

let failed = 0;
let passed = 0;

async function check(name, fn) {
  try {
    const note = await fn();
    passed += 1;
    console.log(`ok    ${name}${note ? ` (${note})` : ''}`);
  } catch (err) {
    failed += 1;
    console.log(`FAIL  ${name}\n      ${String(err?.stack ?? err).split('\n').join('\n      ')}`);
  }
}

function tempDir() {
  return fs.mkdtempSync(path.join(os.tmpdir(), 'pholio-verify-'));
}

function writeFile(dir, name, content) {
  const file = path.join(dir, name);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, typeof content === 'string' ? content : JSON.stringify(content));
  return file;
}

// Run a command with inherited output; throws on a non-zero exit.
function runCommand(command, args, { cwd = pholioRoot } = {}) {
  const result = spawnSync(command, args, { cwd, stdio: 'inherit' });
  if (result.error) throw result.error;
  if (result.status !== 0) throw new Error(`${command} ${args.join(' ')} exited with ${result.status}`);
}

// ---- Tier 0 ---------------------------------------------------------------

async function tier0() {
  const modules = fs.readdirSync(libDir).filter((f) => f.endsWith('.mjs')).sort();

  await check('lib: node --check on every module', () => {
    for (const file of modules) {
      const result = spawnSync(process.execPath, ['--check', path.join(libDir, file)], { encoding: 'utf8' });
      assert.equal(result.status, 0, `${file}: ${result.stderr}`);
    }
    return `${modules.length} modules`;
  });

  const cli = await import('./lib/cli.mjs');
  const pages = await import('./lib/pages.mjs');
  const png = await import('./lib/png.mjs');
  const pixel = await import('./lib/pixel.mjs');
  const color = await import('./lib/color.mjs');
  const domWalk = await import('./lib/dom-walk.mjs');
  const browser = await import('./lib/browser.mjs');
  const serveSite = await import('./lib/serve-site.mjs');

  await check('cli: argument helpers', () => {
    const a = ['node', 'tool', '--name', 'value', '--list', 'a, b,,c', '--n', '42', '--x', '1', '--x', '2', '--flag'];
    assert.equal(cli.arg('name', 'fb', a), 'value');
    assert.equal(cli.arg('missing', 'fb', a), 'fb');
    assert.equal(cli.flag('flag', a), true);
    assert.deepEqual(cli.argAll('x', a), ['1', '2']);
    assert.deepEqual(cli.argList('list', [], a), ['a', 'b', 'c']);
    assert.equal(cli.argNumber('n', 0, a), 42);
    assert.throws(() => cli.argNumber('name', 0, a), /needs a number/);
  });

  await check('cli: no built-in rewrites', () => {
    assert.equal('DEFAULT_REWRITES' in cli, false);
    assert.deepEqual(cli.buildRewrites(['node', 'tool']), []);
    assert.deepEqual(cli.buildExtraPages(['node', 'tool']), []);
  });

  await check('cli: --rewrites loads a JSON file', () => {
    const dir = tempDir();
    try {
      const list = writeFile(dir, 'list.json', [['/ref/docs', '/docs'], ['/static/', '/docs/assets/']]);
      const object = writeFile(dir, 'object.json', {
        rewrites: [['/ref', '/']],
        extraPages: ['/ref/docs', '/ref'],
      });
      const bad = writeFile(dir, 'bad.json', { rewrites: [['/only-one']] });
      const broken = writeFile(dir, 'broken.json', '{ not json');

      assert.deepEqual(cli.buildRewrites(['node', 'tool', '--rewrites', list]), [
        ['/ref/docs', '/docs'],
        ['/static/', '/docs/assets/'],
      ]);
      // Files apply in order, then single --rewrite rules.
      assert.deepEqual(
        cli.buildRewrites(['node', 'tool', '--rewrite', '/a=/b', '--rewrites', list, '--rewrites', object]),
        [['/ref/docs', '/docs'], ['/static/', '/docs/assets/'], ['/ref', '/'], ['/a', '/b']],
      );
      assert.deepEqual(cli.buildRewrites(['node', 'tool', '--rewrites', list, '--no-rewrite']), []);
      assert.deepEqual(
        cli.buildExtraPages(['node', 'tool', '--rewrites', object, '--extra-pages', '/ref,/extra']),
        ['/ref/docs', '/ref', '/extra'],
      );
      assert.deepEqual(cli.buildExtraPages(['node', 'tool', '--rewrites', list]), []);
      assert.throws(() => cli.buildRewrites(['node', 'tool', '--rewrites', bad]), /\[from, to\] string pairs/);
      assert.throws(() => cli.buildRewrites(['node', 'tool', '--rewrites', broken]), /not readable/);
      assert.throws(() => cli.buildRewrites(['node', 'tool', '--rewrites', path.join(dir, 'none.json')]), /not readable/);
      assert.throws(() => cli.buildRewrites(['node', 'tool', '--rewrite', 'no-equals']), /from=to/);

      const rules = cli.buildRewrites(['node', 'tool', '--rewrites', list]);
      assert.equal(cli.rewritePath('/ref/docs', rules), '/docs');
      assert.equal(cli.rewritePath('/ref/docs/guide#top', rules), '/docs/guide#top');
      assert.equal(cli.rewritePath('/ref/docsearch', rules), '/ref/docsearch');
      assert.equal(cli.rewritePath('/static/img/a.png', rules), '/docs/assets/img/a.png');
    } finally {
      fs.rmSync(dir, { recursive: true, force: true });
    }
  });

  await check('cli: joinUrl', () => {
    assert.equal(cli.joinUrl('http://127.0.0.1:4000/', '/docs/guide'), 'http://127.0.0.1:4000/docs/guide');
    assert.equal(cli.joinUrl('https://example.test/docs', '/docs/guide'), 'https://example.test/docs/guide');
    assert.equal(cli.joinUrl('https://example.test/docs', '/docs'), 'https://example.test/docs');
    assert.equal(cli.isUrl('https://example.test'), true);
    assert.equal(cli.isUrl('/docs'), false);
  });

  await check('pages: tree, extra pages, filter, URLs', async () => {
    const dir = tempDir();
    try {
      writeFile(dir, 'tree.json', {
        children: [
          { page: '/ref/docs/a' },
          { index: '/ref/docs/b', children: [{ page: '/ref/docs/b/c' }, { page: '/ref/docs/a' }] },
        ],
      });
      assert.deepEqual(await pages.loadPageList(dir), ['/ref/docs/a', '/ref/docs/b', '/ref/docs/b/c']);
      const list = await pages.loadPageList(path.join(dir, 'tree.json'), ['/ref', '/ref/docs/a']);
      assert.deepEqual(list, ['/ref', '/ref/docs/a', '/ref/docs/b', '/ref/docs/b/c']);
      assert.deepEqual(pages.filterPages(list, '=/ref'), ['/ref']);
      assert.equal(pages.filterPages(list, '/ref').length, 4);
      assert.throws(() => pages.filterPages(list, 'nothing'), /matches no reference page/);
      await assert.rejects(pages.resolveTreeFile(path.join(dir, 'empty')), /No tree.json/);
      assert.equal(pages.pageSlug('/'), 'index');
      assert.deepEqual(
        pages.pageUrls('/ref/docs/a', {
          referenceBase: 'http://127.0.0.1:1',
          candidateBase: 'http://127.0.0.1:2/docs',
          rewrites: [['/ref/docs', '/docs']],
        }),
        { path: '/ref/docs/a', slug: 'ref/docs/a', reference: 'http://127.0.0.1:1/ref/docs/a', candidate: 'http://127.0.0.1:2/docs/a' },
      );
    } finally {
      fs.rmSync(dir, { recursive: true, force: true });
    }
  });

  await check('png: encode/decode round trip', () => {
    const img = png.blankImage(3, 2, [10, 20, 30, 255]);
    img.data[4 * 4 + 3] = 128;
    const back = png.decodePng(png.encodePng(img));
    assert.equal(back.width, 3);
    assert.equal(back.height, 2);
    assert.deepEqual([...back.data], [...img.data]);
    assert.throws(() => png.decodePng(Buffer.from('not a png at all')), /signature/);
  });

  await check('pixel: diffImages', () => {
    const a = png.blankImage(4, 4, [255, 255, 255, 255]);
    const same = pixel.diffImages(a, png.blankImage(4, 4, [255, 255, 255, 255]));
    assert.equal(same.different, 0);
    const b = png.blankImage(4, 4, [255, 255, 255, 255]);
    b.data[0] = 0;
    assert.equal(pixel.diffImages(a, b).different, 1);
    const c = png.blankImage(4, 4, [255, 255, 255, 255]);
    c.data[1] = 254;
    const rounding = pixel.diffImages(a, c, { maxChannelDelta: 1 });
    assert.equal(rounding.different, 0);
    assert.equal(rounding.rounding, 1);
    const bigger = pixel.diffImages(a, png.blankImage(5, 4, [255, 255, 255, 255]));
    assert.equal(bigger.sameSize, false);
    assert.equal(bigger.different, 4);
  });

  await check('color: normalisation', () => {
    assert.equal(color.normaliseColor('#fff'), 'rgba(255, 255, 255, 1)');
    assert.equal(color.normaliseColor('oklch(1 0 0)'), 'rgba(255, 255, 255, 1)');
    assert.equal(color.normaliseColor('transparent'), 'rgba(0, 0, 0, 0)');
    assert.equal(color.normaliseColor('10px'), null);
    assert.equal(
      color.normaliseColorsInValue('rgb(0 0 0 / 10%) 0 1px 2px, url("/a/linen.png") red'),
      'rgba(0, 0, 0, 0.1) 0 1px 2px, url("/a/linen.png") rgba(255, 0, 0, 1)',
    );
  });

  await check('dom-walk: structure mismatch', () => {
    assert.equal(domWalk.firstStructureMismatch(['a', 'b'], ['a', 'b'], [], []), null);
    assert.equal(domWalk.firstStructureMismatch(['a', 'b'], ['a', 'c'], ['x', 'y'], ['x', 'z']).kind, 'tag');
    assert.equal(domWalk.firstStructureMismatch(['a', 'b'], ['a'], ['x', 'y'], ['x']).kind, 'missing');
    assert.equal(domWalk.collectOptions({ properties: [], customProperties: [] }).dropTags.includes('script'), true);
  });

  await check('serve-site: static files, 404 without origin, fallback with origin', async () => {
    const siteDir = tempDir();
    const originDir = tempDir();
    const servers = [];
    try {
      writeFile(siteDir, 'index.html', '<p>home</p>');
      writeFile(siteDir, 'docs/guide/index.html', '<p>guide</p>');
      writeFile(originDir, 'images/a.txt', 'from origin');
      const origin = await serveSite.startSite({ root: originDir });
      servers.push(origin);
      const plain = await serveSite.startSite({ root: siteDir });
      servers.push(plain);
      const withOrigin = await serveSite.startSite({ root: siteDir, origin: origin.url });
      servers.push(withOrigin);

      const get = async (base, p) => {
        const r = await fetch(base + p);
        return [r.status, await r.text()];
      };
      assert.deepEqual(await get(plain.url, '/'), [200, '<p>home</p>']);
      assert.deepEqual(await get(plain.url, '/docs/guide'), [200, '<p>guide</p>']);
      assert.deepEqual(await get(plain.url, '/docs/guide/'), [200, '<p>guide</p>']);
      assert.equal((await get(plain.url, '/images/a.txt'))[0], 404);
      assert.deepEqual(await get(withOrigin.url, '/images/a.txt'), [200, 'from origin']);
    } finally {
      await Promise.all(servers.map((s) => s.close()));
      fs.rmSync(siteDir, { recursive: true, force: true });
      fs.rmSync(originDir, { recursive: true, force: true });
    }
  });

  await check('serve-reference: refuses to start without --reference and --lab', () => {
    const env = { ...process.env };
    delete env.PHOLIO_REFERENCE;
    const result = spawnSync(process.execPath, [path.join(libDir, 'serve-reference.mjs')], { encoding: 'utf8', env });
    assert.equal(result.status, 2);
    assert.match(result.stderr, /--reference/);
  });

  await check('browser: Playwright resolution order', () => {
    const order = browser.playwrightCandidates({
      env: { PHOLIO_PLAYWRIGHT: '/opt/pw' },
      from: '/w/app/vendor/pholio/verify',
      root: '/w/root',
      cwd: '/w/cwd',
    });
    assert.deepEqual(order, [
      '/opt/pw/index.mjs',
      '/w/app/vendor/pholio/verify/node_modules/playwright/index.mjs',
      '/w/app/vendor/pholio/node_modules/playwright/index.mjs',
      '/w/app/vendor/node_modules/playwright/index.mjs',
      '/w/app/node_modules/playwright/index.mjs',
      '/w/node_modules/playwright/index.mjs',
      '/node_modules/playwright/index.mjs',
      '/w/root/node_modules/playwright/index.mjs',
      '/w/cwd/node_modules/playwright/index.mjs',
    ]);
    const resolved = browser.resolvePlaywright();
    if (!resolved && has('require-playwright')) throw new Error('Playwright not found (--require-playwright)');
    return resolved ? `resolved: ${resolved}` : 'not installed, skipped';
  });

  if (has('launch-browser')) {
    await check('browser: launch Chromium once', async () => {
      const instance = await browser.launchBrowser();
      try {
        const { context, page } = await browser.openPage(instance, { width: 320 });
        await page.setContent('<p>ok</p>');
        assert.equal(await page.textContent('p'), 'ok');
        await browser.closeQuietly(context);
        return instance.version();
      } finally {
        await instance.close();
      }
    });
  }
}

// ---- Tiers 1–3 ------------------------------------------------------------

function scriptStep(relative, args = []) {
  return [`${relative}${args.length ? ` ${args.join(' ')}` : ''}`, () => {
    const file = path.join(pholioRoot, relative);
    if (!fs.existsSync(file)) throw new Error(`missing: ${relative}`);
    const command = file.endsWith('.sh') ? file : process.execPath;
    runCommand(command, file.endsWith('.sh') ? args : [file, ...args]);
  }];
}

async function tier1() {
  await check(...scriptStep('scripts/check.sh'));
}

async function tier2() {
  for (const tool of ['verify/golden-dom-selftest.mjs', 'verify/computed-style-selftest.mjs', 'verify/pixel-diff-selftest.mjs']) {
    await check(...scriptStep(tool));
  }
  await check(...scriptStep('verify/search-parity.mjs', ['--selftest']));
  await check(...scriptStep('verify/search-parity.mjs', [
    '--oracle', '--content', 'examples/demo/content', '--base-url', '/',
    '--queries', 'verify/fixtures/demo/queries.json', '--tokenizer', 'english',
  ]));
  // Every icon (the sample size exceeds the icon count) with a fixed seed, which also
  // fixes the class names paired with each icon: a failure reproduces on every run.
  await check(...scriptStep('verify/lucide-oracle.mjs', ['--check', '--sample', '100000', '--seed', '1']));
}

async function tier3() {
  const reference = option('reference') ?? process.env.PHOLIO_REFERENCE;
  const rewrites = option('rewrites');
  const candidate = option('candidate');
  if (!reference || !rewrites || !candidate) {
    usage('Tier 3 needs --reference <dir> (or PHOLIO_REFERENCE), --rewrites <file.json> and --candidate <url>');
  }
  const shared = ['--reference', reference, '--rewrites', rewrites, '--candidate', candidate];
  for (const tool of ['verify/golden-dom.mjs', 'verify/computed-style.mjs', 'verify/pixel-diff.mjs', 'verify/behaviour.mjs']) {
    await check(...scriptStep(tool, shared));
  }
}

const tiers = [tier0, tier1, tier2, tier3];
for (let t = 0; t <= tier; t += 1) {
  console.log(`\n== tier ${t}`);
  await tiers[t]();
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
