#!/bin/bash
# Install unlimitsky Node worker on a remote VPS and register with the main (Hub) panel.
# No per-node token — use Hub IP/port + this server's SSH user/password + register password from Hub admin.
#
# Interactive:
#   curl -fsSL http://HUB_IP:PORT/bin/install-node.sh | sudo bash -s
#
# Non-interactive:
#   sudo bash install-node.sh \
#     --hub-ip 1.2.3.4 --hub-port 8082 \
#     --register-secret 'SECRET_FROM_PANEL' \
#     --ssh-user root --ssh-pass 'YourRootPass' \
#     --name germany-1 --connect-host vpn.example.com
#
set -euo pipefail

NODE_ROOT="${USK_NODE_ROOT:-/opt/unlimitsky-node}"
DATA_ROOT="${USK_DATA_ROOT:-/var/lib/unlimitsky}"
HUB_IP=""
HUB_PORT="8082"
REGISTER_SECRET=""
SSH_USER=""
SSH_PASS=""
NODE_NAME=""
CONNECT_HOST=""
SSH_PORT="22"
HUB_SCHEME="http"

usage() {
  cat <<'HELP'
unlimitsky Node installer (remote VPS)

Required:
  --hub-ip IP           Main panel server IP or domain
  --hub-port PORT       Main panel HTTP port (default 8082)
  --register-secret S   Registration password (Admin → Nodes page on Hub)
  --ssh-user USER       SSH user on THIS server (root recommended)
  --ssh-pass PASS       SSH password on THIS server (Hub uses it for provisioning)

Optional:
  --name NAME           Node display name (default: hostname)
  --connect-host HOST   IP/domain in buyer configs (default: this server's public IP)
  --ssh-port PORT       SSH port on this server (default 22)
  --hub-scheme http|https

Example:
  sudo bash install-node.sh --hub-ip 1.2.3.4 --hub-port 8082 \
    --register-secret abc123 --ssh-user root --ssh-pass 'secret' --name node-de
HELP
}

while [ $# -gt 0 ]; do
  case "$1" in
    --hub-ip) HUB_IP="$2"; shift 2 ;;
    --hub-port) HUB_PORT="$2"; shift 2 ;;
    --register-secret) REGISTER_SECRET="$2"; shift 2 ;;
    --ssh-user) SSH_USER="$2"; shift 2 ;;
    --ssh-pass) SSH_PASS="$2"; shift 2 ;;
    --name) NODE_NAME="$2"; shift 2 ;;
    --connect-host) CONNECT_HOST="$2"; shift 2 ;;
    --ssh-port) SSH_PORT="$2"; shift 2 ;;
    --hub-scheme) HUB_SCHEME="$2"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1"; usage; exit 1 ;;
  esac
done

if [ "$EUID" -ne 0 ]; then
  echo "USK_ERR: run_as_root"
  exit 1
fi

prompt_if_empty() {
  local varname="$1"
  local prompt="$2"
  local default="${3:-}"
  local current
  current=$(eval "echo \${$varname}")
  if [ -n "$current" ]; then
    return 0
  fi
  if [ -n "$default" ]; then
    read -r -p "$prompt [$default]: " input
    input=${input:-$default}
  else
    read -r -p "$prompt: " input
  fi
  eval "$varname=\"\$input\""
}

prompt_secret() {
  local varname="$1"
  local prompt="$2"
  local current
  current=$(eval "echo \${$varname}")
  if [ -n "$current" ]; then
    return 0
  fi
  read -r -s -p "$prompt: " input
  echo ""
  eval "$varname=\"\$input\""
}

detect_public_ip() {
  curl -4 -s --max-time 8 ifconfig.me 2>/dev/null \
    || curl -4 -s --max-time 8 icanhazip.com 2>/dev/null \
    || hostname -I 2>/dev/null | awk '{print $1}'
}

echo "=== unlimitsky Node installer ==="
prompt_if_empty HUB_IP "Hub panel IP or domain"
prompt_if_empty HUB_PORT "Hub panel port" "8082"
prompt_if_empty REGISTER_SECRET "Hub registration password (from Admin → Nodes)"
prompt_if_empty SSH_USER "SSH username on THIS server" "root"
prompt_secret SSH_PASS "SSH password on THIS server"
prompt_if_empty NODE_NAME "Node name" "$(hostname -s 2>/dev/null || echo node)"
if [ -z "$CONNECT_HOST" ]; then
  def_ip=$(detect_public_ip)
  prompt_if_empty CONNECT_HOST "Connect address for buyers (IP or domain)" "${def_ip:-}"
fi

for req in HUB_IP HUB_PORT REGISTER_SECRET SSH_USER SSH_PASS NODE_NAME CONNECT_HOST; do
  val=$(eval "echo \${$req}")
  if [ -z "$val" ]; then
    echo "USK_ERR: missing_${req,,}"
    exit 1
  fi
done

NEED_APT=0
for cmd in curl jq sshd; do
  command -v "$cmd" >/dev/null 2>&1 || NEED_APT=1
done
if [ "$NEED_APT" -eq 1 ]; then
  apt-get update -qq
  apt-get install -y curl jq ca-certificates openssh-server
fi

systemctl enable ssh 2>/dev/null || systemctl enable sshd 2>/dev/null || true
systemctl start ssh 2>/dev/null || systemctl start sshd 2>/dev/null || true

mkdir -p "$NODE_ROOT/bin" "$DATA_ROOT/xray" /usr/local/etc/xray
chmod 755 "$NODE_ROOT" "$DATA_ROOT"

HUB_BASE="${HUB_SCHEME}://${HUB_IP}:${HUB_PORT}"
echo "[*] Downloading worker scripts from Hub (${HUB_BASE})..."

BIN_LIST=(
  usk-common.sh
  provision-common.sh
  xray-common.sh
  xray-stats-state.sh
  enforce-xray-iplimit.sh
  install-fail2ban-iplimit.sh
  openvpn-common.sh
  collect-usage-stats.sh
  enforce-connection-limits.sh
  remove-user-xray.sh
  remove-user-wireguard.sh
  remove-user-openvpn.sh
  add-user-xray.sh
  disable-user-xray.sh
  enable-user-xray.sh
  remove-user-xray.sh
  repair-xray.sh
  xray-fix-connectivity.sh
  refresh-xray-client-links.sh
)

for f in "${BIN_LIST[@]}"; do
  if ! curl -fsSL "${HUB_BASE}/bin/${f}" -o "${NODE_ROOT}/bin/${f}"; then
    echo "USK_ERR: download_failed file=${f}"
    exit 1
  fi
  chmod +x "${NODE_ROOT}/bin/${f}"
done

# Allow Hub SSH user to run provisioning without interactive sudo password
SUDOERS="/etc/sudoers.d/unlimitsky-node"
cat > "$SUDOERS" <<EOF
# unlimitsky Hub remote provisioning
${SSH_USER} ALL=(root) NOPASSWD: /bin/bash ${NODE_ROOT}/bin/*.sh
EOF
chmod 440 "$SUDOERS"

# Optional: install Xray if missing
if ! command -v xray >/dev/null 2>&1 && [ ! -x /usr/local/bin/xray ]; then
  echo "[*] Xray not found — installing (VLESS Reality)..."
  if curl -fsSL "${HUB_BASE}/bin/install-xray.sh" -o /tmp/usk-install-xray.sh; then
    chmod +x /tmp/usk-install-xray.sh
    PANEL_ROOT="$NODE_ROOT" USK_DATA_ROOT="$DATA_ROOT" bash /tmp/usk-install-xray.sh 443 || echo "USK_WARN: xray_install_failed run manually"
    rm -f /tmp/usk-install-xray.sh
  fi
fi

SSH_HOST=$(detect_public_ip)
[ -n "$SSH_HOST" ] || SSH_HOST="$CONNECT_HOST"

payload=$(jq -nc \
  --arg name "$NODE_NAME" \
  --arg host "$SSH_HOST" \
  --argjson port "$SSH_PORT" \
  --arg user "$SSH_USER" \
  --arg pass "$SSH_PASS" \
  --arg connect "$CONNECT_HOST" \
  '{name:$name, ssh_host:$host, ssh_port:$port, ssh_user:$user, ssh_password:$pass, connect_host:$connect}')

echo "[*] Registering node with Hub..."
resp=$(curl -fsSL -X POST "${HUB_BASE}/api/node-register.php" \
  -H "Content-Type: application/json" \
  -H "X-USK-Register-Secret: ${REGISTER_SECRET}" \
  -d "$payload" 2>&1) || {
  echo "USK_ERR: hub_register_failed"
  echo "$resp" | tail -20
  exit 1
}

if ! echo "$resp" | jq -e '.ok == true' >/dev/null 2>&1; then
  echo "USK_ERR: hub_rejected"
  echo "$resp"
  exit 1
fi

node_id=$(echo "$resp" | jq -r '.node_id // empty')
echo "USK_OK: node_registered id=${node_id} name=${NODE_NAME} connect_host=${CONNECT_HOST}"
echo "USK_INFO: node_root=${NODE_ROOT} hub=${HUB_BASE}"
exit 0
