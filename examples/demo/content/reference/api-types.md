---
title: API types
description: The options accepted by open(), as a type table.
icon: braces
---

## `open(path, options)`

Opens an archive. Every option is optional unless marked required.

<TypeTable>
  <TypeProp name="path" type="string" required>
  Folder of the archive, absolute or relative to the working directory.
  </TypeProp>
  <TypeProp name="watch" type="boolean" default="false">
  Re-index files as they change on disk.
  </TypeProp>
  <TypeProp name="limit" type="number" default="50" typeDescription="Integer between 1 and 1000">
  Maximum number of hits a single search returns.
  </TypeProp>
  <TypeProp name="language" type="&quot;english&quot; | &quot;german&quot;" default="&quot;english&quot;">
  Tokenizer used for new documents.
  </TypeProp>
  <TypeProp name="legacyIndex" type="boolean" default="false" deprecated>
  Read the 1.x index format. Will be removed in version 3.
  </TypeProp>
</TypeTable>

## Return value

<TypeTable>
  <TypeProp name="search" type="(query: string) => Promise<Hit[]>">
  Runs a query and resolves to the ranked hits.
  </TypeProp>
  <TypeProp name="reindex" type="() => Promise<void>">
  Rebuilds the index from the files on disk.
  </TypeProp>
  <TypeProp name="close" type="() => void">
  Releases file handles. The archive cannot be used afterwards.
  </TypeProp>
</TypeTable>
