#!/bin/bash
# Apply OpenVPN client-connect hook on existing servers (safe to re-run).
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$DIR/openvpn-common.sh" 2>/dev/null || exit 0
usk_openvpn_ensure_management 2>/dev/null || true
