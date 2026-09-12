# `examples/demo/` — every component on one small site

A neutral demo site in Pholio's content format. It documents **Lanternfly**, a
fictional command-line tool, because a component catalogue is easier to judge
on plausible prose than on "lorem ipsum". Lanternfly does not exist.

## What is in it

| Path | Contents |
| --- | --- |
| `pholio.config.php` | Configuration: title, logo, nav, start page hero and cards, palette, search, one redirect |
| `content/meta.json` | Two root areas, Guide and Reference |
| `content/guide/` | Overview, installation, writing pages, callouts and cards, tabs and accordions, steps and files |
| `content/reference/` | Overview, code blocks, API types, Markdown extras |
| `assets/` | Self-made SVGs only: logo, hero light and dark, a pipeline diagram with a dark twin, a colour scale |

## Component coverage

| Component | Page |
| --- | --- |
| `Callout`, every type and alias | `guide/callouts-and-cards.md` |
| `Cards` / `Card`, with and without icons and links | `guide/callouts-and-cards.md`, `guide/index.md`, `reference/index.md` |
| `Banner`, rainbow variant | `guide/index.md` |
| `Screenshot` with dark twin | `guide/index.md` |
| `ImageZoom`, plain Markdown image, blockquote | `guide/writing-pages.md` |
| `InlineTOC`, `Steps` / `Step`, code tabs | `guide/installation.md` |
| `Tabs` / `Tab` with `defaultIndex`, `groupId`, `persist`; `Accordions` single and multiple | `guide/tabs-and-accordions.md` |
| `Files` / `Folder` / `File` | `guide/steps-and-files.md` |
| Code blocks: title, `lineNumbers`, notation comments, code tabs, `DynamicCodeBlock` | `reference/code-blocks.md` |
| `TypeTable` / `TypeProp` with `required`, `default`, `typeDescription`, `deprecated` | `reference/api-types.md` |
| Aligned tables, task lists, footnotes, strikethrough, hard breaks, nested lists | `reference/markdown-extras.md` |

## Building it

The generator has not been moved into this repository yet, so this does not
work today. Once it has:

```bash
cd examples/demo
php ../../bin/pholio build --config pholio.config.php
php -S localhost:8080 -t out
```

Then open <http://localhost:8080>. The config sets `strict_content` on, so a
page without a description or a broken internal link fails the build. This
site is meant to pass that check and to exercise every tag the parser knows.

## Assumptions to confirm when the generator lands

- `Screenshot` finds a dark twin as `<name>-dark.<ext>` for any extension,
  not only `.webp`.
- Asset paths in `Screenshot` and `ImageZoom` resolve against `assets/`;
  plain Markdown images resolve relative to the page file.
- `Accordions type` and `Folder defaultOpen` follow Fumadocs' prop names.
