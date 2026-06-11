#!/bin/bash
# Sync Xray outbound + per-user routing for all active clients bound to a node.
# Usage: xray-sync-node-egress.sh <node_id> [send_through_ip]
#
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/xray-common.sh"

if [ "$EUID" -ne 0 ]; then echo "USK_ERR: run_as_root"; exit 1; fi

NODE_ID="${1:-}"
SEND_THROUGH="${2:-}"
[ -n "$NODE_ID" ] || { echo "USK_ERR: node_id_required"; exit 1; }

usk_xray_resolve_cfg
[ -f "$XRAY_CFG" ] || { echo "USK_ERR: xray_not_installed"; exit 1; }
command -v jq >/dev/null 2>&1 || { echo "USK_ERR: jq_required"; exit 1; }

if [ -z "$SEND_THROUGH" ]; then
  HUB_SCRIPT="${DIR}/setup-hub-node-tunnel.sh"
  if [ -x "$HUB_SCRIPT" ]; then
    SEND_THROUGH=$(/bin/bash "$HUB_SCRIPT" send-through "$NODE_ID" 2>/dev/null | sed -n 's/^USK_OK: send_through=//p' | head -1)
  fi
fi
[ -n "$SEND_THROUGH" ] || { echo "USK_ERR: tunnel_not_ready"; exit 1; }

PANEL_ROOT="${PANEL_ROOT:-$(dirname "$DIR")}"
PANEL_FILE="${PANEL_ROOT}/data/clients/xray.json"

usk_xray_ensure_node_outbound "$XRAY_CFG" "$NODE_ID" "$SEND_THROUGH" || { echo "USK_ERR: xray_node_outbound_failed"; exit 1; }

if [ -f "$PANEL_FILE" ]; then
  while IFS= read -r email; do
    [ -n "$email" ] || continue
    usk_xray_bind_user_to_node "$XRAY_CFG" "$email" "$NODE_ID" || true
  done < <(jq -r --arg nid "$NODE_ID" '
    to_entries[] |
    select((.value.node_id // "") == $nid and (.value.status // "active") == "active") |
    (.value.xray_email // .value.usage_id // .value.email // .key)
  ' "$PANEL_FILE" 2>/dev/null)
fi

if ! usk_xray_test_config "$XRAY_CFG"; then
  echo "USK_ERR: xray_config_test_failed"
  exit 1
fi
usk_xray_service_restart || { echo "USK_ERR: xray_restart_failed"; exit 1; }

echo "USK_OK: xray_node_egress_synced node=${NODE_ID} send_through=${SEND_THROUGH}"
exit 0
