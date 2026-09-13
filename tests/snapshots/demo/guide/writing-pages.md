> Documentation index: https://lanternfly.example/llms.txt, a list of every page in this documentation.

# Writing pages

> Frontmatter, headings, links and images in the Markdown subset Pholio accepts.

A page is a Markdown file with frontmatter. The title comes from the
frontmatter, so the body starts at `##`.

## Frontmatter

```yaml title="guide/installation.md"
---
title: Installation
description: Install Lanternfly and check that it runs.
icon: download
---
```

## Headings

Use `##` for sections and `###` for subsections. Headings become anchors and
entries in the table of contents on the right.

### A subsection

Ids are derived from the text, so this heading is reachable as
`#a-subsection`.

#### A fourth level

Allowed, but rarely a good idea.

## Links

Link to another page with a relative path, like
[Callouts and cards](https://lanternfly.example/guide/callouts-and-cards.md), or to the
[reference section](https://lanternfly.example/reference.md). Broken internal links stop the build.
External links such as [the CommonMark spec](https://spec.commonmark.org)
open in a new tab.

## Images

A plain Markdown image renders as a single bordered picture:

![An indigo colour scale from 50 to 950](https://lanternfly.example/images/color-scale.svg)

For light and dark variants, use `Screenshot`. For images worth enlarging, use
`ImageZoom`:

![An indigo colour scale from 50 to 950](https://lanternfly.example/images/color-scale.svg)

## Quotes

> Write the page you wish you had found the first time.

## Related topics

- [Guide](https://lanternfly.example/guide.md)
- [Installation](https://lanternfly.example/guide/installation.md)
- [Callouts and cards](https://lanternfly.example/guide/callouts-and-cards.md)
- [Tabs and accordions](https://lanternfly.example/guide/tabs-and-accordions.md)
- [Steps and files](https://lanternfly.example/guide/steps-and-files.md)
- Previous: [Installation](https://lanternfly.example/guide/installation.md)
- Next: [Callouts and cards](https://lanternfly.example/guide/callouts-and-cards.md)
