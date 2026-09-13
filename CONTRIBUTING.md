# Contributing

Pholio is a personal project by Luka Löhr, published at version 0.1.0.
These are the rules that apply to every change.

## One file per commit

Every commit changes exactly one file. Run `git add <file> && git commit`,
never `git add -A`, never several files at once. The result is a long history
in which every change can be read, reviewed and reverted on its own.

The single exception is generated output: the files a build writes are
committed together, once per build run.

## Commit messages

Conventional prefix, then the file, then what changed:

```
feat(components): src/components/callout.php – icon and color strip per type
fix(theme): theme/css/notebook.css – sidebar mask at 12px, not 16px
docs: docs/configuration.md – document the search tokenizer key
```

English, imperative, no attribution or co-author lines.

## Acceptance is a measurement

A change is done when the relevant checks have been run and read, not when it
looks right. "Works on my machine" is not a report; a diff is.

| Checks | Command | Needs |
| --- | --- | --- |
| PHP | `./scripts/check.sh` | PHP 8.2, PCRE2 10.43 |
| Node | `node verify/run.mjs` | Node 26, `npm ci` in `verify/`, the Playwright browsers |

A change to generated output regenerates the demo snapshot with
`./scripts/update-snapshots.sh`, committed as one run. See [Checks](#checks)
and [`verify/README.md`](verify/README.md).

A new configuration key is added to `src/Config.php` and
`docs/configuration.md` together; `tests/DocsConfigTest.php` fails otherwise.

## Code rules

- PHP 8.2, `declare(strict_types=1)`, four spaces, no Composer dependencies.
- Components are pure functions: props in, HTML string out, no globals, no I/O.
- Fail loud. Unknown input aborts the build with the file and line in the
  message. Never add a silent fallback.
- JavaScript is plain ES modules, two spaces, no bundler and no libraries.
- CSS is hand-written in `theme/css/`, in cascade order. Colors go through the
  `--color-fd-*` tokens, so presets and site palettes reach every component.
- Node is allowed in `verify/` only, because nothing there is ever shipped.

## Checks

### PHP

`./scripts/check.sh` needs nothing but PHP 8.2 and git. It stops at the first
failure:

1. `scripts/lint.sh`: `php -l` on every tracked PHP file.
2. `php tests/run.php`: every `tests/*Test.php` in its own process. `--only Config,Cli`
   runs a subset. Tests that need Node print `SKIP: <reason>` and pass without it;
   `--require-node` turns every skip into a failure.
3. `pholio check` of the demo site against `tests/snapshots/demo`.

**Demo snapshot.** `tests/snapshots/demo` is the committed build of `examples/demo`,
a site that uses every component. Any change to the generated HTML, CSS,
JavaScript, search index or agent files shows up as a `missing:`, `stale:` or
`extra:` line. After an intended change, run `./scripts/update-snapshots.sh`,
review `git diff --stat tests/snapshots/demo` and commit the run as one commit.

**Highlighter samples.** `tests/fixtures/highlight` holds code samples in every
bundled language with the expected HTML next to each one.
`php tests/HighlightTest.php --update` rewrites the expected files after an
intended change.

**PCRE2.** The highlighter's complete grammars need PCRE2 10.43 or newer. On an
older PCRE2 the highlighter samples and the snapshot check skip themselves and
`check.sh` builds the demo instead; `PHOLIO_REQUIRE_PCRE2=1` turns those skips
into failures.

### Node

```bash
cd verify && npm ci && npx playwright install chromium-headless-shell firefox webkit && cd ..
node verify/run.mjs
```

| Check | What it proves |
| --- | --- |
| Search engine | `theme/js/search.js` normalizes, splits and encodes exactly like `src/lib/SearchIndex.php`, and rebuilds the demo index from the page texts |
| Search relevance | Every query in `verify/fixtures/search-relevance.json` ranks the expected pages first |
| Search dialog | Hotkeys, results, arrow keys, Enter and the main-thread fallback in Chromium, Firefox and WebKit, with the demo served under a subpath and its security headers |
| Agent score | The demo's agent files and content negotiation score 100 of 100 |
| Icons | Every icon's markup matches lucide itself |

### CI

Every push and pull request to `main` runs `check.sh` on PHP 8.2 to 8.5 with the
PCRE2 of stable distributions (plus a demo build that must print the PCRE2
fallback warning), `check.sh` on PHP 8.2 and 8.5 with PCRE2 10.43 or newer and
`PHOLIO_REQUIRE_PCRE2=1`, and `node verify/run.mjs` on Node 26. A release tag runs
the PHP jobs again before the archives are published.

## Before you push

```bash
./scripts/check.sh
```
