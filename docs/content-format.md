---
title: Content format
description: The Markdown Pholio accepts, the component tags it understands and the meta.json tree files.
icon: file-text
---

Pholio parses a fixed grammar. There is no fallback: an unknown construct stops
the build with exit code 3 and a message naming the file and the line, for
example `pholio: content/guide/index.md:12: Unknown component <Tip>; allowed: …`.

## Files and URLs

Every `*.md` file below `content_dir` is a page (`content.extensions` changes the
list). `guide/installation.md` is published at `{docs}/guide/installation`,
`guide/index.md` at `{docs}/guide`. URLs have no trailing slash. Files whose name
starts with `_` are drafts and only built with `--dev`.

## Frontmatter

Every file starts with frontmatter. Values are plain scalars; YAML lists,
objects and anchors are errors, and so is any key outside this table
(`content.frontmatter_aliases` maps other names onto these).

| Key | Required | Meaning |
| --- | --- | --- |
| `title` | yes | Page title, used in the tree, the breadcrumb and `<title>` |
| `description` | no | Shown under the heading and indexed for search |
| `keywords` | no | Search terms the page text does not use, such as synonyms or English words: a comma-separated string (`keywords: "herunterladen, download, dark mode"`) or a list of strings (`keywords: [herunterladen, "dark mode"]`, or `- herunterladen` lines below the key); both index the same. Anything else is an error. Only indexed for search, weighted like the title |
| `heading` | no | Visible `h1` when it should differ from `title` |
| `icon` | no | lucide icon name, used in the sidebar and in cards |
| `full` | no | `true` renders the page without the table of contents column |
| `updated` | no | Text of the "Last updated" line under the page |

## Markdown

- Headings `##` to `####`. The `h1` comes from the frontmatter, so `#` in the body
  is an error. Heading ids follow `github-slugger`: lowercase, punctuation
  removed, spaces to hyphens, non-ASCII letters kept, duplicates suffixed `-1`,
  `-2`.
- Paragraphs, `**bold**`, `*italic*`, `~~strikethrough~~`, `` `code` ``, links,
  images, blockquotes, horizontal rules, hard breaks from two trailing spaces.
- Ordered, unordered and nested lists, task lists `- [x]`.
- GitHub-flavoured tables with column alignment.
- Footnotes `[^1]` with their definitions.

A plain Markdown image renders as a single bordered image, with its width and
height read from the file. Put images into the project's `assets/` folder and
reference them either by their published URL, `/assets/images/diagram.png`, or
relative to the page, `../assets/images/diagram.png`. Both publish as
`/assets/images/diagram.png`. A relative path that points outside a copied
directory stops the build. Sites with their own layout keep using `copy` and
`content.asset_prefix`, `content.asset_target` and `content.asset_root`.

## Code blocks

Fenced code is highlighted at build time with the bundled TextMate grammars and
GitHub light and dark themes: `css`, `diff`, `html`, `java`, `javascript` (`js`),
`json`, `php`, `shellscript` (`bash`, `sh`), `sql`, `typescript` (`ts`), `xml` and
`yaml`. Any other language stops the build.

````text
```ts title="search.ts" lineNumbers
const hits = await archive.search('invoice'); // [!code highlight]
```
````

| Meta | Effect |
| --- | --- |
| `title="…"` | File name bar above the code |
| `lineNumbers`, `lineNumbers=40` | Line numbers, optionally starting at another value |
| `tab="…"` | Consecutive blocks with `tab` are merged into one tab group |
| `{1,3-4}` | Accepted for compatibility, no effect |

Notation comments at the end of a line, in the comment syntax of the language:
`[!code highlight]`, `[!code focus]`, `[!code ++]`, `[!code --]`, and
`[!code word:term]` on its own line to highlight a word in the lines below.

## Component tags

Tags sit on their own line, attributes are quoted, and nesting works as in HTML.
They are data: no expressions, no imports, no evaluation. Attribute values have
four notations:

- text: `title="Before you start"`
- list: `items="macOS|Linux|Windows"`, a literal `|` written as `\|`
- boolean: `persist` for true, `persist="false"` for false
- number: `defaultIndex="1"`

Icons are lucide names such as `book-open`; an unknown name stops the build.

| Tag | Attributes | Children |
| --- | --- | --- |
| `Callout` | `type` (`info`, `warning`, `error`, `success`, `idea`; aliases `warn`, `tip`), `title`, `icon` | Markdown |
| `Cards` | none | `Card` |
| `Card` | `title` (required), `description`, `href`, `icon` | none |
| `Screenshot` | `src` (required), `dark`, `alt` | none |
| `ImageZoom` | `src` (required), `alt`, `width`, `height` | none |
| `Banner` | `id`, `variant` (`normal`, `rainbow`), `height`, `changeLayout` | Markdown |
| `Tabs` | `items`, `groupId`, `persist`, `updateAnchor`, `defaultIndex`, `label` | `Tab` |
| `Tab` | `value` | Markdown |
| `Accordions` | `type` (`single`, `multiple`), `defaultValue` | `Accordion` |
| `Accordion` | `title` (required), `id`, `value` | Markdown |
| `Steps` | none | `Step` |
| `Step` | none | Markdown |
| `Files` | none | `Folder`, `File` |
| `Folder` | `name` (required), `defaultOpen`, `disabled` | `Folder`, `File` |
| `File` | `name` (required), `icon` | none |
| `TypeTable` | none | `TypeProp` |
| `TypeProp` | `name`, `type` (both required), `default`, `typeDescription`, `typeDescriptionLink`, `required`, `deprecated` | Markdown |
| `InlineTOC` | `label` | none |
| `DynamicCodeBlock` | `lang` (required) | verbatim code |

```text
<Callout type="warning" title="Before you start">
Back up your configuration.
</Callout>

<Screenshot src="/images/pipeline.svg" dark="/images/pipeline-dark.svg" alt="The pipeline" />

<Tabs items="macOS|Linux" groupId="os" persist>
<Tab value="macOS">
Archives live in `~/Library/Application Support`.
</Tab>
<Tab value="Linux">
Archives live in `~/.local/share`.
</Tab>
</Tabs>
```

`Screenshot` shows `dark` in the dark colour scheme and `src` in the light one.
Without `dark` the same image is shown in both.

## meta.json

A folder's `meta.json` names the folder and orders its pages. It is optional; a
folder without one lists its pages alphabetically.

| Key | Meaning |
| --- | --- |
| `title`, `description`, `icon` | Folder title, description and icon, used in the sidebar and by `home.cards.from_tree` |
| `root` | The folder is a root area with its own sidebar, listed in the area switcher |
| `pages` | Order of the entries, see below |
| `defaultOpen` | The folder starts expanded in the sidebar |
| `collapsible` | `false` keeps the folder permanently expanded |

Entries in `pages`:

- `"installation"`: a page or subfolder by its file or folder name
- `"---Components---"`, `"---[icon]Components---"`: a separator with an optional icon
- `"..."`: every remaining entry alphabetically, `"z...a"` in reverse
- `"...folder"`: the entries of a subfolder, inlined
- `"[Title](https://example.org)"`, `"external:[Title](https://example.org)"`: a link

## What stops the build

Exit code 3 with file and line: unknown frontmatter keys or YAML constructs, a
`#` heading, unknown component tags or attributes, a tag inside a parent it
doesn't belong to, unknown lucide icons, unknown code languages, invalid
`meta.json`, duplicate slugs and a folder group name used as a file name.

Not checked yet: whether an internal link points at an existing page, and
whether an image file exists. A missing image renders without width and height.
