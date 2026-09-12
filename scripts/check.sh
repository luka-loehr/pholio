#!/usr/bin/env bash
# Full local check: syntax, then a parity build against the committed output.
set -euo pipefail

cd "$(dirname "$0")/.."

./scripts/lint.sh

if [ ! -d src/lib ]; then
    echo "check: generator not present yet (src/ is still a placeholder), stopping after lint"
    exit 0
fi

php bin/pholio build --check --config pholio.config.php
