# Contributing

Pholio is a personal project by Luka Löhr, at version 0.1.0 and currently private.
If you have access to this repository, these are the rules that apply.

## One file per commit

Every commit changes exactly one file. Run `git add <file> && git commit`,
never `git add -A`, never several files at once. The result is a long history
in which every change can be read, reviewed and reverted on its own.

The single exception is generated output: the files a build writes are
committed together, once per build run.

## Commit messages

Conventional prefix, then the file, then what changed:

```
feat(components): src/components/callout.php – icon and colour strip per type
fix(theme): theme/css/notebook.css – sidebar mask at 12px, not 16px
docs: docs/configuration.md – document the search tokenizer key
```

English, imperative, no attribution or co-author lines.

## Acceptance is a measurement

A change is done when the relevant verification tier has been run and read,
not when it looks right. "Works on my machine" is not a report; a diff is.

| Tier | Command | Needs |
| --- | --- | --- |
| 1 | `./scripts/check.sh` | PHP 8.2, PCRE2 10.43 |
| 2 | `node verify/run.mjs --tier 2` | Tier 1, Node, `npm ci` in `verify/`, Chromium |
| 3 | `node verify/run.mjs --tier 3 --reference <dir> --rewrites <file.json> --candidate <url>` | A reference export |

A change to generated output regenerates the demo snapshot with
`./scripts/update-snapshots.sh`, committed as one run. See
[`docs/verification.md`](docs/verification.md) and
[`verify/README.md`](verify/README.md).

A new configuration key is added to `src/Config.php` and
`docs/configuration.md` together; `tests/DocsConfigTest.php` fails otherwise.

## Code rules

- PHP 8.2, `declare(strict_types=1)`, four spaces, no Composer dependencies.
- Components are pure functions: props in, HTML string out, no globals, no I/O.
- Fail loud. Unknown input aborts the build with the file and line in the
  message. Never add a silent fallback.
- JavaScript is plain ES modules, two spaces, no bundler and no libraries.
- CSS values are derived from the compiled reference output, not guessed. If a
  value cannot be justified from the reference, it is wrong.
- Node is allowed in `verify/` only, because nothing there is ever shipped.

## Before you push

```bash
./scripts/check.sh
```
