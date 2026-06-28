#!/bin/bash
# Optional: Prometheus xray-exporter + sample scrape config (Grafana dashboards).
# The unlimitsky panel does NOT need this — it reads Xray Stats API directly (127.0.0.1:10085).
# Use only if you want Grafana/Prometheus monitoring on the same VPS.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$DIR/xray-common.sh" 2>/dev/null || true
# shellcheck disable=SC1091
source "$DIR/xray-stats-state.sh" 2>/dev/null || true

if [ "$EUID" -ne 0 ]; then
  echo "Run as root: sudo bash $0"
  exit 1
fi

XRAY_API="${USK_XRAY_STATS_API:-127.0.0.1:10085}"
EXPORTER_PORT="${USK_XRAY_EXPORTER_PORT:-9550}"
BIN="/usr/local/bin/xray-exporter"
ARCH="$(uname -m)"
case "$ARCH" in
  x86_64|amd64) ASSET="xray-exporter_linux_amd64" ;;
  aarch64|arm64) ASSET="xray-exporter_linux_arm64" ;;
  *)
    echo "Unsupported arch: $ARCH"
    exit 1
    ;;
esac

if [ -f "${XRAY_CFG:-/usr/local/etc/xray/config.json}" ]; then
  bash "$DIR/xray-fix-stats-api.sh" || true
fi

echo "[*] Downloading anatolykopyl/xray-exporter ..."
curl -fsSL -o "$BIN" "https://github.com/anatolykopyl/xray-exporter/releases/latest/download/${ASSET}"
chmod +x "$BIN"

cat >/etc/systemd/system/xray-exporter.service <<EOF
[Unit]
Description=Xray Exporter for Prometheus (optional — panel uses Stats API directly)
After=network.target xray.service

[Service]
Type=simple
ExecStart=${BIN} --xray-endpoint ${XRAY_API}
Restart=always
RestartSec=10
User=nobody
Group=nogroup

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable xray-exporter
systemctl restart xray-exporter

mkdir -p /etc/prometheus 2>/dev/null || true
if [ ! -f /etc/prometheus/prometheus.yml ]; then
  cat >/etc/prometheus/prometheus-unlimitsky-xray.yml.example <<EOF
# Add to prometheus.yml scrape_configs:
scrape_configs:
  - job_name: xray
    static_configs:
      - targets: ['127.0.0.1:${EXPORTER_PORT}']
EOF
fi

echo ""
echo "OK: xray-exporter running on http://127.0.0.1:${EXPORTER_PORT}/metrics"
echo "    Xray Stats API: ${XRAY_API}"
echo "    Panel usage sync: still via bin/collect-usage-stats.sh (no Prometheus required)"
