# Changelog

All notable changes to Pholio are recorded in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and Pholio adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the version is below 1.0.0, a minor version may change the content format
or the configuration schema; such changes are listed under **Changed**.

## [Unreleased]

### Added

- **Markdown for every page.** Each page gets a clean Markdown twin at `<page>.md`: component tags
  become plain Markdown, the twin opens with a pointer to `llms.txt` and ends with related topics.
- **llms.txt and llms-full.txt** at the site root and below `.well-known/`, in navigation order with
  one-line summaries. A site too large for one index gets `_llms/` section indexes instead, without
  leaving out a page.
- **skill.md and Agent Skills discovery**: a skill generated from the configuration and the page tree,
  replaceable by `skill.md` or `skills/<name>/SKILL.md`, published with the 0.2.0 discovery index and its
  digests, the 0.1.0 index, and an A2A agent card.
- **Content negotiation.** `.htaccess` and `pholio dev` answer `Accept: text/markdown`, `Accept: text/plain`
  and AI assistants' user agents with the Markdown twin, and send `Link` and `X-Llms-Txt` on every response,
  404s included. A `_headers` file carries the headers to Cloudflare Pages and Netlify;
  `examples/cloudflare-worker` negotiates on Cloudflare Workers.
- **robots.txt, sitemap.xml and JSON-LD** (WebSite, TechArticle, BreadcrumbList) on every page.
- **Page actions**: "Copy page" next to the page title, with View as Markdown, Open in ChatGPT, Open in
  Claude and Copy llms.txt URL.
- **Configuration**: `site.url`, `site.description` and the `agents` block to switch each file off,
  add agent instructions or exclude pages; `noindex: true` in the frontmatter.
- **Agent score**: `verify/agent-score.mjs` checks a running site like `mint score`; tier 2 scores the demo.

### Changed

- `.htaccess` and `pholio dev` serve `.md` files, the page twins; Markdown sources are never copied
  into the output.

## [0.2.0] - 2026-09-13

### Added

- **One-command install.** `curl -fsSL https://raw.githubusercontent.com/luka-loehr/pholio/main/scripts/install.sh | sh`
  installs the latest release for the current user with `pholio` on `PATH`, after verifying the
  checksum, PHP and PCRE2. `--version`, `--system`, `--prune` and `--dir <path>` for a per-project install.
- **`pholio init [dir]`** creates a project with a sample site: `pholio.config.php`, `content/` and
  `assets/`. It never overwrites files unless `--force`; `--name` and `--lang` set the title and language.
- **Project directory argument.** `pholio build`, `check` and `dev` take the project directory, so
  `pholio build my-docs` works from anywhere.
- **`keywords` frontmatter key**: search terms a page does not use in its text, such as synonyms,
  weighted like the title. Either a comma-separated string or a list of strings (`[a, "b c"]` or
  `- a` lines); any other value stops the build with file and line.
- **Search debugging.** `verify/search-query.mjs` prints the top results of one query against a built
  site, with `--explain` for the score of every term.
- **Releases** with `pholio-<version>.tar.gz`, `.zip` and `SHA256SUMS` attached, and continuous
  integration on PHP 8.2 to 8.5.
- **Cross-browser search check** in tier 2: the search dialog in Chromium, Firefox and WebKit, served
  under a sub-path with the site's security headers.

### Changed

- **Search ranking.** Title and phrase matches come first, then a score over title, keywords,
  description, headings and text, weighted by word rarity and by how much of the query a page
  covers. Stopwords are dropped; prefix, compound, inflection and typo matching, umlaut and `ß`
  folding and hyphen, space and joined spellings are equivalent. Results are grouped per page,
  at most 8 pages with 3 headings each.
- **Faster search.** The search index is prebuilt as a compact inverted index (format version 2)
  without body text. It loads on the first hover, focus or open of a search trigger, and queries run
  in a Web Worker so typing never blocks the page.
- **Configuration is optional.** Every key has a default: `content/` for pages, `assets/` published
  at `/assets/`, `public/` for the output and the theme under `/pholio/`. The title defaults to the
  directory name.
- **Images** resolve relative to the page or through the copied directories, and get their width and height.
- **PCRE2 10.43 is recommended, not required.** On an older PCRE2 the build succeeds and warns once
  per grammar that its highlighting is simplified.
- The code block opt-out classes are now `not-pholio-codeblock` and `not-pholio-code`.

### Fixed

- ⌘K and Ctrl+K also open search when the key arrives as uppercase "K" (Caps Lock, synthetic key events).
- Arrow-key selection in the search dialog no longer jumps back to the first result when a slower
  answer for the same query arrives.
- The secondary hero button no longer animates its background again after the page has loaded.

### Removed

- The zbsearch notice and license text: the search no longer derives from zbsearch.

## [0.1.0] - 2026-09-13

The first public release: a static documentation generator in plain PHP that
reproduces the Notebook documentation theme, with the tooling that measures the
reproduction.

### Added

- **Command line.** `pholio build`, `pholio check` (diff a build against a directory in
  both directions) and `pholio dev` (build with drafts, serve with PHP's built-in
  server, rebuild on changes), with `--config`, `--profile`, `--content`, `--out`,
  `--only`, `--dev` and `--set`, and documented exit codes.
- **Configuration.** One PHP file returning a plain array, validated and normalized
  with errors naming the key; profiles merged over the base configuration;
  `pholio.config.example.php` documents every key.
- **Content format.** A strict Markdown subset with front matter, one `meta.json`
  per folder for the page tree, heading ids, table of contents and footnotes.
  Unknown constructs, components, icons or languages, invalid `meta.json` and
  duplicate slugs stop the build with file and line.
- **Components** as declarative tags: callouts, cards, banners, screenshots,
  images with zoom, tabs, accordions, steps, file trees, type tables, an inline
  table of contents and code blocks.
- **Syntax highlighting** from the TextMate grammars and GitHub light and dark
  themes packaged by Shiki, translated from Oniguruma to PCRE2 (full parity needs
  PCRE2 10.43 or newer).
- **Search without a backend.** A search index built at compile time and ranking
  in the browser with zbsearch's parameters, with English and German tokenizers.
- **Theme.** The Notebook layout in plain CSS and JavaScript modules: sidebar,
  header tabs, table of contents popover, search dialog, theme switch, light and
  dark schemes, Inter fonts and lucide icons.
- **Deployment output.** Static HTML per page, one stylesheet, the search index and
  an Apache `.htaccess` with security headers and configured redirects.
- **Interface languages** English and German.
- **Demo site** in `examples/demo/` covering every component, with its build
  committed as `tests/snapshots/demo`.
- **Tests and checks.** A dependency-free PHP test suite (`php tests/run.php`),
  `scripts/lint.sh`, `scripts/check.sh`, `scripts/update-snapshots.sh` and
  `scripts/serve-demo.sh`.
- **Verification tooling** in `verify/` (Node, development only) in tiers 0 to 3:
  golden DOM, computed styles, pixel diff and behavior against a reference
  export, plus search and lucide oracles.
- Documentation in `docs/`, written in Pholio's own format, and the licenses and
  notices of all vendored material.

[Unreleased]: https://github.com/luka-loehr/pholio/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/luka-loehr/pholio/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/luka-loehr/pholio/releases/tag/v0.1.0
