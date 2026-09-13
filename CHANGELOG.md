# Changelog

All notable changes to Pholio are recorded in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and Pholio adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the version is below 1.0.0, a minor version may change the content format
or the configuration schema; such changes are listed under **Changed**.

## [Unreleased]

### Added

- Continuous integration on PHP 8.2 to 8.5 and the tier 2 verification on Node 26.
- Release workflow that publishes `pholio-<version>.tar.gz`, `.zip` and `SHA256SUMS` for every `v*.*.*` tag.
- `scripts/install.sh`, a one-command install of a release with checksum, PHP and PCRE2 checks.
- `scripts/release.sh`, which bumps `VERSION`, dates the changelog, runs the checks and tags a release.
- `verify/search-query.mjs`, which prints the top results of one query against a built search index,
  with `--explain` for the score of every term.
- `keywords` frontmatter key: comma-separated search terms a page does not use in its text, such as
  synonyms, weighted like the title.
- `verify/search-dialog.mjs`, part of tier 2: the search dialog in Chromium, Firefox and WebKit with the
  demo served under `/docs/` and its `.htaccess` headers (hotkeys, results, keyboard navigation, worker fallback).

### Fixed

- ⌘K and Ctrl+K also open search when the key arrives as uppercase "K" (Caps Lock, synthetic key events).

### Changed

- Search has its own ranking: title and phrase matches first, then a score over title, keywords,
  description, headings and text, weighted by word rarity and by how much of the query a page
  covers. Stopwords are dropped; prefix, compound, inflection and typo matching, umlaut and `ß`
  folding and hyphen, space and joined spellings are equivalent. Results are grouped per page,
  at most 8 pages with 3 headings each.
- The search index is a compact inverted index (format version 2) without body text; it loads
  on the first hover, focus or open of a search trigger, and queries run in a Web Worker.

### Removed

- The zbsearch notice, licence text and `verify/` dependency: the search no longer derives from zbsearch.

## [0.1.0] - 2026-09-13

The first public release: a static documentation generator in plain PHP that
reproduces the Notebook documentation theme, with the tooling that measures the
reproduction.

### Added

- **Command line.** `pholio build`, `pholio check` (diff a build against a directory in
  both directions) and `pholio dev` (build with drafts, serve with PHP's built-in
  server, rebuild on changes), with `--config`, `--profile`, `--content`, `--out`,
  `--only`, `--dev` and `--set`, and documented exit codes.
- **Configuration.** One PHP file returning a plain array, validated and normalised
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
  in the browser with zbsearch's parameters, with English and German tokenisers.
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
  golden DOM, computed styles, pixel diff and behaviour against a reference
  export, plus search and lucide oracles.
- Documentation in `docs/`, written in Pholio's own format, and the licences and
  notices of all vendored material.

[Unreleased]: https://github.com/luka-loehr/pholio/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/luka-loehr/pholio/releases/tag/v0.1.0
