#!/usr/bin/env bash
# Regenerate tests/snapshots/demo from a fresh demo build.
# Review the result with `git status` and `git diff`, then commit the whole run at once.
set -euo pipefail

cd "$(dirname "$0")/.."

snapshot=tests/snapshots/demo
rm -rf "$snapshot"
php bin/pholio build --config examples/demo/pholio.config.php --out "$snapshot"
php bin/pholio check --config examples/demo/pholio.config.php --against "$snapshot"
