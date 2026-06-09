#!/bin/bash
# Single sudo entrypoint for www-data — runs allowlisted panel scripts as root.
# Usage: usk-run-root.sh <path-relative-to-panel-root> [args...]
# Example: usk-run-root.sh bin/add-user-wireguard.sh user1 0 30 udp
set -euo pipefail

BIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
USK_ROOT="$(cd "$BIN_DIR/.." && pwd)"

REL="${1:-}"
shift || { echo "USK_ERR: missing_script"; exit 1; }

# Normalize: bare script name → bin/
case "$REL" in
  bin/*|scripts/*) ;;
  *.sh) REL="bin/$REL" ;;
  *)
    echo "USK_ERR: invalid_script_path"
    exit 1
    ;;
esac

# Block traversal
case "$REL" in
  *..*) echo "USK_ERR: path_escape"; exit 1 ;;
esac

SCRIPT="${USK_ROOT}/${REL}"
if ! SCRIPT="$(readlink -f "$SCRIPT" 2>/dev/null)"; then
  echo "USK_ERR: script_not_found"
  exit 1
fi
ROOT="$(readlink -f "$USK_ROOT")"
case "$SCRIPT" in
  "$ROOT"/*) ;;
  *) echo "USK_ERR: path_escape"; exit 1 ;;
esac

if [ ! -f "$SCRIPT" ]; then
  echo "USK_ERR: script_not_found"
  exit 1
fi

bn="$(basename "$SCRIPT")"
allowed=0
case "$bn" in
  install-*.sh|add-user-*.sh|repair-*.sh|disable-user-*.sh|enable-user-*.sh|remove-user-*.sh|run-protocol-install.sh|probe-protocol.sh|setup-l2tp-usage.sh|collect-usage-stats.sh|run-native-limits.sh|xray-fix-stats-api.sh|enforce-connection-limits.sh|enforce-xray-iplimit.sh|install-fail2ban-iplimit.sh|xray-restore-runtime.sh|xray-emergency-fix.sh|install-php-zip.sh|apply-panel-access.sh|run-panel-update.sh|usk-run-root.sh)
    allowed=1
    ;;
esac
if [ "$allowed" -eq 0 ] && [ "$REL" = "scripts/panel-self-update.sh" ]; then
  allowed=1
fi
if [ "$allowed" -eq 0 ]; then
  echo "USK_ERR: script_not_allowed"
  exit 1
fi

exec /bin/bash "$SCRIPT" "$@"
