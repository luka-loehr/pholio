---
title: Tabs and accordions
description: Tabs with a default, grouped tabs that stay in sync, and single or multiple accordions.
icon: panels-top-left
---

## Tabs

`items` lists the labels, separated by `|`. Each `Tab` takes its `value` from
the matching label. With `updateAnchor`, choosing a tab also updates the URL
hash, so the link you copy opens that tab.

<Tabs items="macOS|Linux|Windows" updateAnchor>
<Tab value="macOS">
Archives live in `~/Library/Application Support/Lanternfly`.
</Tab>
<Tab value="Linux">
Archives live in `~/.local/share/lanternfly`.
</Tab>
<Tab value="Windows">
Archives live in `%APPDATA%\Lanternfly`.
</Tab>
</Tabs>

### Choosing the default

<Tabs items="Monthly|Yearly" defaultIndex="1">
<Tab value="Monthly">
Pay each month, cancel any time.
</Tab>
<Tab value="Yearly">
Pay once a year and get two months free.
</Tab>
</Tabs>

### Grouped tabs

Tabs with the same `groupId` switch together. With `persist`, the choice is
remembered across pages and visits.

<Tabs groupId="language" items="JavaScript|PHP" persist>
<Tab value="JavaScript">
```js
import { open } from 'lanternfly';
const archive = await open('notes');
```
</Tab>
<Tab value="PHP">
```php
<?php
$archive = Lanternfly\open('notes');
```
</Tab>
</Tabs>

<Tabs groupId="language" items="JavaScript|PHP" persist>
<Tab value="JavaScript">
```js
const hits = await archive.search('invoice');
```
</Tab>
<Tab value="PHP">
```php
<?php
$hits = $archive->search('invoice');
```
</Tab>
</Tabs>

A label that itself contains a pipe is escaped: `items="a\|b|c"` yields the two
tabs "a|b" and "c".

## Accordions

### Single

Only one item is open at a time.

<Accordions type="single">
<Accordion title="Is Lanternfly free?">
Yes, for personal use.
</Accordion>
<Accordion title="Does it sync?">
No. Archives are plain folders; use whatever sync tool you already trust.
</Accordion>
<Accordion title="Can I export everything?" id="export-all">
Yes. `lanternfly export --all` writes one Markdown file per note.
</Accordion>
</Accordions>

### Multiple

Any number of items can be open.

<Accordions type="multiple">
<Accordion title="Keyboard shortcuts">
`/` focuses search, `n` creates a note, `Esc` closes any dialog.
</Accordion>
<Accordion title="File formats">
Markdown, plain text and PDF are indexed. Everything else is stored but not
searched.
</Accordion>
</Accordions>
