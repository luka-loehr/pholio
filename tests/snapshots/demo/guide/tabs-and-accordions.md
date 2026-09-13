> Documentation index: https://lanternfly.example/llms.txt, a list of every page in this documentation.

# Tabs and accordions

> Tabs with a default, grouped tabs that stay in sync, and single or multiple accordions.

## Tabs

`items` lists the labels, separated by `|`. Each `Tab` takes its `value` from
the matching label. With `updateAnchor`, choosing a tab also updates the URL
hash, so the link you copy opens that tab.

### macOS

Archives live in `~/Library/Application Support/Lanternfly`.

### Linux

Archives live in `~/.local/share/lanternfly`.

### Windows

Archives live in `%APPDATA%\Lanternfly`.

### Choosing the default

#### Monthly

Pay each month, cancel any time.

#### Yearly

Pay once a year and get two months free.

### Grouped tabs

Tabs with the same `groupId` switch together. With `persist`, the choice is
remembered across pages and visits.

#### JavaScript

```js
import { open } from 'lanternfly';
const archive = await open('notes');
```

#### PHP

```php
<?php
$archive = Lanternfly\open('notes');
```

#### JavaScript

```js
const hits = await archive.search('invoice');
```

#### PHP

```php
<?php
$hits = $archive->search('invoice');
```

A label that itself contains a pipe is escaped: `items="a\|b|c"` yields the two
tabs "a|b" and "c".

## Accordions

### Single

Only one item is open at a time.

#### Is Lanternfly free?

Yes, for personal use.

#### Does it sync?

No. Archives are plain folders; use whatever sync tool you already trust.

#### Can I export everything?

Yes. `lanternfly export --all` writes one Markdown file per note.

### Multiple

Any number of items can be open.

#### Keyboard shortcuts

`/` focuses search, `n` creates a note, `Esc` closes any dialog.

#### File formats

Markdown, plain text and PDF are indexed. Everything else is stored but not
searched.

## Related topics

- [Guide](https://lanternfly.example/guide.md)
- [Installation](https://lanternfly.example/guide/installation.md)
- [Writing pages](https://lanternfly.example/guide/writing-pages.md)
- [Callouts and cards](https://lanternfly.example/guide/callouts-and-cards.md)
- [Steps and files](https://lanternfly.example/guide/steps-and-files.md)
- Previous: [Callouts and cards](https://lanternfly.example/guide/callouts-and-cards.md)
- Next: [Steps and files](https://lanternfly.example/guide/steps-and-files.md)
