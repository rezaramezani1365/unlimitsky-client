#!/bin/bash
# Restore /etc/sudoers.d/unlimitsky so www-data can run VPN scripts (add-user, install, repair).
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIB="${DIR}/../install/lib.sh"
if [ ! -f "$LIB" ]; then
  echo "USK_ERR: lib_missing"
  exit 1
fi
# shellcheck source=/dev/null
source "$LIB"

if [ "$EUID" -ne 0 ]; then
  echo "USK_ERR: run_as_root"
  exit 1
fi

WEB_ROOT="${1:-/var/www/unlimitsky}"
if [ ! -f "${WEB_ROOT}/config.php" ]; then
  echo "USK_ERR: panel_not_found"
  exit 1
fi

if usk_write_vpn_sudoers "$WEB_ROOT"; then
  chmod +x "${WEB_ROOT}/bin/usk-run-root.sh" 2>/dev/null || true
  if sudo -u www-data sudo -n /bin/bash "${WEB_ROOT}/bin/usk-run-root.sh" bin/probe-protocol.sh wireguard "$WEB_ROOT" 2>&1 | grep -q USK_OK; then
    echo "USK_OK: sudoers_updated"
    exit 0
  fi
  echo "USK_WARN: sudoers_written_but_probe_failed"
  echo "USK_OK: sudoers_updated"
  exit 0
fi
echo "USK_ERR: sudoers_write_failed"
exit 1
