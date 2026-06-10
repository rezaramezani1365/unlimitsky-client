#!/bin/bash
# WireGuard health report ΓÇö run on server: sudo bash /var/www/unlimitsky/bin/diagnose-wireguard.sh
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"
source "$DIR/wireguard-common.sh"
set +e

conf="/etc/wireguard/wg0.conf"
registry=$(usk_wg_registry)
iface=$(usk_wg_main_iface)
iface="${iface:-eth0}"
subnet=$(usk_wg_subnet)

peer_count=0
if wg show wg0 >/dev/null 2>&1; then
  peer_count=$(wg show wg0 peers 2>/dev/null | wc -l)
  peer_count=$(echo "$peer_count" | tr -dc '0-9')
fi

listen_port=""
conf_port=""
runtime_port=""
[ -f "$conf" ] && conf_port=$(grep -E '^ListenPort' "$conf" 2>/dev/null | sed 's/.*=[[:space:]]*//' | tr -dc '0-9')
wg show wg0 >/dev/null 2>&1 && runtime_port=$(wg show wg0 listen-port 2>/dev/null | tr -dc '0-9')
listen_port="${conf_port:-$runtime_port}"
server_key_ok=0
[ -s /etc/wireguard/server_public.key ] && server_key_ok=1

registry_count=0
[ -f "$registry" ] && command -v jq >/dev/null 2>&1 \
  && registry_count=$(jq 'length' "$registry" 2>/dev/null)

nat_pkts=0
if command -v iptables >/dev/null 2>&1; then
  nat_pkts=$(iptables -t nat -L POSTROUTING -n -v 2>/dev/null | awk -v s="$subnet" '$0 ~ s {print $1; exit}')
  nat_pkts=$(echo "$nat_pkts" | tr -dc '0-9')
fi

fwd_wg=0
if command -v iptables >/dev/null 2>&1; then
  fwd_wg=$(iptables -L FORWARD -n -v 2>/dev/null | awk '/wg0/ {print $1; exit}')
  fwd_wg=$(echo "$fwd_wg" | tr -dc '0-9')
fi

ip_fwd=$(sysctl -n net.ipv4.ip_forward 2>/dev/null)

udp2raw_ok=0
usk_wg_udp2raw_valid /usr/local/bin/udp2raw 2>/dev/null && udp2raw_ok=1
tcp_unit=0
systemctl is-active --quiet udp2raw-wg 2>/dev/null && tcp_unit=1
tcp_port=""
tcp_bridge_target=""
if [ -f "/etc/systemd/system/udp2raw-wg.service" ]; then
  tcp_port=$(usk_wg_tcp_port 2>/dev/null || true)
  tcp_bridge_target=$(grep -oE '127\.0\.0\.1:[0-9]+' "/etc/systemd/system/udp2raw-wg.service" 2>/dev/null | head -1)
fi

issues=()
[ ! -f "$conf" ] && issues+=("wireguard_not_installed")
wg show wg0 >/dev/null 2>&1 || issues+=("wg0_down")
[ "$peer_count" -eq 0 ] 2>/dev/null && issues+=("no_peers_on_wg0")
[ -z "$conf_port" ] && issues+=("listen_port_missing_in_conf")
[ -n "$conf_port" ] && [ -n "$runtime_port" ] && [ "$conf_port" != "$runtime_port" ] && issues+=("listen_port_mismatch")
[ "$server_key_ok" -eq 0 ] && issues+=("server_public_key_missing")
[ "$registry_count" -gt 0 ] 2>/dev/null && [ "$peer_count" -eq 0 ] 2>/dev/null && issues+=("registry_not_synced_to_wg0")
[ "$ip_fwd" != "1" ] && issues+=("ip_forward_off")
[ "$nat_pkts" = "0" ] 2>/dev/null && [ "$peer_count" -gt 0 ] 2>/dev/null && issues+=("no_nat_traffic_yet")
[ "$tcp_unit" -eq 0 ] 2>/dev/null && [ -f "/etc/systemd/system/udp2raw-wg.service" ] && issues+=("udp2raw_service_down")
[ -n "$tcp_bridge_target" ] && [ -n "$listen_port" ] && [ "$tcp_bridge_target" != "127.0.0.1:${listen_port}" ] && issues+=("tcp_bridge_port_mismatch")

echo "USK_WG_DIAG"
echo "wg0_up=$(wg show wg0 >/dev/null 2>&1 && echo 1 || echo 0)"
echo "listen_port_conf=${conf_port:-missing}"
echo "listen_port_runtime=${runtime_port:-unknown}"
echo "server_public_key=${server_key_ok}"
echo "main_iface=${iface}"
echo "subnet=${subnet}"
echo "peer_count=${peer_count:-0}"
echo "registry_clients=${registry_count:-0}"
echo "ip_forward=${ip_fwd:-0}"
echo "nat_packets_subnet=${nat_pkts:-0}"
echo "forward_packets_wg0=${fwd_wg:-0}"
echo "udp2raw_binary=${udp2raw_ok}"
echo "udp2raw_service=${tcp_unit}"
echo "tcp_bridge_port=${tcp_port:-none}"
echo "tcp_bridge_target=${tcp_bridge_target:-none}"
if [ ${#issues[@]} -gt 0 ]; then
  echo "issues=$(IFS=,; echo "${issues[*]}")"
else
  echo "issues=none"
fi
echo "--- wg show wg0 ---"
wg show wg0 2>&1
echo "--- wg0.conf peers ---"
grep -E '^\[Peer\]|^# |^PublicKey|^AllowedIPs' "$conf" 2>/dev/null || true
