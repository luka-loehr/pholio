---
title: Pholio
description: Beautiful documentation, powered by Markdown. One polished theme, fast search, zero dependencies.
icon: book-open
---

Pholio turns a folder of Markdown files into a finished documentation site:
static HTML, one stylesheet, a few small JavaScript modules, the fonts and a
search index. No Composer, no npm, no framework, no build server. PHP runs once
at build time and is never needed again.

There is one theme, and it is polished down to the details: light and dark
schemes, a sidebar with collapsible folders, a table of contents that follows
you, keyboard shortcuts and instant search. You write Markdown; Pholio takes
care of the rest.

<Callout type="info" title="Status">
Pholio 0.2.0 builds complete documentation sites. The content format and the
configuration schema are settled; what comes next is on the
[roadmap](/docs/roadmap).
</Callout>

## What you get

<Cards>
  <Card title="Markdown in, HTML out" description="A strict Markdown subset plus a few declarative component tags. Anything unrecognised stops the build." href="/docs/content-format" icon="file-text" />
  <Card title="One configuration file" description="Title, logo, navigation, start page, palette, search, redirects, profiles. Plain PHP data, validated before the build." href="/docs/configuration" icon="settings" />
  <Card title="Zero dependencies" description="Vendored fonts, icons and grammars with their licences. Nothing to install, nothing to update." href="/docs/architecture" icon="package" />
  <Card title="Tested, not assumed" description="PHP tests, a committed demo snapshot and browser checks for DOM, styles, pixels and behaviour." href="/docs/verification" icon="check-circle" />
</Cards>

## What it deliberately is not

Pholio does not read your source code. There is no docblock extraction, no API
reference generation, no expression evaluation in content. Your documentation
is written by you; Pholio renders it.

It is also not MDX. Component tags are data, not programs: they carry
attributes, they nest, and they can't import or execute anything. New tags are
registered in PHP, where code belongs.
