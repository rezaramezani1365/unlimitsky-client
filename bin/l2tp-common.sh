#!/bin/bash
# Shared L2TP/IPsec config (Windows + iOS compatible)

usk_l2tp_detect_ip() {
  local ip=""
  ip=$(curl -4 -s --max-time 5 ifconfig.me 2>/dev/null || true)
  if [ -z "$ip" ]; then
    ip=$(hostname -I 2>/dev/null | awk '{print $1}')
  fi
  if [ -z "$ip" ]; then
    ip=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src") print $(i+1)}')
  fi
  echo "${ip:-}"
}

usk_l2tp_main_iface() {
  local iface
  iface=$(ip -4 route show default 2>/dev/null | awk '{print $5; exit}')
  if [ -n "$iface" ] && [ "$iface" != "lo" ]; then
    echo "$iface"
    return 0
  fi
  iface=$(ip route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="dev"){print $(i+1); exit}}')
  echo "${iface:-eth0}"
}

usk_l2tp_write_ipsec() {
  local psk="$1"
  local server_ip="${2:-}"
  if [ -z "$server_ip" ]; then
    server_ip=$(usk_l2tp_detect_ip)
  fi

  local leftid_line="  leftid=%any"
  if [ -n "$server_ip" ]; then
    leftid_line="  leftid=${server_ip}"
  fi

  cat > /etc/ipsec.conf <<IPSEC
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
${leftid_line}
  leftprotoport=17/1701
  right=%any
  rightid=%any
  rightprotoport=17/%any
  ike=3des-sha1-modp1024,aes128-sha1-modp1024,aes256-sha1-modp2048,aes128-sha1-modp2048!
  esp=3des-sha1,aes128-sha1,aes256-sha1!
  forceencaps=yes
  fragmentation=yes
  dpdaction=clear
  dpddelay=300s
  keyingtries=3
  rekey=no
IPSEC

  cat > /etc/ipsec.secrets <<SECRETS
%any %any : PSK "${psk}"
SECRETS
  chmod 600 /etc/ipsec.secrets
}

usk_l2tp_write_strongswan_compat() {
  mkdir -p /etc/strongswan.d/charon

  cat > /etc/strongswan.d/charon/compat-l2tp.conf <<'SSW'
# Windows/iOS L2TP needs IKEv1 + legacy ciphers (3DES-SHA1-MODP1024)
charon {
  install_routes = no
  send_vendor_id = yes
  accept_private_algs = yes
}
SSW

  cat > /etc/strongswan.d/charon/unlimitsky-l2tp-ciphers.conf <<'CIPH'
# Ensure weak-but-required algorithms stay available for Windows L2TP
openssl {
  load = yes
}
aes {
  load = yes
  3des = yes
}
sha1 {
  load = yes
}
sha2 {
  load = yes
}
random {
  load = yes
}
nonce {
  load = yes
}
hmac {
  load = yes
}
kdf {
  load = yes
}
CIPH

  if [ -f /etc/strongswan.d/charon/aes.conf ]; then
    grep -q '3des = yes' /etc/strongswan.d/charon/aes.conf 2>/dev/null \
      || sed -i '/aes = yes/a\    3des = yes' /etc/strongswan.d/charon/aes.conf 2>/dev/null || true
  fi
}

usk_l2tp_write_xl2tpd() {
  cat > /etc/xl2tpd/xl2tpd.conf <<'L2TP'
[global]
listen-addr = 0.0.0.0
port = 1701
access control = no

[lns default]
ip range = 10.10.10.100-10.10.10.200
local ip = 10.10.10.1
exclusive = yes
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
  # xl2tpd on Ubuntu 22.04+ may need this for kernel IPsec SAref
  sysctl -w net.ipv4.conf.all.disable_policy=0 >/dev/null 2>&1 || true
}

usk_l2tp_setup_iptables() {
  local subnet="${1:-10.10.10.0/24}"
  local iface
  iface=$(usk_l2tp_main_iface)

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
    || iptables -t nat -I POSTROUTING -s "$subnet" -o "$iface" -j MASQUERADE 2>/dev/null || true
}

usk_l2tp_ensure_ufw() {
  ensure_ufw_port 500 udp ipsec-ike
  ensure_ufw_port 4500 udp ipsec-nat-t
  ensure_ufw_port 1701 udp l2tp
}

usk_l2tp_ipsec_running() {
  if command -v ipsec >/dev/null 2>&1; then
    ipsec status >/dev/null 2>&1 && return 0
  fi
  for svc in strongswan-starter strongswan ipsec; do
    if systemctl is-active --quiet "$svc" 2>/dev/null; then
      return 0
    fi
  done
  return 1
}

usk_l2tp_xl2tpd_running() {
  systemctl is-active --quiet xl2tpd 2>/dev/null
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
    ipsec restart 2>/dev/null || true
  fi
  systemctl unmask xl2tpd 2>/dev/null || true
  systemctl enable xl2tpd 2>/dev/null || true
  systemctl restart xl2tpd 2>/dev/null || systemctl start xl2tpd 2>/dev/null || true
}

usk_l2tp_verify_services() {
  usk_l2tp_ipsec_running && usk_l2tp_xl2tpd_running
}
