![Pholio banner](docs/assets/banner.svg)

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg?style=flat)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.2-777bb4.svg?style=flat)](https://www.php.net/releases/8.2/en.php)
[![Dependencies](https://img.shields.io/badge/dependencies-none-success.svg?style=flat)](#2-system)
[![Status](https://img.shields.io/badge/status-pre--release-orange.svg?style=flat)](docs/roadmap.md)

**Beautiful PHP documentation, generated from your code.**

I wanted a documentation site that looks and behaves exactly like the Fumadocs
Notebook theme, without Node, npm, Composer or a framework in the repository.
So I rebuilt the theme in plain PHP, CSS and JavaScript, and then I wrote the
tooling that proves the rebuild is the same page rather than a resemblance: the
same element tree, the same computed styles, the same pixels, the same keyboard
behaviour. It was built for a real documentation site first, and Pholio is that
generator made to stand on its own.

> **Pre-release.** The skeleton is here; the generator itself is being moved in
> from the project it was written for, so `php bin/pholio build` exits with
> "not yet wired" for now. The content format and the configuration schema are
> settled. See [`docs/roadmap.md`](docs/roadmap.md).

## 1. What it does

Point Pholio at a folder of Markdown files and one configuration file. It
writes a finished static site: one HTML file per page, one stylesheet, one
JavaScript module, the fonts, the icons, a search index, and the redirect rules
for whatever URLs you are leaving behind. Deploy it by copying the files.

- **Markdown plus component tags.** A strict subset of Markdown and a small set
  of declarative tags: `Callout`, `Cards`, `Card`, `Screenshot`, with a larger
  catalogue planned. Tags carry attributes and nest. They never import or
  evaluate anything.
- **No dependencies.** No Composer, no npm, nothing to install. The only
  external ingredients are vendored assets that ship with their licences.
- **PHP at build time only.** The output is static HTML. No PHP runs in
  production.
- **Fail loud.** An unknown construct, a broken internal link, a missing dark
  image twin or a duplicate slug stops the build. There is no silent fallback.
- **Search without a backend.** The index is built at compile time; ranking runs
  in the browser with the same parameters as the reference, so the results and
  their order match.

## 2. System

Markdown goes in, static HTML comes out, and the output is held to a standard
that can be measured.

The target is the Fumadocs Notebook theme, frozen once as a reference build.
Being "the same" then means four things, checked in order, all of which must be
green:

| Stage | What is compared |
| --- | --- |
| **Golden DOM** | Element tree and every attribute, after normalising framework noise and mapping class names through a translation table |
| **Computed styles** | About 70 CSS properties per element, at three widths, light and dark, including hovered and focused states |
| **Pixels** | Full-page screenshots per page, width and scheme, zero tolerance outside a text-antialiasing mask, including open dialogs, drawers and popovers |
| **Behaviour** | Attribute order frame by frame while animating, `getAnimations()` keyframes, focus order, hotkeys, scroll locking, and the result lists for a fixed query set |

That is the whole idea of the project: the resemblance is not asserted in a
README, it is a diff that either is empty or is not.

## 3. Architecture

```
 content/**                 Markdown + one meta.json per folder
     |
     v
 +-------------------------------------------------------------+
 |  parser        lib/Markdown.php     strict grammar -> AST    |
 |  tree          lib/Tree.php         meta.json -> page tree   |
 |  slugs/toc     lib/Slug.php Toc.php headings -> ids, toc     |
 |  index         lib/SearchIndex.php  blocks -> search docs    |
 +-------------------------------------------------------------+
     |
     v
 +-------------------------------------------------------------+
 |  components    components/*.php     pure functions           |
 |  templates     templates/document.php   the html shell       |
 +-------------------------------------------------------------+
     |
     v
 output_dir/**              index.html per page, search-index.json, .htaccess
```

Each stage is a pure transformation. Only the first stage reads the filesystem
and only the last writes it, which is what makes `--check` possible: rebuild
into a temporary directory, diff, refuse anything stale.

| Path | Contents |
| --- | --- |
| `bin/pholio` | CLI entry point |
| `src/` | The generator: `lib/`, `components/`, `templates/` |
| `theme/` | `css/`, `js/`, `fonts/`, `icons/`, `LICENSES/` |
| `verify/` | Node development tooling for the four stages, never shipped |
| `docs/` | This project's own documentation, written in Pholio's format |
| `docs/assets/` | Banner and other repository images |
| `examples/demo/` | A demo site covering every component |
| `scripts/` | Local checks, run by hand |
| `pholio.config.example.php` | The documented configuration schema |

`src/`, `theme/` and `verify/` currently hold only a
README describing what lands there. `examples/demo/` is complete content that
waits only for the generator.

## 4. Requirements

| Requirement | Why |
| --- | --- |
| **PHP 8.2 or newer**, CLI | The generator runs once per build. Nothing runs on the server |
| **PCRE2 10.43 or newer** (bundled with PHP; check with `php -r 'echo PCRE_VERSION;'`) | The syntax highlighter runs TextMate grammars, whose patterns are written for Oniguruma. Pholio translates them to PCRE2. Some of them use lookbehinds of variable length, which PCRE2 supports only from 10.43. On older versions those few patterns are switched off with a warning, and highlighting no longer matches the reference exactly |
| **`mbstring` and `ctype`** | Unicode-aware slugs, case folding and tokenisation. Both are enabled in almost every PHP build |

That is the complete list: no Composer, no npm, no network access during a
build. Node is needed only for the optional comparison tooling in `verify/`.

## 5. Credits

Pholio stands on other people's work. It reproduces the
[Fumadocs](https://github.com/fuma-nama/fumadocs) Notebook theme and follows the
state-attribute contract of [Base UI](https://github.com/mui/base-ui). Its CSS
values come from [Tailwind CSS](https://github.com/tailwindlabs/tailwindcss)
output, and its search ranking follows
[zbsearch](https://github.com/micheleriva/zbsearch). It ships
[Inter](https://github.com/rsms/inter), [lucide](https://github.com/lucide-icons/lucide)
icons, and TextMate grammars and GitHub themes as packaged by
[Shiki](https://github.com/shikijs/shiki).

Exact versions, upstream commits, copyright holders and the verbatim licence
texts are in [`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md) and
[`licenses/`](licenses/).

## 6. Quickstart

The fastest way to see the format is the demo site in
[`examples/demo/`](examples/demo/): ten pages documenting a fictional tool,
covering every component and Markdown extra. Once the generator is wired it
builds with:

```bash
cd examples/demo
php ../../bin/pholio build --config pholio.config.php
php -S localhost:8080 -t out
```

For your own project:

```bash
# 1. Bring Pholio into your project
git clone https://github.com/luka-loehr/pholio.git vendor/pholio

# 2. Start from the documented schema
cp vendor/pholio/pholio.config.example.php docs.config.php

# 3. Build
php vendor/pholio/bin/pholio build --config docs.config.php
```

| Flag | Effect |
| --- | --- |
| `--config <file>` | Configuration file to use |
| `--check` | Build into a temporary directory and diff against the output directory; any difference is an error |
| `--dev` | Also build the component showcase page |
| `--only <slug>` | Build a single page, for iteration |

Requirements are in [section 4](#4-requirements).

## 7. Content format

Frontmatter with `title`, optional `description`, `heading`, `icon` and `full`.
Then a fixed Markdown subset: `##` to `####`, paragraphs, bold, italic, inline
code, links, images, nested lists, GitHub tables with alignment, blockquotes,
rules, and hard breaks from two trailing spaces. Heading ids follow
`github-slugger`, duplicates included. Relative links between articles are
resolved and verified at build time.

Component tags sit on their own line with quoted attributes:

```markdown
<Callout type="warning" title="Before you start">
Back up your configuration.
</Callout>

<Cards>
  <Card title="Exporting" description="Save a conversation." href="/docs/export" icon="download" />
</Cards>

<Screenshot src="chat/export.webp" alt="The export dialog" />
```

| Tag | Attributes |
| --- | --- |
| `Callout` | `type` (`info`, `warning`, `error`, `success`, `idea`; `warn` and `tip` are aliases), `title` |
| `Cards` | none; a grid wrapper |
| `Card` | `title`, `description`, `href`, `icon` |
| `Screenshot` | `src`, `alt`, optional `dark`; the dark twin `<name>-dark.webp` is found automatically and a missing one is an error |

**Planned:** `Tabs`/`Tab`, `Accordions`/`Accordion`, `Steps`/`Step`,
`Files`/`Folder`/`File`, `TypeTable`, `Banner`, `InlineTOC`, `ImageZoom`, and
fenced code blocks with titles, line numbers and line highlighting.

Custom tags are registered in PHP through the `components` key. Content stays
data; code stays in PHP.

## 8. Configuration

One PHP file returning one array. Full reference in
[`docs/configuration.md`](docs/configuration.md); the example file carries the
same information as comments.

| Key | Default | Meaning |
| --- | --- | --- |
| `title` | required | Header wordmark, `<title>`, search dialog |
| `logo` | `null` | Logo path, rendered 24px and rounded |
| `base_url` | required | Absolute site URL, no trailing slash |
| `base_path` | `/` | Path prefix the site is served under |
| `asset_base` | `assets/` | Where CSS, JS, fonts and images are served from |
| `language` | `en` | `<html lang>`, UI translations, search tokenizer |
| `content_dir` | required | Markdown sources and `meta.json` files |
| `output_dir` | required | Where the static site is written |
| `nav` | `[]` | Header links: `title`, `href`, optional `active`, `icon`, `icon_only` |
| `home` | `null` | Start page: hero kicker, headline, lead, images, buttons, and the area cards |
| `theme.light`, `theme.dark` | built-in | Colour token overrides, without the `--color-fd-` prefix |
| `theme.default_scheme` | `system` | `light`, `dark` or `system` |
| `theme.custom_css` | `null` | Stylesheet appended after the generated CSS |
| `search.enabled` | `true` | Off removes the field, the hotkey and the index |
| `search.index_path` | `search-index.json` | Index location, relative to `output_dir` |
| `search.tokenizer` | follows `language` | `german` or `english`; neither stems |
| `redirects` | `[]` | Old path to new path, emitted as 301 rules |
| `slots` | `[]` | Slot overrides: `nav`, `sidebar.banner`, `sidebar.footer`, `toc.header`, `page.footer` (planned) |
| `components` | `[]` | Custom content tags (planned) |
| `strict_content` | `false` | Fail on pages without a description, headings or a body |

## 9. Decision record

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
  depend on that order. Copying a React runtime to get five behaviours was not
  a trade worth making.
- **2026-09-12 — Four-stage verification.** DOM, computed styles, pixels,
  behaviour. A checklist review of a theme rebuild finds the differences you
  thought to look for. A diff finds the others.
- **2026-09-12 — One file per commit.** Long history, but every change is
  readable and revertible on its own. It has already paid for itself while
  moving code between repositories with `git subtree split`.

## 10. Docs map

| Document | What it covers |
| --- | --- |
| [`docs/index.md`](docs/index.md) | What Pholio is and what it deliberately is not |
| [`docs/getting-started.md`](docs/getting-started.md) | Requirements, install, the first page, build flags |
| [`docs/content-format.md`](docs/content-format.md) | Frontmatter, the Markdown subset, every component tag |
| [`docs/configuration.md`](docs/configuration.md) | Every configuration key |
| [`docs/architecture.md`](docs/architecture.md) | The pipeline, the design rules, the known limits |
| [`docs/verification.md`](docs/verification.md) | The four stages and what each one catches |
| [`docs/roadmap.md`](docs/roadmap.md) | Current state, next steps, non-goals |
| [`examples/demo/`](examples/demo/README.md) | The demo site, its component coverage table and build instructions |
| [`docs/assets/og.png`](docs/assets/og.png) | 1200x630 social preview, source in `docs/assets/og.svg` |
| [`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md) | Every third-party component with version, licence, upstream commit and licence text |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | Commit rules, code rules, acceptance |
| [`src/README.md`](src/README.md), [`theme/README.md`](theme/README.md), [`verify/README.md`](verify/README.md) | What lands in each folder |

These pages are written in Pholio's own format and will be built with Pholio
itself once the generator is wired.

## 11. Security

Content is data, never a program. There are no expressions, no imports and no
evaluation in Markdown or in component tags; a tag is a name, some quoted
attributes and its children, and it is dispatched to a PHP function that
returns a string. A hostile content file can produce an ugly page. It cannot
execute anything.

Everything is escaped by default and only the parser's own output is trusted as
HTML. Links are classified by pattern, and external ones get
`rel="noreferrer noopener"`.

The parser fails loud. Unknown constructs, missing files, broken internal links
and duplicate slugs abort the build with the file and line, so a
misunderstanding surfaces at build time rather than as a wrong page in front of
a reader.

Pholio runs on your machine or your build host, reads your content directory
and writes your output directory. It makes no network requests and needs no
credentials.

## 12. License

Pholio is MIT licensed. See [`LICENSE`](LICENSE).

Third-party material keeps its own licence: ISC for lucide, SIL OFL 1.1 for
Inter, MIT for the grammars, themes, Fumadocs, Base UI and Tailwind CSS, and
Apache-2.0 for zbsearch. The full list with versions and texts is in
[`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md); the short version is in
[section 5](#5-credits).

Built by [Luka Löhr](https://github.com/luka-loehr).
