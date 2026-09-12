---
title: Callouts and cards
description: Every callout type and the card grid, with and without icons and links.
icon: layout-grid
---

## Callouts

A callout has a `type` and an optional `title`. Its body is ordinary Markdown.

<Callout>
No type means `info`. This one has no title either.
</Callout>

<Callout type="info" title="Info">
Lanternfly indexes files in the background, so the first search after a large
import may be slow.
</Callout>

<Callout type="tip" title="Tip">
`tip` is an alias for `info`. Press `/` in the archive view to jump to search.
</Callout>

<Callout type="warning" title="Warning">
Moving an archive while it is being indexed can leave the index incomplete.
</Callout>

<Callout type="warn" title="Warn">
`warn` is an alias for `warning`.
</Callout>

<Callout type="error" title="Error">
`lanternfly purge` deletes archives permanently. There is no undo.
</Callout>

<Callout type="success" title="Success">
When indexing finishes, the status bar shows a green check.
</Callout>

<Callout type="idea" title="Idea">
Keep one archive per project. Searches stay fast and exports stay small.
</Callout>

## Cards

Cards group related links. Each card can carry a lucide icon.

<Cards>
  <Card title="Archives" description="Create, open and move archives." href="/guide/steps-and-files" icon="archive" />
  <Card title="Search" description="Query syntax and filters." href="/reference/markdown-extras" icon="search" />
  <Card title="Configuration" description="Every option, typed." href="/reference/api-types" icon="settings" />
  <Card title="Command line" description="Code examples for every command." href="/reference/code-blocks" icon="terminal" />
</Cards>

### Without links or icons

A card without `href` is static, which is useful for short summaries.

<Cards>
  <Card title="Fast" description="Indexes ten thousand notes in under a second." />
  <Card title="Local" description="Nothing leaves your machine." />
</Cards>
