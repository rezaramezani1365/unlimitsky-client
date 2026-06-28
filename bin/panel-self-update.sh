#!/bin/bash
# Wrapper — panel update script lives in scripts/, not bin/.
exec "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/scripts/panel-self-update.sh" "$@"
