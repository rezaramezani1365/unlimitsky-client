#!/bin/bash
# Install Xray — VLESS + Reality (port 443, Iran-optimized)
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"
source "$DIR/xray-common.sh"

export USK_DATA_ROOT="${USK_DATA_ROOT:-/var/lib/unlimitsky}"
mkdir -p "$USK_DATA_ROOT/xray" /usr/local/etc/xray
chmod 755 "$USK_DATA_ROOT" "$USK_DATA_ROOT/xray" 2>/dev/null || true

# On-node mode: drop Hub relay DNAT so clients hit local Xray instead of forwarded Hub port.
usk_node_clear_relay_rules "$DIR" 2>/dev/null || true

VLESS_PORT="${1:-${USK_XRAY_VLESS_PORT:-443}}"
VLESS_PORT=$(echo "$VLESS_PORT" | tr -dc '0-9')
[ -n "$VLESS_PORT" ] && [ "$VLESS_PORT" -ge 1 ] && [ "$VLESS_PORT" -le 65535 ] 2>/dev/null || VLESS_PORT=443

PANEL_ROOT="${PANEL_ROOT:-$(dirname "$DIR")}"
if [ -f "$XRAY_CFG" ]; then
  EXISTING_PORT=$(usk_xray_vless_port_from_config "$XRAY_CFG" 2>/dev/null || true)
  if [ -n "$EXISTING_PORT" ] && [ "$EXISTING_PORT" -ge 1 ] 2>/dev/null && [ "$EXISTING_PORT" -le 65535 ] 2>/dev/null; then
    VLESS_PORT="$EXISTING_PORT"
    echo "USK_INFO: xray_reinstall_preserve_port=${VLESS_PORT}"
  else
    VLESS_PORT=$(usk_xray_pick_free_port "$VLESS_PORT")
  fi
else
  VLESS_PORT=$(usk_xray_pick_free_port "$VLESS_PORT")
fi
export USK_XRAY_VLESS_PORT="$VLESS_PORT"

NEED_APT=0
for cmd in curl jq openssl; do
  command -v "$cmd" >/dev/null 2>&1 || NEED_APT=1
done
if [ "$NEED_APT" -eq 1 ]; then
  apt-get update -qq
  apt-get install -y curl unzip jq openssl ca-certificates qrencode
fi

if ! usk_xray_bin >/dev/null 2>&1; then
  bash -c "$(curl -fsSL https://github.com/XTLS/Xray-install/raw/main/install-release.sh)" @ install \
    || usk_fail "xray_binary_install_failed"
else
  echo "USK_INFO: xray_binary_already_installed"
fi

UUID=$(cat /proc/sys/kernel/random/uuid)

if [ -f "$XRAY_CFG" ]; then
  cp "$XRAY_CFG" "${XRAY_CFG}.bak.$(date +%s)" 2>/dev/null || true
  usk_xray_dedupe_config_clients "$XRAY_CFG" 2>/dev/null || true
fi

usk_xray_migrate_legacy_config "$XRAY_CFG" 2>/dev/null || true

EXISTING_VLESS=$(usk_xray_collect_all_clients_json "$XRAY_CFG" "$PANEL_ROOT" 2>/dev/null || usk_xray_load_clients "$XRAY_CFG")
if [ "$EXISTING_VLESS" = "[]" ] || [ "$EXISTING_VLESS" = "null" ] || [ -z "$EXISTING_VLESS" ]; then
  EXISTING_VLESS="[{\"id\":\"$UUID\",\"email\":\"bootstrap\",\"flow\":\"xtls-rprx-vision\"}]"
fi

usk_xray_ensure_reality_params || usk_fail "xray_reality_keygen_failed"

EXISTING_VLESS=$(usk_xray_normalize_clients "$EXISTING_VLESS")

if ! usk_xray_write_config "$XRAY_CFG" "$EXISTING_VLESS" "$VLESS_PORT"; then
  usk_fail "xray_config_json_failed"
fi

usk_xray_ensure_stats_policy "$XRAY_CFG" 2>/dev/null || true

usk_xray_fix_perms "$XRAY_CFG"
if ! usk_xray_test_config "$XRAY_CFG"; then
  usk_xray_test_config "$XRAY_CFG" 2>&1 | tail -12
  usk_fail "xray_config_test_failed"
fi

systemctl enable xray 2>/dev/null || systemctl enable xray.service 2>/dev/null || true
systemctl daemon-reload 2>/dev/null || true

usk_xray_open_firewall "$VLESS_PORT" "xray-vless-reality"

if ! usk_xray_verify_or_fail "$XRAY_CFG"; then
  echo "USK_WARN: xray_verify_retry"
  sleep 3
  usk_xray_service_restart || true
  if ! usk_xray_port_listening "$VLESS_PORT"; then
    usk_fail "xray_vless_port_not_listening port=${VLESS_PORT}"
  fi
  if ! usk_xray_test_config "$XRAY_CFG"; then
    usk_fail "xray_config_test_failed"
  fi
fi

usk_xray_rebuild_clients_in_config "$XRAY_CFG" "$PANEL_ROOT" 1 2>/dev/null || true
usk_xray_dedupe_config_clients "$XRAY_CFG" 2>/dev/null || true
source "$DIR/provision-common.sh" 2>/dev/null || true
usk_xray_clear_slot_iptables "${VLESS_PORT}" 2>/dev/null || true
REFRESHED=$(usk_xray_refresh_stored_links "$PANEL_ROOT" 2>/dev/null || echo 0)
CFG_N=$(jq '[.inbounds[]?|select(.protocol=="vless")|.settings.clients[]?]|length' "$XRAY_CFG" 2>/dev/null || echo 0)
echo "USK_INFO: xray_links_refreshed=${REFRESHED:-0} config_clients=${CFG_N:-0}"

# shellcheck disable=SC1090
. "$USK_XRAY_REALITY_FILE"
echo "USK_META:vless_port=${VLESS_PORT};reality=1;sni=${REALITY_SNI:-www.microsoft.com}"
usk_ok
