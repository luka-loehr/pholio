> ## Documentation Index
> Fetch the complete documentation index at: https://lanternfly.example/llms.txt
> Use this file to discover all available pages before exploring further.

# API types

> The options accepted by open(), as a type table.

## `open(path, options)`

Opens an archive. Every option is optional unless marked required.

- `path`: `string`, required. Folder of the archive, absolute or relative to the working directory.
- `watch`: `boolean`, default `false`. Re-index files as they change on disk.
- `limit`: `number` (Integer between 1 and 1000), default `50`. Maximum number of hits a single search returns.
- `language`: `"english" | "german"`, default `"english"`. Tokenizer used for new documents.
- `legacyIndex`: `boolean`, default `false`, deprecated. Read the 1.x index format. Will be removed in version 3.

## Return value

- `search`: `(query: string) => Promise<Hit[]>`. Runs a query and resolves to the ranked hits.
- `reindex`: `() => Promise<void>`. Rebuilds the index from the files on disk.
- `close`: `() => void`. Releases file handles. The archive cannot be used afterwards.

## Related topics

- [Reference](https://lanternfly.example/reference.md)
- [Code blocks](https://lanternfly.example/reference/code-blocks.md)
- [Markdown extras](https://lanternfly.example/reference/markdown-extras.md)
- Previous: [Code blocks](https://lanternfly.example/reference/code-blocks.md)
- Next: [Markdown extras](https://lanternfly.example/reference/markdown-extras.md)
