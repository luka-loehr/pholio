# `examples/demo/` — every component on one small site

A neutral demo site in Pholio's content format. It documents **Lanternfly**, a
fictional command-line tool, because a component catalog is easier to judge
on plausible prose than on "lorem ipsum". Lanternfly does not exist.

## What is in it

| Path | Contents |
| --- | --- |
| `pholio.config.php` | Configuration: title, logo, nav, start page hero and cards, palette, search, one redirect; images published under `/images` |
| `content/meta.json` | Two root areas, Guide and Reference |
| `content/guide/` | Overview, installation, writing pages, callouts and cards, tabs and accordions, steps and files |
| `content/reference/` | Overview, code blocks, API types, Markdown extras |
| `assets/` | Self-made SVGs only: logo, hero light and dark, a pipeline diagram with a dark twin, a color scale |

## Component coverage

| Component | Page |
| --- | --- |
| `Callout`, every type and alias | `guide/callouts-and-cards.md` |
| `Cards` / `Card`, with and without icons and links | `guide/callouts-and-cards.md`, `guide/index.md`, `reference/index.md` |
| `Banner`, rainbow variant, `changeLayout="false"` | `guide/index.md` |
| `Screenshot` with dark twin | `guide/index.md` |
| `ImageZoom`, plain Markdown image, blockquote | `guide/writing-pages.md` |
| `InlineTOC`, `Steps` / `Step`, code tabs, `updated` frontmatter | `guide/installation.md` |
| `Tabs` / `Tab` with `updateAnchor`, `defaultIndex`, `groupId`, `persist`; `Accordions` single and multiple | `guide/tabs-and-accordions.md` |
| `Files` / `Folder` / `File` | `guide/steps-and-files.md` |
| Code blocks: title, `lineNumbers`, notation comments, code tabs, `DynamicCodeBlock` | `reference/code-blocks.md` |
| `TypeTable` / `TypeProp` with `required`, `default`, `typeDescription`, `deprecated` | `reference/api-types.md` |
| Aligned tables, task lists, footnotes, strikethrough, hard breaks, nested lists | `reference/markdown-extras.md` |

## Building it

From the repository root:

```bash
php bin/pholio build --config examples/demo/pholio.config.php
./scripts/serve-demo.sh
```

The script runs `pholio dev`: it builds with drafts, serves
<http://127.0.0.1:8080> and rebuilds when the content or the config changes. The
output directory `examples/demo/out` is ignored by git.

Nav and button hrefs are written without a trailing slash (`/guide`), because
generated URLs have none. `tests/PipelineTest.php` asserts that every nav href is
a generated page, and that the dark twin, image sizes, accordion types and open
folders come out as the content expects. `tests/snapshots/demo` holds the
committed build.

Code fences use only the bundled grammars: css, diff, html, java, javascript
(`js`), json, php, shellscript (`bash`, `sh`), sql, typescript (`ts`), xml and
yaml. Any other language fails the build.
