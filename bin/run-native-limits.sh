#!/bin/bash
# Run usage sync as root (sudo from www-data). stdout = JSON report.
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "$DIR")"
PHP_BIN="${PHP_BIN:-php}"
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  PHP_BIN="$(command -v php 2>/dev/null || echo php)"
fi
exec "$PHP_BIN" "$ROOT/cron/native-limits.php"
