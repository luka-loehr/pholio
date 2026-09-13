---
title: Testing
description: The PHP test suite, the committed demo snapshot and the Node checks of search, agent files and icons.
icon: check-circle
---

A change is done when the checks have run and their output has been read.
Everything that shapes the generated site is compared against committed output,
so a change shows up as a diff instead of as a surprise on a published site.

## The PHP checks

```bash
./scripts/check.sh
```

`check.sh` needs nothing but PHP 8.2 and git. It runs three steps and stops at the
first failure:

1. `scripts/lint.sh`: `php -l` on every tracked PHP file.
2. `php tests/run.php`: every `tests/*Test.php` in its own process. `--only Config,Cli`
   runs a subset.
3. `pholio check` of the demo site against `tests/snapshots/demo`.

Tests that need Node print `SKIP: <reason>` and pass without it.
`php tests/run.php --require-node` turns every skip into a failure.

### The demo snapshot

`tests/snapshots/demo` is the committed build of `examples/demo`, a site that uses
every component. Any change to the generated HTML, CSS, JavaScript, search index or
agent files shows up as a `missing:`, `stale:` or `extra:` line. After an intended
change, regenerate it and review the diff:

```bash
./scripts/update-snapshots.sh
git diff --stat tests/snapshots/demo
```

The snapshot is generated output and is committed as one commit per run.

### Highlighter samples

`tests/fixtures/highlight` holds code samples in every bundled language with the
expected HTML next to each one: notation comments, Windows line endings, very long
lines, emoji, unterminated strings and the edge cases of the code fence meta.
`php tests/HighlightTest.php --update` rewrites the expected files after an
intended change.

### PCRE2

The highlighter's complete grammars need PCRE2 10.43 or newer. On an older PCRE2
the highlighter simplifies a few patterns, so the highlighter samples and the
snapshot check skip themselves and `check.sh` builds the demo instead.
`PHOLIO_REQUIRE_PCRE2=1` turns those skips into failures.

## The Node checks

```bash
cd verify
npm ci
npx playwright install chromium-headless-shell firefox webkit
cd ..
node verify/run.mjs
```

| Check | What it proves |
| --- | --- |
| Search engine | `theme/js/search.js` normalizes, splits and encodes exactly like `src/lib/SearchIndex.php`, and rebuilds the demo index from the page texts |
| Search relevance | Every query in `verify/fixtures/search-relevance.json` ranks the expected pages first |
| Search dialog | Hotkeys, results, arrow keys, Enter and the main-thread fallback in Chromium, Firefox and WebKit, with the demo served under a subpath and its security headers |
| Agent score | The demo's `llms.txt`, `skill.md`, content negotiation, robots.txt, sitemap and JSON-LD score 100 of 100 |
| Icons | Every icon's markup matches lucide itself |

`node verify/search-query.mjs --index <search-index.json> --explain "query"` shows
how a single query was ranked, and `node verify/agent-score.mjs --base <url>`
scores any running site. [`verify/README.md`](https://github.com/luka-loehr/pholio/blob/main/verify/README.md)
lists every tool.

## Continuous integration

Every push and pull request to `main` runs:

- `check.sh` on PHP 8.2 to 8.5 with the PCRE2 of stable distributions, plus a demo
  build that must print the PCRE2 fallback warning.
- `check.sh` on PHP 8.2 and 8.5 with PCRE2 10.43 or newer and
  `PHOLIO_REQUIRE_PCRE2=1`, so the highlighter samples and the snapshot must match.
- `node verify/run.mjs` on Node 26.

A release tag runs the PHP jobs again before the archives are published.
