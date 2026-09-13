# `scripts/`: local checks

Plain shell scripts, run by hand from any directory. There is deliberately no
CI configuration in this repository: the checks that matter compare a build
against a browser, and those run on a real machine, not on a hosted runner.

| Script | What it does |
| --- | --- |
| `scripts/lint.sh` | `php -l` over every tracked PHP file |
| `scripts/check.sh` | Tier 1: the lint, `php tests/run.php`, and `pholio check` of the demo against `tests/snapshots/demo` (a plain demo build while no snapshot exists). Exits non-zero on the first failure |
| `scripts/update-snapshots.sh` | Rebuilds `tests/snapshots/demo` from the demo and checks it; commit the result as one run |
| `scripts/serve-demo.sh` | `pholio dev` for the demo at `http://127.0.0.1:8080`; passes `--port`, `--host` and `--no-watch` through |
