# `scripts/`: checks, releases, install

Shell scripts that run from any directory. The same checks run locally and in
CI.

| Script | What it does |
| --- | --- |
| `scripts/lint.sh` | `php -l` over every tracked PHP file, except the deliberately invalid highlighter samples |
| `scripts/check.sh` | Tier 1: the lint, `php tests/run.php`, and `pholio check` of the demo against `tests/snapshots/demo` (a plain demo build while no snapshot exists). Exits non-zero on the first failure |
| `scripts/update-snapshots.sh` | Rebuilds `tests/snapshots/demo` from the demo and checks it; commit the result as one run |
| `scripts/serve-demo.sh` | `pholio dev` for the demo at `http://127.0.0.1:8080`; passes `--port`, `--host` and `--no-watch` through |
| `scripts/release.sh` | `release.sh <version> [--push]` bumps `VERSION`, dates `CHANGELOG.md`, runs the checks, commits and tags `v<version>`. `--package` writes the release archives and `SHA256SUMS`, `--notes` prints a version's changelog section |
| `scripts/install.sh` | The one-line install for users: downloads a release, verifies its checksum, checks PHP and PCRE2 and installs Pholio |

## CI

`.github/workflows/ci.yml` runs on every push and pull request to `main`: a PHP
job that requires PCRE2 10.43 and runs `lint.sh`, `php tests/run.php` and
`check.sh`, and a Node job that runs `node verify/run.mjs --tier 2` with
Chromium headless shell.

`.github/workflows/release.yml` runs on a `v*.*.*` tag: it checks that the tag
matches `VERSION` and has a `CHANGELOG.md` section, runs `check.sh`, builds the
archives with `release.sh --package` and publishes the GitHub release that
`install.sh` downloads.

Tier 3, the comparison against a reference build, needs a reference export and
runs on a real machine, not in CI. See [`docs/verification.md`](../docs/verification.md).
