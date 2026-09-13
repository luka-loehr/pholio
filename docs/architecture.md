---
title: Architecture
description: How a folder of Markdown becomes a static site, and why each stage exists.
icon: layers
---

## The pipeline

```
pholio.config.php     Config.php: validate, apply defaults and profile, normalise
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
licence files: the Inter font, the lucide icon paths, the Shiki grammars and
themes, the values derived from the compiled Tailwind output and the search
tokenizer and ranking ported from zbsearch. `verify/` may use Node and
Playwright because it is never deployed.

**Fail loud.** Unknown Markdown, an unknown component, attribute, icon or code
language, an invalid `meta.json`, a duplicate slug: each one stops the build with
the file and line. There is no silent fallback, because a documentation site
that renders something wrong is worse than one that refuses to build.

**Search without a backend.** The index is built at compile time and shipped as
JSON. The browser tokenizes and ranks with the same parameters as the
reference, so results and their order match. The cost is one download the first
time the dialog opens.

**Configuration is data.** One array, validated against a schema before
anything runs. Components never read the public keys; they read the normalised
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
| `theme/js/` | ES modules, one per behaviour |
| `theme/fonts/` | Inter |
| `vendor-data/` | Shiki grammars and themes, lucide icon data |
| `licenses/` | Licence texts of the vendored material, copied into every build |
| `verify/` | Node comparison tooling, never shipped |
| `tests/` | PHP tests, fixtures and the demo snapshot |
| `docs/` | This documentation, in Pholio's content format |
| `examples/demo/` | A demo site covering every component |
| `scripts/` | Local checks, run by hand |

## Known limits

Next.js swaps pages without reloading; a static site reloads. Every navigation
is a full page load, softened by restoring the sidebar's scroll position and
open folders from `sessionStorage` before the first paint.
