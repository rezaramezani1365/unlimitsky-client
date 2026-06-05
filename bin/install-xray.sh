#!/bin/bash
# Install Xray (VLESS + VMess) on Ubuntu
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"

apt-get update -qq
apt-get install -y curl unzip jq

bash -c "$(curl -L https://github.com/XTLS/Xray-install/raw/main/install-release.sh)" @ install

UUID=$(cat /proc/sys/kernel/random/uuid)
mkdir -p /usr/local/etc/xray

cat > /usr/local/etc/xray/config.json <<EOF
{
  "log": { "loglevel": "warning" },
  "inbounds": [
    {
      "port": 443,
      "protocol": "vless",
      "settings": {
        "clients": [{ "id": "$UUID", "flow": "xtls-rprx-vision" }],
        "decryption": "none"
      },
      "streamSettings": { "network": "tcp", "security": "none" }
    },
    {
      "port": 8443,
      "protocol": "vmess",
      "settings": {
        "clients": [{ "id": "$UUID", "alterId": 0 }]
      },
      "streamSettings": { "network": "tcp" }
    }
  ],
  "outbounds": [{ "protocol": "freedom" }]
}
EOF

systemctl enable xray 2>/dev/null || systemctl enable xray.service 2>/dev/null || true
systemctl restart xray 2>/dev/null || systemctl restart xray.service 2>/dev/null || usk_fail "xray_service_failed"

[ -f /usr/local/etc/xray/config.json ] || usk_fail "xray_config_missing"
jq -e '.inbounds | length >= 2' /usr/local/etc/xray/config.json >/dev/null 2>&1 || usk_fail "xray_config_invalid"

ensure_ufw_port 443 tcp xray-vless
ensure_ufw_port 8443 tcp xray-vmess
usk_ok
