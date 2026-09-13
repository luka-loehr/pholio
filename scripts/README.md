# `scripts/`: checks, releases, install

Shell scripts that run from any directory. The same checks run locally and in
CI.

| Script | What it does |
| --- | --- |
| `scripts/lint.sh` | `php -l` over every tracked PHP file, except the deliberately invalid highlighter samples |
| `scripts/check.sh` | The lint, `php tests/run.php`, and `pholio check` of the demo against `tests/snapshots/demo` (a plain demo build while no snapshot exists). Exits non-zero on the first failure. The highlighter samples and the snapshot check need PCRE2 10.43 or newer. On an older PCRE2 they skip with a message and `check.sh` builds the demo instead; `PHOLIO_REQUIRE_PCRE2=1` turns those skips into failures |
| `scripts/update-snapshots.sh` | Rebuilds `tests/snapshots/demo` from the demo and checks it; run it with PCRE2 10.43 or newer and commit the result as one run |
| `scripts/serve-demo.sh` | `pholio dev` for the demo at `http://127.0.0.1:8080`; passes `--port`, `--host` and `--no-watch` through |
| `scripts/release.sh` | `release.sh <version> [--push]` bumps `VERSION`, dates `CHANGELOG.md`, runs the checks, commits and tags `v<version>`. `--package` writes the release archives and `SHA256SUMS`, `--notes` prints a version's changelog section |
| `scripts/install.sh` | The one-line install for users: downloads a release and verifies its checksum, checks PHP and PCRE2 (an older PCRE2 only warns), installs into `~/.local/share/pholio` and links `pholio` into `~/.local/bin`. `--system` installs into `/usr/local`, `--dir` makes a per-project copy, `--prune` removes old versions |

## CI

`.github/workflows/ci.yml` runs on every push and pull request to `main`. Each
commit on `main` keeps its run; only a newer push to the same pull request
cancels its older run.

- **PHP 8.2 to 8.5 on `ubuntu-latest`** (PCRE2 10.42, like stable
  distributions): `check.sh`, whose PCRE2 10.43+ comparisons skip there, and a
  demo build that must print the PCRE2 fallback warning.
- **Full fidelity, PHP 8.2 and 8.5 on `ubuntu-26.04`**: `check.sh` with
  `PHOLIO_REQUIRE_PCRE2=1`, so the highlighter samples and the demo snapshot
  must match and nothing may skip.
- **Node 26**: `node verify/run.mjs`, the search engine and relevance checks,
  the search dialog in Chromium, Firefox and WebKit, the agent score of the demo
  and the icon check.

`.github/workflows/release.yml` runs on a `v*.*.*` tag: it checks that the tag
matches `VERSION` and has a `CHANGELOG.md` section, runs the same stable and
full-fidelity jobs, and only when both pass builds the archives with
`release.sh --package` and publishes the GitHub release that `install.sh`
downloads. See [`docs/testing.md`](../docs/testing.md).
