---
title: Getting started
description: Install Pholio, create a project, write a page, build and preview the site.
icon: rocket
---

## Requirements

- PHP 8.2 or newer, command line, with `mbstring` and `ctype`.
- PCRE2 10.43 or newer, which is bundled with PHP (`php -r 'echo PCRE_VERSION;'`).
  The syntax highlighter needs it for lookbehinds of variable length.

That is the whole list for building a site. Node is needed only for the
comparison tooling in `verify/`, which never reaches a published site.

## Install

The install script downloads the latest release, verifies its checksum, checks
PHP and PCRE2, and extracts Pholio into `vendor/pholio`:

```bash
curl -fsSL https://raw.githubusercontent.com/luka-loehr/pholio/main/scripts/install.sh | sh
```

`sh -s -- --dir <path> --version vX.Y.Z` installs somewhere else or a specific
version. Pholio is a drop-in, not a package, so cloning the repository works as
well:

```bash
git clone https://github.com/luka-loehr/pholio.git vendor/pholio
```

The examples below call `php vendor/pholio/bin/pholio`. Put `vendor/pholio/bin`
on your `PATH` to type `pholio` instead.

## Create a project

```bash
php vendor/pholio/bin/pholio init docs
```

`init` creates the project layout with a small sample site. `--name "My Docs"`
sets the site title (default: derived from the directory name, so `my-docs`
becomes "My Docs"), and `--lang de` switches the interface to German. It refuses
to overwrite existing files and exits 2 unless you pass `--force`.

```
docs/
  pholio.config.php   optional; every key has a default
  content/            Markdown pages and meta.json files
  assets/             images and files, published at /assets/
  public/             the built site
```

Without a `pholio.config.php`, a directory with a `content/` folder builds with
the defaults. [Configuration](/docs/configuration) lists every key.

## Write a page

Content is a folder of Markdown files. A `meta.json` per folder sets its title
and the order of its pages.

```markdown
---
title: Exporting a report
description: How to save a report as a file.
---

Open the report you want to keep, then choose **Export** in the toolbar.

![The export dialog](/assets/images/export.png)

<Callout type="info">
Exports contain every page of the report.
</Callout>
```

The format is described in [Content format](/docs/content-format).

## Build and preview

```bash
php vendor/pholio/bin/pholio dev docs
php vendor/pholio/bin/pholio build docs
```

`dev` builds with drafts, serves the site at `http://127.0.0.1:8080` and
rebuilds when the content, the config or `assets/` changes. `build` writes the
site into `docs/public/`. Deploy by copying that directory; nothing runs on the
server.

## Try the demo

The repository contains a demo site that uses every component:

```bash
cd vendor/pholio
php bin/pholio build --config examples/demo/pholio.config.php
./scripts/serve-demo.sh
```

## Commands

```
pholio init   [dir] [--name <site name>] [--lang en|de] [--force]
pholio build  [dir] [--config <file>] [--profile <name>] [--content <dir>] [--out <dir>]
              [--only <url-part>] [--dev] [--quiet] [--set <key.path>=<value>]
pholio check  [dir] [--config <file>] [--profile <name>] [--content <dir>] [--against <dir>]
              [--dev] [--set <key.path>=<value>]
pholio dev    [dir] [--config <file>] [--profile <name>] [--content <dir>] [--host 127.0.0.1]
              [--port 8080] [--no-watch] [--set <key.path>=<value>]
pholio --help | <command> --help | --version
```

`[dir]` is the project directory, default the current directory. `pholio` without
arguments prints a short overview and exits 0.

| Command | What it does |
| --- | --- |
| `init` | Creates `pholio.config.php`, `content/` with two sample pages and `assets/images/` |
| `build` | Renders the site into `output_dir`. Files already there that Pholio doesn't write are left alone |
| `check` | Renders into a temporary directory and compares it with `--against` (default `output_dir`) in both directions. Prints `missing:` (built, not there), `stale:` (content differs) and `extra:` (there, not built) lines; `output.keep` paths are ignored |
| `dev` | Builds with drafts into `output_dir`, serves it with PHP's built-in server and rebuilds on changes |

| Flag | Effect |
| --- | --- |
| `--config <file>` | Configuration file. Default: `<dir>/pholio.config.php` if present, otherwise the defaults. Can't be combined with `[dir]` |
| `--profile <name>` | Merge `profiles.<name>` over the configuration |
| `--content <dir>`, `--out <dir>` | Override `content_dir` and `output_dir`, relative to the current directory |
| `--against <dir>` | Directory `check` compares with |
| `--only <url-part>` | Render only pages whose URL contains this text. For iteration, not for deployment |
| `--dev` | Include drafts, the pages whose file name starts with `_` |
| `--quiet` | No summary line |
| `--set <key>=<value>` | Override a string key, e.g. `--set search.tokenizer=german`. Repeatable |
| `--host`, `--port` | Address of the `dev` server. Default `127.0.0.1:8080` |
| `--no-watch` | `dev` serves without rebuilding |
| `--name`, `--lang`, `--force` | `init`: site title, interface language, overwrite existing files |

`build --check` still works as an alias of `check` and prints a deprecation
note.

## Exit codes

| Code | Meaning |
| --- | --- |
| `0` | Success; `check` found no difference |
| `1` | `check` found differences |
| `2` | Usage or configuration error: unknown command, flag or key, a planned key, an invalid value, a missing config file, a directory with neither `pholio.config.php` nor `content/`, an unknown profile, `init` refusing to overwrite |
| `3` | Content error: Markdown, component tags, frontmatter, `meta.json`, icons, code languages, an image path outside a copied directory |
| `4` | I/O error: the output can't be written or a copy failed |
| `70` | Internal error. `PHOLIO_DEBUG=1` prints the stack trace |

Errors go to stderr as `pholio: <file>:<line>: <message>` when a location is
known.
