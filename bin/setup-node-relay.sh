#!/bin/bash
# Port-forward relay on a Node: client connects to NODE:PORT → Hub:PORT (VPN terminates on Hub).
# Usage:
#   setup-node-relay.sh init
#   setup-node-relay.sh add <tcp|udp> <listen_port> <hub_ip> <hub_port> [rule_id]
#   setup-node-relay.sh remove <rule_id>
#   setup-node-relay.sh status
#
set -euo pipefail

NODE_ROOT="${USK_NODE_ROOT:-/opt/unlimitsky-node}"
STATE_DIR="${NODE_ROOT}/data/relay"
RULES_FILE="${STATE_DIR}/rules.json"
APPLY_SCRIPT="${STATE_DIR}/apply-relay.sh"
CRON_FILE="/etc/cron.d/unlimitsky-node-relay"

usk_err() { echo "USK_ERR: $*"; exit 1; }

ensure_state_dir() {
  mkdir -p "$STATE_DIR"
  chmod 755 "$STATE_DIR"
  if [ ! -f "$RULES_FILE" ]; then
    echo "[]" > "$RULES_FILE"
  fi
}

ensure_jq() {
  command -v jq >/dev/null 2>&1 || {
    apt-get update -qq 2>/dev/null || true
    apt-get install -y jq iptables 2>/dev/null || true
  }
  command -v jq >/dev/null 2>&1 || usk_err "jq_required"
}

enable_forwarding() {
  sysctl -w net.ipv4.ip_forward=1 >/dev/null 2>&1 || true
  if [ -f /etc/sysctl.conf ] && ! grep -q '^net.ipv4.ip_forward=1' /etc/sysctl.conf 2>/dev/null; then
    echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf
  fi
}

detect_wan_if() {
  local iface
  iface=$(ip -4 route show default 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="dev"){print $(i+1); exit}}')
  [ -n "$iface" ] && echo "$iface" && return
  iface=$(ip -4 -o addr show scope global 2>/dev/null | awk '{print $2; exit}')
  [ -n "$iface" ] && echo "$iface" && return
  echo "eth0"
}

apply_rule_iptables() {
  local action="$1" proto="$2" listen="$3" hub_ip="$4" hub_port="$5"
  local wan
  wan=$(detect_wan_if)

  if [ "$action" = "add" ]; then
    iptables -t nat -C PREROUTING -i "$wan" -p "$proto" --dport "$listen" -j DNAT --to-destination "${hub_ip}:${hub_port}" 2>/dev/null \
      || iptables -t nat -A PREROUTING -i "$wan" -p "$proto" --dport "$listen" -j DNAT --to-destination "${hub_ip}:${hub_port}"
    iptables -t nat -C POSTROUTING -o "$wan" -p "$proto" -d "$hub_ip" --dport "$hub_port" -j MASQUERADE 2>/dev/null \
      || iptables -t nat -A POSTROUTING -o "$wan" -p "$proto" -d "$hub_ip" --dport "$hub_port" -j MASQUERADE
    iptables -C FORWARD -p "$proto" -d "$hub_ip" --dport "$hub_port" -j ACCEPT 2>/dev/null \
      || iptables -A FORWARD -p "$proto" -d "$hub_ip" --dport "$hub_port" -j ACCEPT
    iptables -C FORWARD -p "$proto" -s "$hub_ip" --sport "$hub_port" -m state --state ESTABLISHED,RELATED -j ACCEPT 2>/dev/null \
      || iptables -A FORWARD -p "$proto" -s "$hub_ip" --sport "$hub_port" -m state --state ESTABLISHED,RELATED -j ACCEPT
  else
    iptables -t nat -D PREROUTING -i "$wan" -p "$proto" --dport "$listen" -j DNAT --to-destination "${hub_ip}:${hub_port}" 2>/dev/null || true
    iptables -t nat -D POSTROUTING -o "$wan" -p "$proto" -d "$hub_ip" --dport "$hub_port" -j MASQUERADE 2>/dev/null || true
    iptables -D FORWARD -p "$proto" -d "$hub_ip" --dport "$hub_port" -j ACCEPT 2>/dev/null || true
    iptables -D FORWARD -p "$proto" -s "$hub_ip" --sport "$hub_port" -m state --state ESTABLISHED,RELATED -j ACCEPT 2>/dev/null || true
  fi
}

rebuild_apply_script() {
  ensure_jq
  ensure_state_dir
  local script_path="${NODE_ROOT}/bin/setup-node-relay.sh"
  {
    echo '#!/bin/bash'
    echo 'set -euo pipefail'
    echo "exec /bin/bash \"${script_path}\" apply-persisted"
  } > "$APPLY_SCRIPT"
  chmod 755 "$APPLY_SCRIPT"
}

cmd_apply_persisted() {
  ensure_jq
  ensure_state_dir
  enable_forwarding
  jq -r '.[] | "\(.proto) \(.listen) \(.hub_ip) \(.hub_port)"' "$RULES_FILE" 2>/dev/null | while read -r proto listen hub_ip hub_port; do
    [ -z "$proto" ] && continue
    apply_rule_iptables add "$proto" "$listen" "$hub_ip" "$hub_port" || true
  done
  echo "USK_OK: relay_applied"
}

install_reboot_hook() {
  ensure_state_dir
  rebuild_apply_script
  cat > "$CRON_FILE" <<EOF
@reboot root ${APPLY_SCRIPT} >/dev/null 2>&1
EOF
  chmod 644 "$CRON_FILE" 2>/dev/null || true
}

cmd_init() {
  ensure_jq
  ensure_state_dir
  enable_forwarding
  install_reboot_hook
  echo "USK_OK: relay_init"
}

cmd_add() {
  local proto="${1:-}" listen="${2:-}" hub_ip="${3:-}" hub_port="${4:-}" rule_id="${5:-}"
  ensure_jq
  ensure_state_dir
  enable_forwarding

  proto=$(echo "$proto" | tr '[:upper:]' '[:lower:]')
  if [ "$proto" != "tcp" ] && [ "$proto" != "udp" ]; then
    usk_err "invalid_proto"
  fi
  if [ -z "$listen" ] || [ -z "$hub_ip" ] || [ -z "$hub_port" ]; then
    usk_err "missing_args"
  fi
  if [ "$listen" -lt 1 ] 2>/dev/null || [ "$listen" -gt 65535 ] 2>/dev/null; then
    usk_err "invalid_listen_port"
  fi
  if [ "$hub_port" -lt 1 ] 2>/dev/null || [ "$hub_port" -gt 65535 ] 2>/dev/null; then
    usk_err "invalid_hub_port"
  fi

  if [ -z "$rule_id" ]; then
    rule_id="${proto}-${listen}-to-${hub_ip}-${hub_port}"
  fi

  apply_rule_iptables add "$proto" "$listen" "$hub_ip" "$hub_port"

  tmp=$(mktemp)
  jq --arg id "$rule_id" --arg proto "$proto" --argjson listen "$listen" --arg hub_ip "$hub_ip" --argjson hub_port "$hub_port" \
    'map(select(.id != $id)) + [{id:$id, proto:$proto, listen:$listen, hub_ip:$hub_ip, hub_port:$hub_port, updated_at:(now|todate)}]' \
    "$RULES_FILE" > "$tmp" && mv "$tmp" "$RULES_FILE"

  install_reboot_hook
  echo "USK_OK: relay_added id=${rule_id} proto=${proto} listen=${listen} hub=${hub_ip}:${hub_port}"
}

cmd_remove() {
  local rule_id="${1:-}"
  ensure_jq
  ensure_state_dir
  [ -n "$rule_id" ] || usk_err "rule_id_required"

  local proto listen hub_ip hub_port
  proto=$(jq -r --arg id "$rule_id" '.[] | select(.id==$id) | .proto' "$RULES_FILE" | head -1)
  listen=$(jq -r --arg id "$rule_id" '.[] | select(.id==$id) | .listen' "$RULES_FILE" | head -1)
  hub_ip=$(jq -r --arg id "$rule_id" '.[] | select(.id==$id) | .hub_ip' "$RULES_FILE" | head -1)
  hub_port=$(jq -r --arg id "$rule_id" '.[] | select(.id==$id) | .hub_port' "$RULES_FILE" | head -1)

  if [ -n "$proto" ] && [ "$proto" != "null" ]; then
    apply_rule_iptables remove "$proto" "$listen" "$hub_ip" "$hub_port"
  fi

  tmp=$(mktemp)
  jq --arg id "$rule_id" 'map(select(.id != $id))' "$RULES_FILE" > "$tmp" && mv "$tmp" "$RULES_FILE"
  rebuild_apply_script
  echo "USK_OK: relay_removed id=${rule_id}"
}

cmd_status() {
  ensure_jq
  ensure_state_dir
  local count
  count=$(jq 'length' "$RULES_FILE" 2>/dev/null || echo 0)
  echo "USK_OK: relay_status count=${count}"
  jq -c '.' "$RULES_FILE" 2>/dev/null || echo "[]"
}

ACTION="${1:-}"
shift || true

case "$ACTION" in
  init) cmd_init ;;
  add) cmd_add "$@" ;;
  remove) cmd_remove "$@" ;;
  status) cmd_status ;;
  apply-persisted) cmd_apply_persisted ;;
  *)
    usk_err "usage: setup-node-relay.sh init|add|remove|status|apply-persisted"
    ;;
esac
