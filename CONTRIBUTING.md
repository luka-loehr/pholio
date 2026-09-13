# Contributing

Pholio is a personal project by Luka Löhr, published at version 0.1.0.
These are the rules that apply to every change.

## One file per commit

Every commit changes exactly one file. Run `git add <file> && git commit`,
never `git add -A`, never several files at once. The result is a long history
in which every change can be read, reviewed and reverted on its own.

The single exception is generated output: the files a build writes are
committed together, once per build run.

## Commit messages

Conventional prefix, then the file, then what changed:

```
feat(components): src/components/callout.php – icon and color strip per type
fix(theme): theme/css/notebook.css – sidebar mask at 12px, not 16px
docs: docs/configuration.md – document the search tokenizer key
```

English, imperative, no attribution or co-author lines.

## Acceptance is a measurement

A change is done when the relevant checks have been run and read, not when it
looks right. "Works on my machine" is not a report; a diff is.

| Checks | Command | Needs |
| --- | --- | --- |
| PHP | `./scripts/check.sh` | PHP 8.2, PCRE2 10.43 |
| Node | `node verify/run.mjs` | Node 26, `npm ci` in `verify/`, the Playwright browsers |

A change to generated output regenerates the demo snapshot with
`./scripts/update-snapshots.sh`, committed as one run. See
[`docs/testing.md`](docs/testing.md) and
[`verify/README.md`](verify/README.md).

A new configuration key is added to `src/Config.php` and
`docs/configuration.md` together; `tests/DocsConfigTest.php` fails otherwise.

## Code rules

- PHP 8.2, `declare(strict_types=1)`, four spaces, no Composer dependencies.
- Components are pure functions: props in, HTML string out, no globals, no I/O.
- Fail loud. Unknown input aborts the build with the file and line in the
  message. Never add a silent fallback.
- JavaScript is plain ES modules, two spaces, no bundler and no libraries.
- CSS is hand-written in `theme/css/`, in cascade order. Colors go through the
  `--color-fd-*` tokens, so presets and site palettes reach every component.
- Node is allowed in `verify/` only, because nothing there is ever shipped.

## Before you push

```bash
./scripts/check.sh
```
