#!/bin/bash
# L2TP/IPsec health report — run on server: sudo bash /var/www/unlimitsky/bin/diagnose-l2tp.sh
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"
source "$DIR/l2tp-common.sh"
set +e

subnet="10.10.10.0/24"
iface=$(usk_l2tp_main_iface)
iface="${iface:-eth0}"
server_ip=$(usk_l2tp_detect_ip)

psk_ok=0
[ -f /etc/unlimitsky-l2tp.psk ] && [ -s /etc/unlimitsky-l2tp.psk ] && psk_ok=1

xl2tpd_conf=0
[ -f /etc/xl2tpd/xl2tpd.conf ] && xl2tpd_conf=1

ipsec_conf=0
[ -f /etc/ipsec.conf ] && ipsec_conf=1

chap_users=0
[ -f /etc/ppp/chap-secrets ] && chap_users=$(grep -cE '^[^#[:space:]]+ ' /etc/ppp/chap-secrets 2>/dev/null || echo 0)
chap_users=$(echo "$chap_users" | tr -dc '0-9')

ip_fwd=$(sysctl -n net.ipv4.ip_forward 2>/dev/null)

ipsec_up=0
usk_l2tp_ipsec_running && ipsec_up=1

xl2tpd_up=0
usk_l2tp_xl2tpd_running && xl2tpd_up=1

nat_pkts=0
if command -v iptables >/dev/null 2>&1; then
  nat_pkts=$(iptables -t nat -L POSTROUTING -n -v 2>/dev/null | awk -v s="$subnet" '$0 ~ s {print $1; exit}')
  nat_pkts=$(echo "$nat_pkts" | tr -dc '0-9')
fi

ufw_active=0
ufw_500=0
ufw_4500=0
ufw_1701=0
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
  ufw_active=1
  ufw status 2>/dev/null | grep -qE '500/udp' && ufw_500=1
  ufw status 2>/dev/null | grep -qE '4500/udp' && ufw_4500=1
  ufw status 2>/dev/null | grep -qE '1701/udp' && ufw_1701=1
fi

xl2tpd_ver=""
if command -v xl2tpd >/dev/null 2>&1; then
  xl2tpd_ver=$(xl2tpd -v 2>&1 | head -1)
fi

issues=()
[ "$psk_ok" -eq 0 ] && issues+=("psk_missing")
[ "$xl2tpd_conf" -eq 0 ] && issues+=("xl2tpd_conf_missing")
[ "$ipsec_conf" -eq 0 ] && issues+=("ipsec_conf_missing")
[ "$ipsec_up" -eq 0 ] && issues+=("ipsec_down")
[ "$xl2tpd_up" -eq 0 ] && issues+=("xl2tpd_down")
[ "$ip_fwd" != "1" ] && issues+=("ip_forward_off")
[ "$ufw_active" -eq 1 ] && { [ "$ufw_500" -eq 0 ] || [ "$ufw_4500" -eq 0 ] || [ "$ufw_1701" -eq 0 ]; } \
  && issues+=("ufw_ports_missing")
[ -z "$server_ip" ] && issues+=("server_ip_unknown")
echo "$xl2tpd_ver" | grep -q '1.3.16' 2>/dev/null && issues+=("xl2tpd_1.3.16_known_bug")

echo "USK_L2TP_DIAG"
echo "server_ip=${server_ip:-unknown}"
echo "main_iface=${iface}"
echo "subnet=${subnet}"
echo "psk_file=${psk_ok}"
echo "ipsec_running=${ipsec_up}"
echo "xl2tpd_running=${xl2tpd_up}"
echo "ip_forward=${ip_fwd:-0}"
echo "nat_packets_subnet=${nat_pkts:-0}"
echo "chap_users=${chap_users:-0}"
echo "ufw_active=${ufw_active}"
echo "ufw_udp_500=${ufw_500}"
echo "ufw_udp_4500=${ufw_4500}"
echo "ufw_udp_1701=${ufw_1701}"
echo "xl2tpd_version=${xl2tpd_ver:-unknown}"
if [ ${#issues[@]} -gt 0 ]; then
  echo "issues=$(IFS=,; echo "${issues[*]}")"
else
  echo "issues=none"
fi
echo "--- ipsec status ---"
ipsec status 2>&1 || true
echo "--- xl2tpd listeners ---"
ss -ulnp 2>/dev/null | grep -E ':500|:4500|:1701' || netstat -ulnp 2>/dev/null | grep -E ':500|:4500|:1701' || true
echo "--- iptables NAT (L2TP subnet) ---"
iptables -t nat -L POSTROUTING -n -v 2>/dev/null | grep -F "$subnet" || true
