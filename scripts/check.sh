#!/usr/bin/env bash
# Lint, the PHP test suite, and the demo built against its committed snapshot.
# Exits non-zero on the first failure.
set -euo pipefail

cd "$(dirname "$0")/.."

./scripts/lint.sh
php tests/run.php

config=examples/demo/pholio.config.php
build_demo() {
    out="$(mktemp -d)"
    trap 'rm -rf "$out"' EXIT
    php bin/pholio build --config "$config" --out "$out"
}

# PCRE2 older than 10.43 simplifies some syntax colours, so the snapshot cannot match there. PHOLIO_REQUIRE_PCRE2=1
# runs the snapshot check anyway, which then fails.
pcre2="$(php -r 'echo explode(" ", PCRE_VERSION)[0];')"
if ! php -r 'exit(version_compare(explode(" ", PCRE_VERSION)[0], "10.43", ">=") ? 0 : 1);' \
    && [ "${PHOLIO_REQUIRE_PCRE2:-}" != 1 ]; then
    echo "check: skipped snapshot check: PCRE2 $pcre2 is older than 10.43, building the demo into a temporary directory instead"
    build_demo
elif [ -d tests/snapshots/demo ]; then
    php bin/pholio check --config "$config" --against tests/snapshots/demo
else
    echo "check: tests/snapshots/demo not generated yet, building the demo into a temporary directory"
    build_demo
fi
