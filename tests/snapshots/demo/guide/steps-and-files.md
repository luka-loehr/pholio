> ## Documentation Index
> Fetch the complete documentation index at: https://lanternfly.example/llms.txt
> Use this file to discover all available pages before exploring further.

# Steps and files

> Numbered procedures and file trees.

## Steps

### 1. Create an archive

```bash
lanternfly init notes
```

### 2. Import existing files

```bash
lanternfly import ~/Documents/notes --into notes
```

### 3. Search

```bash
lanternfly search "quarterly report" --in notes
```

## Files

A file tree shows what `lanternfly init` creates. Folders can start open.

- notes/
  - lanternfly.yaml
  - index/
    - terms.bin
    - documents.bin
  - attachments/
    - receipt-2026-03.pdf
  - README.md

> **Info**
>
> Never edit the `index` folder by hand. `lanternfly reindex` rebuilds it from
> scratch.

## Related topics

- [Guide](https://lanternfly.example/guide.md)
- [Installation](https://lanternfly.example/guide/installation.md)
- [Writing pages](https://lanternfly.example/guide/writing-pages.md)
- [Callouts and cards](https://lanternfly.example/guide/callouts-and-cards.md)
- [Tabs and accordions](https://lanternfly.example/guide/tabs-and-accordions.md)
- Previous: [Tabs and accordions](https://lanternfly.example/guide/tabs-and-accordions.md)
