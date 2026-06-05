#!/bin/bash
# Shared L2TP/IPsec config (Windows + iOS compatible)

usk_l2tp_write_ipsec() {
  local psk="$1"
  cat > /etc/ipsec.conf <<'IPSEC'
config setup
  charondebug="ike 1, knl 1, cfg 0"
  uniqueids=no
  strictcrlpolicy=no

conn L2TP-PSK
  keyexchange=ikev1
  auto=add
  authby=secret
  type=transport
  left=%defaultroute
  leftid=%any
  leftprotoport=17/1701
  right=%any
  rightid=%any
  rightprotoport=17/%any
  ike=3des-sha1-modp1024,aes128-sha1-modp1024!
  esp=3des-sha1,aes128-sha1!
  forceencaps=yes
  dpdaction=clear
  dpddelay=300s
  rekey=no
IPSEC

  cat > /etc/ipsec.secrets <<SECRETS
%any %any : PSK "${psk}"
: PSK "${psk}"
SECRETS
  chmod 600 /etc/ipsec.secrets
}

usk_l2tp_write_strongswan_compat() {
  mkdir -p /etc/strongswan.d/charon
  cat > /etc/strongswan.d/charon/compat-l2tp.conf <<'SSW'
# Allow legacy IKEv1 ciphers required by Windows L2TP client
charon {
  load_modular = yes
}
SSW

  if [ -f /etc/strongswan.d/charon/aes.conf ]; then
    sed -i 's/aes = yes/aes = yes\n    3des = yes/' /etc/strongswan.d/charon/aes.conf 2>/dev/null || true
  fi
}

usk_l2tp_write_xl2tpd() {
  cat > /etc/xl2tpd/xl2tpd.conf <<'L2TP'
[global]
listen-addr = 0.0.0.0
port = 1701

[lns default]
ip range = 10.10.10.100-10.10.10.200
local ip = 10.10.10.1
require authentication = yes
refuse pap = yes
require chap = yes
name = l2tpd
ppp debug = no
pppoptfile = /etc/ppp/options.xl2tpd
length bit = yes
L2TP
}

usk_l2tp_write_ppp_options() {
  cat > /etc/ppp/options.xl2tpd <<'PPP'
ipcp-accept-local
ipcp-accept-remote
require-mschap-v2
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
lcp-echo-interval 30
lcp-echo-failure 4
PPP
}

usk_l2tp_sysctl() {
  sysctl -w net.ipv4.ip_forward=1 >/dev/null 2>&1 || true
  grep -q 'net.ipv4.ip_forward=1' /etc/sysctl.conf 2>/dev/null \
    || echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf
  sysctl -w net.ipv4.conf.all.accept_redirects=0 >/dev/null 2>&1 || true
  sysctl -w net.ipv4.conf.all.send_redirects=0 >/dev/null 2>&1 || true
  sysctl -w net.ipv4.conf.default.rp_filter=0 >/dev/null 2>&1 || true
  sysctl -w net.ipv4.conf.all.rp_filter=0 >/dev/null 2>&1 || true
}

usk_l2tp_setup_iptables() {
  local subnet="${1:-10.10.10.0/24}"
  local iface
  iface=$(ip route get 1.1.1.1 2>/dev/null | awk '{print $5; exit}')
  iface="${iface:-eth0}"

  if ! command -v iptables >/dev/null 2>&1; then
    return 0
  fi

  iptables -C INPUT -p udp --dport 500 -j ACCEPT 2>/dev/null \
    || iptables -I INPUT -p udp --dport 500 -j ACCEPT 2>/dev/null || true
  iptables -C INPUT -p udp --dport 4500 -j ACCEPT 2>/dev/null \
    || iptables -I INPUT -p udp --dport 4500 -j ACCEPT 2>/dev/null || true
  iptables -C INPUT -p udp --dport 1701 -j ACCEPT 2>/dev/null \
    || iptables -I INPUT -p udp --dport 1701 -j ACCEPT 2>/dev/null || true
  iptables -C INPUT -p esp -j ACCEPT 2>/dev/null \
    || iptables -I INPUT -p esp -j ACCEPT 2>/dev/null || true
  iptables -C INPUT -p ah -j ACCEPT 2>/dev/null \
    || iptables -I INPUT -p ah -j ACCEPT 2>/dev/null || true
  iptables -C FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null \
    || iptables -I FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null || true
  iptables -C FORWARD -s "$subnet" -j ACCEPT 2>/dev/null \
    || iptables -I FORWARD -s "$subnet" -j ACCEPT 2>/dev/null || true
  iptables -C FORWARD -d "$subnet" -j ACCEPT 2>/dev/null \
    || iptables -I FORWARD -d "$subnet" -j ACCEPT 2>/dev/null || true
  iptables -t nat -C POSTROUTING -s "$subnet" -o "$iface" -j MASQUERADE 2>/dev/null \
    || iptables -t nat -A POSTROUTING -s "$subnet" -o "$iface" -j MASQUERADE 2>/dev/null || true
}

usk_l2tp_restart_services() {
  for svc in strongswan-starter strongswan ipsec; do
    if systemctl cat "$svc" >/dev/null 2>&1; then
      systemctl enable "$svc" 2>/dev/null || true
      systemctl restart "$svc" 2>/dev/null || systemctl start "$svc" 2>/dev/null || true
      break
    fi
  done
  if command -v ipsec >/dev/null 2>&1; then
    ipsec rereadsecrets 2>/dev/null || true
    ipsec reload 2>/dev/null || true
  fi
  systemctl unmask xl2tpd 2>/dev/null || true
  systemctl enable xl2tpd 2>/dev/null || true
  systemctl restart xl2tpd 2>/dev/null || systemctl start xl2tpd 2>/dev/null || true
}
