#!/bin/bash
# Install Xray (VLESS + VMess) on Ubuntu
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"

apt-get update -qq
apt-get install -y curl unzip

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
      }
    }
  ],
  "outbounds": [{ "protocol": "freedom" }]
}
EOF

systemctl enable xray
systemctl restart xray

ensure_ufw_port 443 tcp xray-vless
ensure_ufw_port 8443 tcp xray-vmess
usk_ok
