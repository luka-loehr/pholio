---
title: Architecture
description: How a folder of Markdown becomes a static site, and why each stage exists.
icon: layers
---

## The pipeline

```
content/**            Markdown files and one meta.json per folder
  |
  v
lib/Markdown.php      strict parser  ->  AST
lib/Tree.php          meta.json      ->  page tree, tabs, breadcrumbs, prev/next
lib/Slug.php          headings       ->  stable ids
lib/Toc.php           headings       ->  table of contents
lib/SearchIndex.php   blocks         ->  index documents
  |
  v
components/*.php      pure functions, one file per component
templates/document.php  the html shell
  |
  v
output_dir/**         index.html per page, search-index.json, .htaccess
```

Every stage is a pure transformation with one input and one output. Nothing
reads the filesystem except the first stage and nothing writes it except the
last, which is why `--check` can rebuild into a temporary directory and diff
the result byte for byte.

## Why it is shaped this way

**PHP at build time only.** The output is static HTML served by any web server.
The components are still pure functions, so the same code could render at
request time if a project ever needs it, but nothing in the design assumes a
PHP runtime in production.

**No dependencies.** The only external ingredients are vendored assets with
their licence files: the Inter font, the lucide icon paths, the values derived
from the compiled Tailwind output, and the search ranking ported from
zbsearch. `verify/` may use Node and Playwright because it is never deployed.

**Fail loud.** Unknown Markdown, a missing screenshot, a missing dark twin, a
broken internal link, a duplicate slug, a `meta.json` entry without a file:
each one stops the build. There is no silent fallback, because a documentation
site that renders something wrong is worse than one that refuses to build.

**Search without a backend.** The index is built at compile time and shipped as
JSON. The client tokenizes and ranks in the browser with the same BM25
parameters as the reference, so results and their order match exactly. The cost
is one download the first time the dialog opens.

## Repository layout

| Path | Contents |
| --- | --- |
| `bin/pholio` | CLI entry point |
| `src/lib/` | Parser, tree, slugs, table of contents, search index, HTML helpers |
| `src/components/` | One PHP file per component, pure functions |
| `src/templates/` | The document shell |
| `theme/css/` | `notebook.css`, hand-written in cascade order |
| `theme/js/` | ES modules, one per behaviour, concatenated at build time |
| `theme/fonts/`, `theme/icons/` | Vendored assets |
| `theme/LICENSES/` | One licence file per vendored asset |
| `verify/` | Node development tooling, never shipped |
| `docs/` | This documentation, built with Pholio |
| `examples/demo/` | A runnable demo site covering every component |
| `scripts/` | Local checks, run by hand |

## Known limits

Next.js swaps pages without reloading; a static site reloads. The gap is closed
with speculation-rules prerendering on hover, cross-document view transitions,
and restoring sidebar scroll position and open folders from `sessionStorage`
before the first paint. In browsers without view transitions the navigation is
a hard load, and that is the one visible difference.
