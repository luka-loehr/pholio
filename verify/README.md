# `verify/`: proving the rebuild is identical

Development tooling that compares a Pholio build against the reference build. It is
the only place in the repository where Node is allowed, because nothing here is ever
shipped. Reference exports are never committed (`verify/reference/` is ignored).

## Setup

Node 26 and npm 11 (`engines` in `package.json`). The Node major is pinned because the
highlight oracle's known differences depend on the V8 regular expression engine.

```sh
cd verify
npm ci
npx playwright install chromium-headless-shell   # only for browser-based tools
```

Dependencies are pinned to exact versions: `playwright`, `shiki`, the reference UI core package, `lucide-react`, `react` and
`react-dom`, the versions the reference uses (see `verify/package.json`).

## Tiers

| Tier | Needs | Command |
| --- | --- | --- |
| 0 | Node | `node verify/run.mjs --tier 0` |
| 1 | + PHP 8.2 | `node verify/run.mjs --tier 1` |
| 2 | + `npm ci` and Chromium | `node verify/run.mjs --tier 2` |
| 3 | + a reference export, the running reference app and a rewrites file | `node verify/run.mjs --tier 3 --reference <export dir> --reference-url <app url> --candidate <url> --rewrites <file.json>` |

Tier 3 compares the golden DOM against the export directory and computed style, pixels and
behavior against the running reference app. `--reference` and `--reference-url` can also come
from `PHOLIO_REFERENCE` and `PHOLIO_REFERENCE_URL`. `--allow` (repeatable) is passed to
`golden-dom.mjs`, which otherwise uses `verify/allow/golden-dom.json`. `--scenarios`
(repeatable) defaults to the demo layout and overlays scenarios and `--states` to the demo
pixel states, all in `verify/fixtures/demo/`. The demo catalog scenarios are pending
verification against the reference, so no tier runs them unless passed with `--scenarios`.

A tier runs every lower tier first. Tier 0 is a selftest of `verify/lib`: syntax of every
module, argument and rewrite handling, page lists, PNG round trip, pixel diff, color
normalization, the static server and Playwright resolution. `--require-playwright` fails
when Playwright can't be found, `--launch-browser` starts Chromium once.

## Shared modules (`verify/lib`)

| Module | What it does |
| --- | --- |
| `cli.mjs` | Flags, `~` expansion, JSON helpers, reference → candidate path rewrites, URL joining |
| `pages.mjs` | Page list from the export's `tree.json` plus extra pages, `--only` filter, page URL pairs |
| `browser.mjs` | Playwright resolution and launch, one context per page, theme, animation settling, image loading |
| `dom-walk.mjs` | Normalized element tree and computed style collection in the browser |
| `color.mjs` | CSS Color 4 normalization of every color in a property value to `rgba()` |
| `png.mjs` | Dependency-free PNG reader and writer |
| `pixel.mjs` | YIQ pixel diff with antialiasing and rounding detection |
| `serve-site.mjs` | Static server for a built site, optional fallback `--origin` |
| `serve-reference.mjs` | Frozen reference DOM with Pholio's JavaScript, proxying to the running reference app |

### Rewrites

Reference and candidate paths usually differ (for example `/reference/docs/guide` against
`/docs/guide`). There are no built-in rules: rewrites are site data and come from a JSON
file passed with `--rewrites` (repeatable) plus single `--rewrite from=to` flags, applied in
that order. `--no-rewrite` turns all of them off.

```json
{
  "rewrites": [["/reference/docs", "/docs"], ["/reference", "/"]],
  "extraPages": ["/reference/docs", "/reference"]
}
```

A plain list of `[from, to]` pairs is accepted as well. A rule ending in `/` is a prefix;
any other rule matches the path itself and anything below it (`/`, `#`, `?`).
`extraPages` are reference paths checked in addition to the page tree (a docs root or a
start page that the tree doesn't list); `--extra-pages a,b` adds more.

### Playwright resolution

`browser.mjs` looks for `node_modules/playwright/index.mjs` in this order and takes the
first hit:

1. `PHOLIO_PLAYWRIGHT`, the package directory or its `index.mjs`.
2. `verify/` and every directory above it. This covers `npm ci` inside `verify/` as well
   as a copy of Pholio vendored into another repository whose `node_modules` sits further
   up (for example `<repo>/vendor/pholio/verify` with `<repo>/node_modules`).
3. The directory passed to `launchBrowser()`, then the current working directory.

## Parity tools

| Path | What it does | Required input |
| --- | --- | --- |
| `verify/export-reference.mjs` | Freezes the reference: server-rendered and hydrated HTML per page, table of contents, search answers, icons, fonts, compiled CSS, page tree | `--lab-dir <app dir>` and `--lab-url <url>` (or `PHOLIO_LAB_DIR`, `PHOLIO_LAB_URL`) |
| `verify/golden-dom.mjs` | Element tree and attribute diff per page, with a documented allow-list for unavoidable differences such as hydration ids | `--reference <export dir>`, `--candidate <url or dir>`, `--rewrites <file>`, optional repeatable `--allow <file>` |
| `verify/computed-style.mjs` | About 70 CSS properties per element, at three widths, light and dark, including hover and focus states | `--reference <app url>`, `--candidate <url>`, `--pages <export dir or tree.json>`, `--rewrites <file>` |
| `verify/pixel-diff.mjs` | Full-page screenshot diff, tolerance zero outside text antialiasing | as computed-style, plus optional `--states <file>` |
| `verify/behaviour.mjs` | Keyboard, focus order, scroll lock, click-outside, animation keyframes via `getAnimations()` | `--reference <app url>`, `--candidate <url>`, `--rewrites <file>`, repeatable `--scenarios <file>` |
| `verify/catalogue-states.mjs` | Static, interactive and layered states of the component catalog | `--mode`, `--site <dir>`, `--out <dir>`; `--reference <export dir>` and `--cuts <file>` for `static` and `states` |
| `verify/search-parity.mjs` | `theme/js/search.js` against `src/lib/SearchIndex.php` (normalization, codecs, the rebuilt index), relevance cases and performance budgets | `--selftest`, `--consistency --content <dir> --base-url <path>`, `--relevance` or `--perf` with `--fixture <file>` and `--index <file>` or `--content` |
| `verify/search-query.mjs` | One query against a built index, printing the top pages as the search dialog ranks them: title, breadcrumbs, matched headings | `--index <search-index.json> "query"`, optional `--limit <n>`, `--json`, `--explain` (per-term weights, matched words and scores) |
| `verify/search-dialog.mjs` | The real search dialog in Chromium, Firefox and WebKit, with the demo served under `/docs/` and the headers of its `.htaccess`: hotkeys, results, index and worker paths, arrow keys and Enter, the main-thread fallback | optional `--browsers chromium,firefox,webkit` |
| `verify/agent-score.mjs` | Agent readiness of a running site, modelled on `mint score`: llms.txt and llms-full.txt (format, size, links), skill.md and its digests, content negotiation, robots.txt, sitemap, JSON-LD, the discovery `Link` header on a page and a 404, latency | `--base <url>` or `--demo` (builds and serves the demo), optional `--page <path>`, `--json`, `--min-score <n>` |
| `verify/lucide-oracle.mjs` | Icon markup against lucide-react | `--check --all` or `--check --sample <n>`, optional `--seed <n>` |
| `verify/fixtures/demo/` | Demo site data: search queries, behavior scenarios and pixel states | |
| `verify/class-map.json` | Utility class to `nd-*` component class translation, so the DOM diff keeps working after the CSS is rewritten | |

All stages must be green. A stage reports a diff, never a score.
