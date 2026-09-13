#!/usr/bin/env bash
# Tier 1: lint, the PHP test suite, and the demo built against its committed snapshot.
# Exits non-zero on the first failure.
set -euo pipefail

cd "$(dirname "$0")/.."

./scripts/lint.sh
php tests/run.php

config=examples/demo/pholio.config.php
if [ -d tests/snapshots/demo ]; then
    php bin/pholio check --config "$config" --against tests/snapshots/demo
else
    echo "check: tests/snapshots/demo not generated yet, building the demo into a temporary directory"
    out="$(mktemp -d)"
    trap 'rm -rf "$out"' EXIT
    php bin/pholio build --config "$config" --out "$out"
fi
