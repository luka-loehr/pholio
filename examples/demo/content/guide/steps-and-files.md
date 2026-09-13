---
title: Steps and files
description: Numbered procedures and file trees.
icon: folder-tree
---

## Steps

<Steps>
<Step>

### Create an archive

```bash
lanternfly init notes
```

</Step>
<Step>

### Import existing files

```bash
lanternfly import ~/Documents/notes --into notes
```

</Step>
<Step>

### Search

```bash
lanternfly search "quarterly report" --in notes
```

</Step>
</Steps>

## Files

A file tree shows what `lanternfly init` creates. Folders can start open.

<Files>
  <Folder name="notes" defaultOpen>
    <File name="lanternfly.yaml" />
    <Folder name="index" defaultOpen>
      <File name="terms.bin" />
      <File name="documents.bin" />
    </Folder>
    <Folder name="attachments">
      <File name="receipt-2026-03.pdf" />
    </Folder>
    <File name="README.md" />
  </Folder>
</Files>

<Callout type="info">
Never edit the `index` folder by hand. `lanternfly reindex` rebuilds it from
scratch.
</Callout>
