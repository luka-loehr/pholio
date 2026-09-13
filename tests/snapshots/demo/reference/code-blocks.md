> ## Documentation Index
> Fetch the complete documentation index at: https://lanternfly.example/llms.txt
> Use this file to discover all available pages before exploring further.

# Code blocks

> Titles, line numbers, notation comments and code tabs.

## Plain

```bash
lanternfly search invoice
```

## With a title

```yaml title="lanternfly.yaml"
archive:
  name: notes
  watch: true
```

## With line numbers

```ts title="search.ts"
import { open } from 'lanternfly';

export async function findInvoices(path: string) {
  const archive = await open(path);
  return archive.search('invoice', { limit: 20 });
}
```

Line numbers can start at another value:

```ts
  return archive.search('invoice', { limit: 20 });
}
```

## Highlighting

```ts
const archive = await open('notes');
const hits = await archive.search('invoice');
console.log(hits.length);
```

## Word highlighting

```ts
const hits = await archive.search('invoice');
const more = await archive.search('receipt');
```

## Focus

```ts
const archive = await open('notes');
await archive.reindex();
archive.close();
```

## Diff

```ts
const archive = await open('notes', {
  watch: false,
  watch: true,
});
```

## Notation in other languages

```yaml title="lanternfly.yaml"
archive:
  name: notes
  watch: true
```

## Code tabs

Consecutive code blocks with a `tab` attribute are merged into one tab group.

### JavaScript

```js
const hits = await archive.search('invoice');
```

### PHP

```php
<?php
$hits = $archive->search('invoice');
```

### CLI

```bash
lanternfly search invoice
```

## Line ranges in meta

A range like `{1,3-4}` in the fence meta is accepted for compatibility and has
no effect.

```ts
const a = 1;
const b = 2;
const c = 3;
const d = 4;
```

## Code as tag content

When a code sample must stay verbatim, pass it as the content of
`DynamicCodeBlock`.

```json
{
  "archive": "notes",
  "watch": true
}
```

## Related topics

- [Reference](https://lanternfly.example/reference.md)
- [API types](https://lanternfly.example/reference/api-types.md)
- [Markdown extras](https://lanternfly.example/reference/markdown-extras.md)
- Previous: [Reference](https://lanternfly.example/reference.md)
- Next: [API types](https://lanternfly.example/reference/api-types.md)
