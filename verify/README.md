# `verify/`: Node checks

Product checks that need a JavaScript runtime or a browser: the search engine in `theme/js/search.js`
against the PHP index, the search dialog in real browsers, the files for AI agents and the icon data.
This is the only place in the repository where Node is used, because nothing here is ever shipped.
The PHP test suite in `tests/` and `scripts/check.sh` need none of it.

## Setup

Node 26 and npm 11 (`engines` in `package.json`), PHP on `PATH`.

```sh
cd verify
npm ci
npx playwright install chromium-headless-shell firefox webkit   # only for search-dialog.mjs
```

Dependencies are pinned to exact versions: `playwright`, and `lucide-react` with `react` and `react-dom`
for the icon check and the icon data generator.

## Running

```sh
node verify/run.mjs                   # every check below, in order
node verify/run.mjs --skip-browsers   # without the search dialog
```

CI runs `node verify/run.mjs` in the Node job of `.github/workflows/ci.yml`.

## Tools

| Path | What it does | Input |
| --- | --- | --- |
| `verify/run.mjs` | Runs every check and reports each one | optional `--skip-browsers` |
| `verify/search-check.mjs` | `theme/js/search.js` against `src/lib/SearchIndex.php`: normalization, codecs and the rebuilt index; the relevance cases; performance budgets | `--selftest`, `--consistency --content <dir> --base-url <path>`, `--relevance` or `--perf` with `--fixture <file>` and `--index <file>` or `--content` |
| `verify/search-query.mjs` | One query against a built index, printing the top pages as the search dialog ranks them: title, breadcrumbs, matched headings | `--index <search-index.json> "query"`, optional `--limit <n>`, `--json`, `--explain` (per-term weights, matched words and scores) |
| `verify/search-dialog.mjs` | The real search dialog in Chromium, Firefox and WebKit, with the demo served under `/docs/` and the headers of its `.htaccess`: hotkeys, results, index and worker paths, arrow keys and Enter, the main-thread fallback | optional `--browsers chromium,firefox,webkit` |
| `verify/agent-score.mjs` | Agent readiness of a running site, modelled on `mint score`: llms.txt and llms-full.txt (format, size, links), skill.md and its digests, content negotiation, robots.txt, sitemap, JSON-LD, the discovery `Link` header on a page and a 404, latency | `--base <url>` or `--demo` (builds and serves the demo), optional `--page <path>`, `--json`, `--min-score <n>` |
| `verify/lucide-oracle.mjs` | The markup of `src/lib/Icons.php` against lucide-react itself | `--check --all` or `--check --sample <n>`, optional `--seed <n>` |
| `verify/lib/browser.mjs` | Playwright resolution: `PHOLIO_PLAYWRIGHT`, then `node_modules` in `verify/` and every directory above it, then the current directory | |
| `verify/fixtures/search-relevance.json` | The demo's search relevance cases: `top1`, `top3` or `none` per query | |
| `verify/tools/build-search-index.php` | Writes the search index and the page texts that `search-check.mjs` rebuilds the postings from | `--content <dir> --base-url <url> [--texts] <out.json>` |
| `verify/tools/build-lucide-data.mjs` | Regenerates `vendor-data/lucide/icons.json` from the pinned `lucide-react` | optional `--package <dir>`, `--out <file>` |

The PHP tests `tests/SearchTest.php`, `tests/SearchRelevanceTest.php` and `tests/IconsTest.php` run the
same Node checks when Node (and, for icons, `verify/node_modules`) is available and print `SKIP:` otherwise.
