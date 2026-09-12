---
title: Getting started
description: Install Pholio, write one page, build the site.
icon: rocket
---

<Callout type="warn" title="Not wired yet">
The commands on this page describe the intended workflow. `bin/pholio` is
currently a stub that exits with code 1 until the generator has been moved into
`src/`.
</Callout>

## Requirements

PHP 8.2 or newer with the standard library. That is the whole list. Node is
needed only if you want to run the verification tooling in `verify/`, which is
never part of a published site.

## Install

Pholio is a drop-in, not a package. Copy the repository into your project, or
add it as a submodule:

```bash
git clone https://github.com/luka-loehr/pholio.git vendor/pholio
```

## Create a config

```bash
cp vendor/pholio/pholio.config.example.php docs.config.php
```

Set at least `title`, `base_url`, `content_dir` and `output_dir`. Every other
key has a default. The full list is in [Configuration](/docs/configuration).

## Write a page

Content is a folder of Markdown files. Each folder carries a `meta.json` that
names its title and the order of its pages.

```markdown
---
title: Exporting a chat
description: How to save a conversation as a file.
---

Open the conversation you want to keep, then use the export button in the
header.

<Callout type="info">
Exports contain the full conversation, including your own messages.
</Callout>
```

## Build

```bash
php vendor/pholio/bin/pholio build --config docs.config.php
```

The output directory now holds the finished site. Deploy it by copying the
files; there is nothing to run on the server.

## Useful flags

| Flag | Effect |
| --- | --- |
| `--check` | Builds into a temporary directory and diffs against the output directory. Any difference is an error, so a stale committed build cannot slip through. |
| `--dev` | Also builds the component showcase page, which is excluded from a normal build. |
| `--only <slug>` | Builds a single page. For quick iteration, not for deployment. |
