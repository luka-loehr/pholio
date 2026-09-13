---
title: Pholio
description: A dependency-free PHP generator that turns Markdown into a documentation site indistinguishable from the Fumadocs Notebook theme.
icon: book-open
---

Pholio takes a folder of Markdown files and writes a finished documentation
site: static HTML, one stylesheet, a set of JavaScript modules, the fonts and a search index. No
Composer, no npm, no framework, no build server. PHP runs at build time and is
never needed again.

The look is not "inspired by" Fumadocs' Notebook theme. It is the same page:
the same element tree, the same computed styles, the same animations, the same
keyboard behaviour, proven by a diff rather than claimed in a sentence.

<Callout type="info" title="Status">
Pholio is pre-release. The generator builds its demo site, and the content
format and configuration schema are settled; the first version is not tagged
yet. See the [roadmap](/docs/roadmap).
</Callout>

## What you get

<Cards>
  <Card title="Markdown in, HTML out" description="A strict Markdown subset plus declarative component tags. Anything unrecognised aborts the build." href="/docs/content-format" icon="file-text" />
  <Card title="One configuration file" description="Title, logo, navigation, start page, palette, search, redirects, profiles. Plain PHP data, validated before the build." href="/docs/configuration" icon="settings" />
  <Card title="Zero dependencies" description="Vendored fonts, icons and grammars with their licences. Nothing to install, nothing to update." href="/docs/architecture" icon="package" />
  <Card title="Proven, not promised" description="Golden DOM, computed styles, pixels and behaviour, all compared against the reference." href="/docs/verification" icon="check-circle" />
</Cards>

## What it deliberately is not

Pholio does not read your source code. There is no docblock extraction, no API
reference generation, no expression evaluation in content. Your documentation
is written by you; Pholio only renders it.

It is also not MDX. Component tags are data, not programs: they carry
attributes, they nest, and they cannot import or execute anything. New tags are
registered in PHP, where code belongs.
