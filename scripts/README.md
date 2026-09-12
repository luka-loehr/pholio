# `scripts/` — local checks

Plain shell scripts, run by hand. There is deliberately no CI configuration in
this repository: the checks that matter compare a build against a browser, and
those run on a real machine, not on a hosted runner.

| Script | What it does |
| --- | --- |
| `scripts/lint.sh` | `php -l` over every PHP file that is tracked |
| `scripts/check.sh` | Runs the lint, then `php bin/pholio build --check` once the generator is in place |

Run them from the repository root.
