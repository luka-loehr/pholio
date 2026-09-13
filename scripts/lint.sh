#!/usr/bin/env bash
# Syntax-check every tracked PHP file. No dependencies beyond php and git.
# tests/fixtures/highlight/ holds highlighter inputs, some invalid on purpose; they are not linted.
set -euo pipefail

cd "$(dirname "$0")/.."

status=0
count=0

while IFS= read -r file; do
    count=$((count + 1))
    if ! php -l "$file" > /dev/null; then
        php -l "$file" || true
        status=1
    fi
done < <(git ls-files '*.php' 'bin/pholio' ':!tests/fixtures/highlight/')

echo "checked ${count} file(s)"
exit "$status"
