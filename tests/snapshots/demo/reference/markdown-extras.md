> Documentation index: https://lanternfly.example/llms.txt, a list of every page in this documentation.

# Markdown extras

> GitHub-flavoured tables with alignment, task lists, footnotes, strikethrough and hard breaks.

## Tables

Columns can be aligned left, centre or right.

| Command | Speed | Notes |
| :--- | :---: | ---: |
| `search` | fast | reads the index only |
| `reindex` | slow | reads every file |
| `export` | medium | writes one file per note |

## Task lists

- [x] Install Lanternfly
- [x] Create an archive
- [ ] Import the old notes
- [ ] Delete the old notes folder

## Footnotes

Lanternfly ranks results with BM25[^bm25] and does not stem words[^stem].

## Strikethrough

The `--fast` flag is ~~experimental~~ stable since version 2.2.

## Emphasis and inline code

**Bold**, *italic*, ***both***, and `inline code` combine as expected.

## Hard line breaks

Two trailing spaces end a line\
without starting a new paragraph.

## Lists

1. Ordered lists count themselves.
2. They can nest:
   - an unordered item
   - another one
3. And continue afterwards.

---

A horizontal rule, like the one above, separates unrelated content.

[^bm25]: A ranking function that scores documents by term frequency, adjusted for document length.

[^stem]: Searching for "archive" does not match "archiving".

## Related topics

- [Reference](https://lanternfly.example/reference.md)
- [Code blocks](https://lanternfly.example/reference/code-blocks.md)
- [API types](https://lanternfly.example/reference/api-types.md)
- Previous: [API types](https://lanternfly.example/reference/api-types.md)
