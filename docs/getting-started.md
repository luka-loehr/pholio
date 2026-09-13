---
title: Getting started
description: Install Pholio, write one page, build and preview the site.
icon: rocket
---

## Requirements

- PHP 8.2 or newer, command line, with `mbstring` and `ctype`.
- PCRE2 10.43 or newer, which is bundled with PHP (`php -r 'echo PCRE_VERSION;'`).
  The syntax highlighter needs it for lookbehinds of variable length.

That is the whole list for building a site. Node is needed only for the
comparison tooling in `verify/`, which never reaches a published site.

## Install

Pholio is a drop-in, not a package. Clone it into your project, or add it as a
submodule:

```bash
git clone https://github.com/luka-loehr/pholio.git vendor/pholio
```

## Try the demo

The repository contains a demo site that uses every component:

```bash
cd vendor/pholio
php bin/pholio build --config examples/demo/pholio.config.php
./scripts/serve-demo.sh
```

Then open `http://127.0.0.1:8080`.

## Create a config

```bash
cp vendor/pholio/pholio.config.example.php docs.config.php
```

The example builds as it is once `content/` holds a page; images, a logo and
copied directories are commented out until those files exist. Only `title`,
`content_dir` and `output_dir` are required. Paths are relative to the
configuration file. [Configuration](/docs/configuration) lists every key.

```php
<?php

return [
    'title' => 'Example Docs',
    'content_dir' => 'content',
    'output_dir' => 'public',
];
```

## Write a page

Content is a folder of Markdown files. A `meta.json` per folder sets its title
and the order of its pages.

```markdown
---
title: Exporting a report
description: How to save a report as a file.
---

Open the report you want to keep, then choose **Export** in the toolbar.

<Callout type="info">
Exports contain every page of the report.
</Callout>
```

The format is described in [Content format](/docs/content-format).

## Build and preview

```bash
php vendor/pholio/bin/pholio build --config docs.config.php
php vendor/pholio/bin/pholio dev --config docs.config.php
```

`build` writes the site into `output_dir`. `dev` builds with drafts, serves the
output at `http://127.0.0.1:8080` and rebuilds when the content, the config or a
copied directory changes. Deploy by copying `output_dir`; nothing runs on the
server.

## Commands

```
pholio build  [--config <file>] [--profile <name>] [--content <dir>] [--out <dir>]
              [--only <url-part>] [--dev] [--quiet] [--set <key.path>=<value>]
pholio check  [--config <file>] [--profile <name>] [--content <dir>] [--against <dir>]
              [--dev] [--set <key.path>=<value>]
pholio dev    [--config <file>] [--profile <name>] [--content <dir>] [--host 127.0.0.1]
              [--port 8080] [--no-watch] [--set <key.path>=<value>]
pholio --help | <command> --help | --version
```

| Command | What it does |
| --- | --- |
| `build` | Renders the site into `output_dir`. Files already there that Pholio doesn't write are left alone |
| `check` | Renders into a temporary directory and compares it with `--against` (default `output_dir`) in both directions. Prints `missing:` (built, not there), `stale:` (content differs) and `extra:` (there, not built) lines; `output.keep` paths are ignored |
| `dev` | Builds with drafts into `output_dir`, serves it with PHP's built-in server and rebuilds on changes |

| Flag | Effect |
| --- | --- |
| `--config <file>` | Configuration file. Default: `./pholio.config.php` |
| `--profile <name>` | Merge `profiles.<name>` over the configuration |
| `--content <dir>`, `--out <dir>` | Override `content_dir` and `output_dir`, relative to the current directory |
| `--against <dir>` | Directory `check` compares with |
| `--only <url-part>` | Render only pages whose URL contains this text. For iteration, not for deployment |
| `--dev` | Include drafts, the pages whose file name starts with `_` |
| `--quiet` | No summary line |
| `--set <key>=<value>` | Override a string key, e.g. `--set search.tokenizer=german`. Repeatable |
| `--host`, `--port` | Address of the `dev` server. Default `127.0.0.1:8080` |
| `--no-watch` | `dev` serves without rebuilding |

`build --check` still works as an alias of `check` and prints a deprecation
note.

## Exit codes

| Code | Meaning |
| --- | --- |
| `0` | Success; `check` found no difference |
| `1` | `check` found differences |
| `2` | Usage or configuration error: unknown command, flag or key, a planned key, an invalid value, a missing config file or content directory, an unknown profile |
| `3` | Content error: Markdown, component tags, frontmatter, `meta.json`, icons, code languages |
| `4` | I/O error: the output can't be written or a copy failed |
| `70` | Internal error. `PHOLIO_DEBUG=1` prints the stack trace |

Errors go to stderr as `pholio: <file>:<line>: <message>` when a location is
known.
