#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
PASS="${2:-}"
[ -n "$USERNAME" ] || usk_json_fail "username_required"

if [ ! -f /etc/ocserv/ocserv.conf ]; then
  usk_json_fail "cisco_not_installed"
fi

if [ -z "$PASS" ]; then
  usk_json_fail "password_required"
fi

printf '%s\n%s\n' "$PASS" "$PASS" | ocpasswd -c /etc/ocserv/ocpasswd "$USERNAME" 2>/dev/null \
  || usk_json_fail "cisco_user_enable_failed"

SERVER_IP=$(usk_server_ip)
PORT=$(usk_protocol_port /etc/ocserv/ocserv.conf '^tcp-port' 4443)

ensure_jq

CONFIG="Cisco AnyConnect / OpenConnect VPN
Server: ${SERVER_IP}
Port: ${PORT}
Username: ${USERNAME}
Password: ${PASS}

URL: ${SERVER_IP}:${PORT}"

echo -n "USK_JSON:"
jq -n \
  --arg u "$USERNAME" \
  --arg cfg "$CONFIG" \
  --arg pass "$PASS" \
  --arg ip "$SERVER_IP" \
  --argjson port "$PORT" \
  '{ok:true, username:$u, protocol:"cisco", config:$cfg, links:$cfg, password:$pass, server_ip:$ip, port:$port}'
exit 0
