#!/bin/bash
# Install L2TP/IPsec (xl2tpd + strongSwan) on Ubuntu
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"
set +e

USK_ROOT="${1:-/var/www/unlimitsky}"
L2TP_SUBNET="10.10.10.0/24"

usk_l2tp_main_iface() {
  ip route get 1.1.1.1 2>/dev/null | awk '{print $5; exit}'
}

usk_l2tp_setup_nat() {
  local iface
  iface=$(usk_l2tp_main_iface)
  iface="${iface:-eth0}"

  sysctl -w net.ipv4.ip_forward=1 >/dev/null 2>&1 || true
  grep -q 'net.ipv4.ip_forward=1' /etc/sysctl.conf 2>/dev/null \
    || echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf

  if command -v iptables >/dev/null 2>&1; then
    iptables -C FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null \
      || iptables -I FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null || true
    iptables -C FORWARD -s "$L2TP_SUBNET" -j ACCEPT 2>/dev/null \
      || iptables -I FORWARD -s "$L2TP_SUBNET" -j ACCEPT 2>/dev/null || true
    iptables -C FORWARD -d "$L2TP_SUBNET" -j ACCEPT 2>/dev/null \
      || iptables -I FORWARD -d "$L2TP_SUBNET" -j ACCEPT 2>/dev/null || true
    iptables -t nat -C POSTROUTING -s "$L2TP_SUBNET" -o "$iface" -j MASQUERADE 2>/dev/null \
      || iptables -t nat -A POSTROUTING -s "$L2TP_SUBNET" -o "$iface" -j MASQUERADE 2>/dev/null || true
  fi

  if command -v ufw >/dev/null 2>&1; then
    if grep -q 'DEFAULT_FORWARD_POLICY="DROP"' /etc/default/ufw 2>/dev/null; then
      sed -i 's/DEFAULT_FORWARD_POLICY="DROP"/DEFAULT_FORWARD_POLICY="ACCEPT"/' /etc/default/ufw 2>/dev/null || true
    fi
    ufw route allow in on ppp+ out on "$iface" >/dev/null 2>&1 || true
    ufw route allow in on "$iface" out on ppp+ >/dev/null 2>&1 || true
  fi
}

usk_l2tp_restart_ipsec() {
  for svc in strongswan-starter strongswan ipsec; do
    if systemctl cat "$svc" >/dev/null 2>&1; then
      systemctl enable "$svc" 2>/dev/null || true
      systemctl restart "$svc" 2>/dev/null || systemctl start "$svc" 2>/dev/null || true
      return 0
    fi
  done
  if command -v ipsec >/dev/null 2>&1; then
    ipsec restart 2>/dev/null || true
  fi
}

apt-get update -qq
if ! apt-get install -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" strongswan xl2tpd ppp; then
  usk_fail "l2tp_packages_failed"
fi

if [ -f /etc/unlimitsky-l2tp.psk ]; then
  PSK=$(tr -d '\n\r' < /etc/unlimitsky-l2tp.psk)
else
  PSK="UnlimitSky$(openssl rand -hex 8)"
fi

cat > /etc/ipsec.conf <<'IPSEC'
config setup
  charondebug="ike 1, knl 1, cfg 0"
  uniqueids=no

conn L2TP-PSK
  keyexchange=ikev1
  authby=secret
  type=transport
  left=%defaultroute
  leftprotoport=17/1701
  right=%any
  rightprotoport=17/%any
  ike=aes256-sha1-modp1024,aes128-sha1-modp1024!
  esp=aes128-sha1-modp1024,aes256-sha1,aes128-sha1!
  forceencaps=yes
  auto=add
IPSEC

echo ": PSK \"$PSK\"" > /etc/ipsec.secrets
chmod 600 /etc/ipsec.secrets

cat > /etc/xl2tpd/xl2tpd.conf <<'L2TP'
[global]
listen-addr = 0.0.0.0
[lns default]
ip range = 10.10.10.100-10.10.10.200
local ip = 10.10.10.1
require authentication = no
refuse pap = yes
require chap = yes
name = UnlimitSky-L2TP
ppp debug = no
pppoptfile = /etc/ppp/options.xl2tpd
length bit = yes
L2TP

cat > /etc/ppp/options.xl2tpd <<'PPP'
ipcp-accept-local
ipcp-accept-remote
ms-dns 1.1.1.1
ms-dns 8.8.8.8
noccp
auth
crtscts
hide-password
modem
mtu 1280
mru 1280
lock
connect-delay 5000
proxyarp
PPP

touch /etc/ppp/chap-secrets
chmod 600 /etc/ppp/chap-secrets

echo "$PSK" > /etc/unlimitsky-l2tp.psk
chmod 600 /etc/unlimitsky-l2tp.psk

usk_mark_installed l2tp "$USK_ROOT"

usk_l2tp_setup_nat
usk_l2tp_restart_ipsec

systemctl unmask xl2tpd 2>/dev/null || true
systemctl enable xl2tpd 2>/dev/null || true
systemctl restart xl2tpd 2>/dev/null || systemctl start xl2tpd 2>/dev/null || true
sleep 1
if ! systemctl is-active xl2tpd >/dev/null 2>&1; then
  systemctl start xl2tpd 2>/dev/null || true
  sleep 1
fi

ensure_ufw_port 500 udp ipsec-ike
ensure_ufw_port 4500 udp ipsec-nat-t
ensure_ufw_port 1701 udp l2tp

if [ ! -f /etc/xl2tpd/xl2tpd.conf ] || [ ! -f /etc/ppp/options.xl2tpd ]; then
  usk_fail "l2tp_config_failed"
fi

echo "USK_META:ports=500,4500,1701;port=1701"
usk_ok
