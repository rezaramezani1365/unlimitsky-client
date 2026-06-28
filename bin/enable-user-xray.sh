#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"
source "$DIR/xray-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
UUID="${2:-}"
[ -n "$USERNAME" ] || usk_json_fail "username_required"
[ -n "$UUID" ] || usk_json_fail "uuid_required"

ensure_jq
command -v jq >/dev/null 2>&1 || usk_json_fail "jq_required"

usk_xray_add_client "$XRAY_CFG" "$UUID" "$USERNAME" || usk_json_fail "xray_config_update_failed"
usk_xray_service_restart || true

echo "USK_OK"
exit 0
