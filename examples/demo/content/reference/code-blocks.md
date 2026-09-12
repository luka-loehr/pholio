---
title: Code blocks
description: Titles, line numbers, notation comments and code tabs.
icon: code
---

## Plain

```bash
lanternfly search invoice
```

## With a title

```toml title="lanternfly.toml"
[archive]
name = "notes"
watch = true
```

## With line numbers

```ts title="search.ts" lineNumbers
import { open } from 'lanternfly';

export async function findInvoices(path: string) {
  const archive = await open(path);
  return archive.search('invoice', { limit: 20 });
}
```

Line numbers can start at another value:

```ts lineNumbers=40
  return archive.search('invoice', { limit: 20 });
}
```

## Highlighting

```ts
const archive = await open('notes');
const hits = await archive.search('invoice'); // [!code highlight]
console.log(hits.length);
```

## Word highlighting

```ts
// [!code word:search]
const hits = await archive.search('invoice');
const more = await archive.search('receipt');
```

## Focus

```ts
const archive = await open('notes');
await archive.reindex(); // [!code focus]
archive.close();
```

## Diff

```ts
const archive = await open('notes', {
  watch: false, // [!code --]
  watch: true, // [!code ++]
});
```

## Notation in other languages

```python title="search.py"
archive = open_archive("notes")
hits = archive.search("invoice")  # [!code highlight]
```

## Code tabs

Consecutive code blocks with a `tab` attribute are merged into one tab group.

```js tab="JavaScript"
const hits = await archive.search('invoice');
```

```python tab="Python"
hits = archive.search("invoice")
```

```bash tab="CLI"
lanternfly search invoice
```

## Line ranges in meta

A range like `{1,3-4}` in the fence meta is accepted for compatibility and has
no effect.

```ts {1,3-4}
const a = 1;
const b = 2;
const c = 3;
const d = 4;
```

## Code as tag content

When a code sample must stay verbatim, pass it as the content of
`DynamicCodeBlock`.

<DynamicCodeBlock lang="json">
{
  "archive": "notes",
  "watch": true
}
</DynamicCodeBlock>
