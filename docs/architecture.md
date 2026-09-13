---
title: Architecture
description: How a folder of Markdown becomes a static site, and why each stage exists.
icon: layers
---

## The pipeline

```
pholio.config.php     Config.php: validate, apply defaults and profile, normalize
content/**            Markdown files and one meta.json per folder
  |
  v
lib/Tree.php          meta.json + frontmatter  ->  page tree, root areas, breadcrumbs, prev/next
lib/Markdown.php      strict parser            ->  AST
lib/Toc.php, Ids.php  headings                 ->  table of contents, stable ids
lib/Highlight.php     fenced code              ->  highlighted tokens (TextMate grammars)
lib/SearchIndex.php   blocks                   ->  index documents
  |
  v
lib/Render.php        AST                      ->  HTML, via the components
components/*.php      pure functions, one file per component
templates/document.php  the html shell
  |
  v
output_dir/**         index.html per page, search-index.json, theme assets, .htaccess
```

`Builder.php` drives the run and `Cli.php` maps its errors to exit codes. Every
stage is a pure transformation: nothing reads the filesystem except the tree,
the parser and the image sizes, and nothing writes it except the builder. That
is what lets `pholio check` render into a temporary directory and compare the
result with a committed build byte for byte.

## Why it is shaped this way

**PHP at build time only.** The output is static HTML served by any web server.
The components are pure functions, so the same code could render at request
time if a project ever needs it, but nothing in the design assumes a PHP
runtime in production.

**No dependencies.** The only external ingredients are vendored with their
license files: the Inter font, the lucide icon paths, the Shiki grammars and
themes and the values derived from the compiled Tailwind output. `verify/` may use Node and
Playwright because it is never deployed.

**Fail loud.** Unknown Markdown, an unknown component, attribute, icon or code
language, an invalid `meta.json`, a duplicate slug: each one stops the build with
the file and line. There is no silent fallback, because a documentation site
that renders something wrong is worse than one that refuses to build.

**Search without a backend.** The index is built at compile time and shipped as
compact JSON: an inverted index from normalized words to pages and sections,
without the body text. The browser loads it the first time a search trigger is
hovered, focused or opened and ranks in a Web Worker: title and phrase matches
first, then a score over fields (title, keywords, description, headings, text)
weighted by how rare each query word is and how much of the query a page covers,
with stopwords dropped and prefix, compound, inflection and typo matching. The
ranking weights are the `WEIGHTS` object in `theme/js/search.js`;
`node verify/search-query.mjs --explain` shows how a query was scored.

**Configuration is data.** One array, validated against a schema before
anything runs. Components never read the public keys; they read the normalized
internal shape documented in `src/Config.php`.

## Repository layout

| Path | Contents |
| --- | --- |
| `bin/pholio` | CLI entry point |
| `src/` | `Cli.php`, `Config.php`, `Builder.php`, `Htaccess.php`, `DevServer.php`, `Fs.php`, `I18n.php` |
| `src/lib/` | Parser, tree, ids, table of contents, highlighter, search index, rendering, HTML helpers |
| `src/components/` | One PHP file per component, pure functions |
| `src/templates/` | The document shell |
| `src/i18n/` | Interface strings: `en.php` (every key), `de.php` |
| `theme/css/` | `notebook.css` and its parts, hand-written in cascade order |
| `theme/js/` | ES modules, one per behavior |
| `theme/fonts/` | Inter |
| `vendor-data/` | Shiki grammars and themes, lucide icon data |
| `licenses/` | License texts of the vendored material, copied into every build |
| `verify/` | Node comparison tooling, never shipped |
| `tests/` | PHP tests, fixtures and the demo snapshot |
| `docs/` | This documentation, in Pholio's content format |
| `examples/demo/` | A demo site covering every component |
| `scripts/` | Local checks, run by hand |

## Known limits

Every navigation is a full page load, because the output is static HTML. It is
softened by restoring the sidebar's scroll position and open folders from
`sessionStorage` before the first paint.

## Decision record

- **2026-09-12 — The name is Pholio.** The generator stopped being an internal
  build step of one documentation site and became a product with its own
  repository, its own name and its own branding.
- **2026-09-12 — Markdown plus component tags, not MDX.** MDX buys expressions
  and imports, and pays with a compiler, a component runtime and content that
  can execute. Tags are data: attributes and nesting, nothing else. New tags are
  registered in PHP, where code is supposed to live.
- **2026-09-12 — No extraction from source code.** No docblock parsing, no API
  reference generation. Generated reference pages are the part of a
  documentation site nobody reads and everybody stops maintaining. Pholio
  renders what you wrote.
- **2026-09-12 — PHP at build time only.** The output is static files. The
  components are still pure functions and could render per request, but nothing
  in production depends on a PHP runtime.
- **2026-09-12 — Hand-written CSS derived from the compiled Tailwind output.**
  Keeping the 110 KB of compiled utility CSS would have been pixel-perfect on
  day one and unmaintainable on day two. Every value in the hand-written
  stylesheet comes from the compiled reference, and the computed-style diff is
  what keeps the claim honest.
- **2026-09-12 — Vanilla-JS ports of the Base UI primitives.** Dialog, popover,
  collapsible, scroll area and tabs are rebuilt as small classes that set the
  same state attributes in the same order, because the CSS and the animations
  depend on that order. Copying a React runtime to get five behaviors was not
  a trade worth making.
- **2026-09-12 — Four-stage verification.** DOM, computed styles, pixels,
  behavior. A checklist review of a theme rebuild finds the differences you
  thought to look for. A diff finds the others.
- **2026-09-12 — One file per commit.** Long history, but every change is
  readable and revertible on its own. It has already paid for itself while
  moving code between repositories with `git subtree split`.
