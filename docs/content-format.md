---
title: Content format
description: The Markdown subset Pholio accepts and the component tags it understands.
icon: file-text
---

Pholio parses a fixed grammar. There is no fallback: an unknown construct, a
missing image, a broken internal link or a duplicate slug stops the build with
a message naming the file and the line.

## Frontmatter

Every file starts with YAML frontmatter.

| Key | Required | Meaning |
| --- | --- | --- |
| `title` | yes | Page title, used in the tree, the breadcrumb and `<title>` |
| `description` | no | Shown under the heading and used in the search index |
| `heading` | no | Overrides the visible `h1` when it should differ from `title` |
| `icon` | no | A lucide icon name, used in cards and folder headers |
| `full` | no | Renders the page without the table of contents column |

## Markdown

Headings `##` to `####`. The `h1` comes from the frontmatter, so a `#` in the
body is an error. Beyond that: paragraphs, `**bold**`, `*italic*`, `` `code` ``,
links, images, ordered and unordered lists including nesting, GitHub-flavoured
tables with alignment, blockquotes, horizontal rules, and a hard line break
from two trailing spaces.

Relative links between articles are resolved and checked. A link to a page that
does not exist is a build error, not a 404 discovered later.

Heading ids follow `github-slugger`: lowercased, punctuation removed, spaces to
hyphens, non-ASCII letters preserved, duplicates suffixed `-1`, `-2`.

## Component tags

Tags sit on their own line, attributes are quoted, and nesting works as in HTML.
They are data: no expressions, no imports, no evaluation.

### Callout

<Callout type="info" title="Optional title">
The body is regular Markdown.
</Callout>

```markdown
<Callout type="info" title="Optional title">
The body is regular Markdown.
</Callout>
```

| Attribute | Values |
| --- | --- |
| `type` | `info`, `warning`, `error`, `success`, `idea`. `warn` and `tip` are accepted aliases. |
| `title` | Optional bold first line. |

### Cards and Card

```markdown
<Cards>
  <Card title="Exporting" description="Save a conversation." href="/docs/export" icon="download" />
</Cards>
```

`Card` takes `title`, `description`, `href` and `icon`. Without `href` it
renders as a static card rather than a link.

### Screenshot

```markdown
<Screenshot src="chat/export.webp" alt="The export dialog" />
```

The dark variant is found automatically next to the file as
`<name>-dark.webp`, and both are emitted so the image follows the colour
scheme without a flash. Pass `dark` explicitly to override. A missing dark twin
is a build error.

An image written as plain Markdown renders as a single bordered image with no
dark twin.

## Planned tags

These are specified and will be added in a later phase: `Tabs` and `Tab`,
`Accordions` and `Accordion`, `Steps` and `Step`, `Files`, `Folder` and `File`,
`TypeTable`, `Banner`, `InlineTOC`, `ImageZoom`, and fenced code blocks with a
title, line numbers and line highlighting.

## Registering your own

A tag is a PHP function returning HTML, registered under the `components` key
of the configuration. It receives the parsed attributes and the rendered
children, and it is the only place where code enters the content pipeline.
