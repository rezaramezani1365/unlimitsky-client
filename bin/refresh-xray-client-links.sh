#!/bin/bash
# Regenerate stored VLESS links after Xray reinstall (same UUID, new port/Reality params).
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$DIR/provision-common.sh"
# shellcheck disable=SC1091
source "$DIR/xray-common.sh"

if [ "$EUID" -ne 0 ]; then
  echo "USK_ERR: run_as_root" >&2
  exit 1
fi

[ -f "$XRAY_CFG" ] || { echo "USK_ERR: xray_config_missing" >&2; exit 1; }

PANEL_ROOT="${PANEL_ROOT:-$(dirname "$DIR")}"
n=$(usk_xray_refresh_stored_links "$PANEL_ROOT" 2>/dev/null || echo 0)
echo "USK_OK: xray_links_refreshed=${n}"
exit 0
