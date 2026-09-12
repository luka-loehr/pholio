#!/usr/bin/env bash
# Syntax-check every tracked PHP file. No dependencies beyond php and git.
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
done < <(git ls-files '*.php' 'bin/pholio')

echo "checked ${count} file(s)"
exit "$status"
