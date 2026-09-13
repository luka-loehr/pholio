#!/usr/bin/env bash
# Build the demo with drafts, serve it with PHP's built-in server and rebuild on changes.
#
#   ./scripts/serve-demo.sh [--port 8080] [--host 127.0.0.1] [--no-watch]
set -euo pipefail

cd "$(dirname "$0")/.."

exec php bin/pholio dev --config examples/demo/pholio.config.php "$@"
