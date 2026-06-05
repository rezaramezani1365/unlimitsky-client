#!/bin/bash
# Install Cisco AnyConnect (Ocserv) on Ubuntu
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"

CISCO_PORT="${1:-${USK_CISCO_PORT:-4443}}"
CISCO_PORT=$(echo "$CISCO_PORT" | tr -dc '0-9')
if [ -z "$CISCO_PORT" ] || [ "$CISCO_PORT" -lt 1 ] || [ "$CISCO_PORT" -gt 65535 ] 2>/dev/null; then
  CISCO_PORT=4443
fi

apt-get update -qq
apt-get install -y ocserv gnutls-bin

mkdir -p /etc/ocserv
touch /etc/ocserv/ocpasswd
chmod 600 /etc/ocserv/ocpasswd

cd /etc/ocserv

if [ ! -f server-cert.pem ] || [ ! -f server-key.pem ]; then
  cat > ca.tmpl <<'EOF'
cn = "unlimitsky CA"
organization = "unlimitsky"
expiration_days = 3650
ca
signing_key
cert_signing_key
EOF
  certtool --generate-privkey --outfile ca-key.pem
  certtool --generate-self-signed --load-privkey ca-key.pem --template ca.tmpl --outfile ca-cert.pem

  cat > server.tmpl <<'EOF'
cn = "unlimitsky VPN"
organization = "unlimitsky"
expiration_days = 3650
signing_key
encryption_key
tls_ask_pass = none
EOF
  certtool --generate-privkey --outfile server-key.pem
  certtool --generate-certificate --load-privkey server-key.pem \
    --load-ca-certificate ca-cert.pem --load-ca-privkey ca-key.pem \
    --template server.tmpl --outfile server-cert.pem
fi

MAIN_IFACE=$(ip route get 1.1.1.1 2>/dev/null | awk '{print $5; exit}')
MAIN_IFACE="${MAIN_IFACE:-eth0}"

cat > /etc/ocserv/ocserv.conf <<OCFG
auth = "plain[passwd=/etc/ocserv/ocpasswd,otp=disabled]"
tcp-port = ${CISCO_PORT}
udp-port = ${CISCO_PORT}
run-as-user = nobody
run-as-group = nogroup
socket-file = /var/run/ocserv.socket
server-cert = /etc/ocserv/server-cert.pem
server-key = /etc/ocserv/server-key.pem
isolate-workers = true
max-clients = 512
max-same-clients = 0
keepalive = 32400
try-mtu-discovery = true
device = vpns
ipv4-network = 192.168.100.0/24
ipv4-netmask = 255.255.255.0
dns = 1.1.1.1
dns = 8.8.8.8
route = default
cisco-client-compat = true
dtls-legacy = true
OCFG

sysctl -w net.ipv4.ip_forward=1
grep -q 'net.ipv4.ip_forward=1' /etc/sysctl.conf || echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf

if command -v iptables >/dev/null 2>&1; then
  iptables -C INPUT -p tcp --dport "$CISCO_PORT" -j ACCEPT 2>/dev/null \
    || iptables -I INPUT -p tcp --dport "$CISCO_PORT" -j ACCEPT 2>/dev/null || true
  iptables -C INPUT -p udp --dport "$CISCO_PORT" -j ACCEPT 2>/dev/null \
    || iptables -I INPUT -p udp --dport "$CISCO_PORT" -j ACCEPT 2>/dev/null || true
  iptables -C FORWARD -i vpns+ -j ACCEPT 2>/dev/null \
    || iptables -I FORWARD -i vpns+ -j ACCEPT 2>/dev/null || true
  iptables -C FORWARD -o vpns+ -j ACCEPT 2>/dev/null \
    || iptables -I FORWARD -o vpns+ -j ACCEPT 2>/dev/null || true
  iptables -t nat -C POSTROUTING -s 192.168.100.0/24 -o "$MAIN_IFACE" -j MASQUERADE 2>/dev/null \
    || iptables -t nat -I POSTROUTING -s 192.168.100.0/24 -o "$MAIN_IFACE" -j MASQUERADE 2>/dev/null || true
fi

ensure_ufw_port "$CISCO_PORT" tcp cisco-ocserv
ensure_ufw_port "$CISCO_PORT" udp cisco-ocserv

systemctl unmask ocserv 2>/dev/null || true
systemctl enable ocserv
systemctl restart ocserv

if ! systemctl is-active ocserv >/dev/null 2>&1; then
  usk_fail "cisco_service_failed"
fi

echo "USK_META:port=${CISCO_PORT}"
usk_ok
