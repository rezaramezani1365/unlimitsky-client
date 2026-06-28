#!/bin/bash
# Restore Xray routing + reload all active clients (fixes "connected but no internet").
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec "$DIR/xray-fix-connectivity.sh"
