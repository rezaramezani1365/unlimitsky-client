#!/bin/bash
# Install unlimitsky Node worker on a remote VPS and register with the main (Hub) panel.
# No per-node token — use Hub IP/port + this server's SSH user/password + register password from Hub admin.
#
# Non-interactive (recommended — pipe cannot read prompts from stdin):
#   curl -fsSL http://HUB_IP:PORT/bin/install-node.sh | sudo bash -s -- \
#     --hub-ip 1.2.3.4 --hub-port 8082 \
#     --register-secret 'SECRET_FROM_PANEL' \
#     --ssh-user ubuntu --ssh-pass 'YourUbuntuPass' \
#     --name germany-1 --connect-host vpn.example.com
#
# Interactive (download first, then run — prompts work on a TTY):
#   curl -fsSL http://HUB_IP:PORT/bin/install-node.sh -o install-node.sh
#   sudo bash install-node.sh
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
HUB_PORT_EXPLICIT=0

usage() {
  cat <<'HELP'
unlimitsky Node installer (remote VPS)

Required:
  --hub-ip IP           Main panel server IP or domain
  --hub-port PORT       Main panel HTTP port (default 8082)
  --register-secret S   Registration password (Admin → Nodes page on Hub)
  --ssh-user USER       SSH user on THIS Node VPS (NOT Hub login; use ubuntu if you ssh ubuntu@this-server)
  --ssh-pass PASS       Password for that user on THIS Node (NOT Hub/panel password)

Optional:
  --name NAME           Node display name (default: hostname)
  --connect-host HOST   IP/domain in buyer configs (default: this server's public IP)
  --ssh-port PORT       SSH port on this server (default 22)
  --hub-scheme http|https

Example (non-interactive via pipe):
  curl -fsSL http://1.2.3.4:8082/bin/install-node.sh | sudo bash -s -- \
    --hub-ip 1.2.3.4 --hub-port 8082 --register-secret abc123 \
    --ssh-user ubuntu --ssh-pass 'secret' --name node-de --connect-host 5.6.7.8

Example (interactive):
  curl -fsSL http://1.2.3.4:8082/bin/install-node.sh -o install-node.sh
  sudo bash install-node.sh
HELP
}

can_prompt() {
  [ -t 0 ] || { [ -r /dev/tty ] 2>/dev/null; }
}

read_fd() {
  if [ -t 0 ]; then
    echo "/dev/stdin"
  elif [ -r /dev/tty ] 2>/dev/null; then
    echo "/dev/tty"
  else
    echo ""
  fi
}

all_required_set() {
  for req in HUB_IP HUB_PORT REGISTER_SECRET SSH_USER SSH_PASS NODE_NAME CONNECT_HOST; do
    local val
    val=$(eval "echo \${$req}")
    if [ -z "$val" ]; then
      return 1
    fi
  done
  return 0
}

show_missing_args_error() {
  local hub="${HUB_IP:-HUB_IP}"
  local port="${HUB_PORT:-8082}"
  local scheme="${HUB_SCHEME:-http}"
  cat <<EOF
USK_ERR: missing_required_args (stdin is not a TTY — pipe mode cannot prompt)

Pass all required flags after "bash -s --":

  curl -fsSL ${scheme}://${hub}:${port}/bin/install-node.sh | sudo bash -s -- \\
    --hub-ip ${hub} --hub-port ${port} \\
    --register-secret 'SECRET_FROM_HUB_NODES_PAGE' \\
    --ssh-user ubuntu --ssh-pass 'THIS_NODE_SSH_PASSWORD_NOT_HUB' \\
    --name node-name --connect-host PUBLIC_IP_OR_DOMAIN

Or download and run interactively:

  curl -fsSL ${scheme}://${hub}:${port}/bin/install-node.sh -o install-node.sh
  sudo bash install-node.sh
EOF
}

normalize_hub_address() {
  local raw="$HUB_IP"
  raw="${raw#http://}"
  raw="${raw#https://}"
  raw="${raw%%/*}"

  if [[ "$raw" == \[*\]:* ]]; then
    local host="${raw%:*}"
    local port_part="${raw##*:}"
    HUB_IP="$host"
    if [ "$HUB_PORT_EXPLICIT" -eq 0 ] && [[ "$port_part" =~ ^[0-9]+$ ]]; then
      HUB_PORT="$port_part"
    fi
    return
  fi

  if [[ "$raw" == *:* ]]; then
    local host="${raw%:*}"
    local port_part="${raw##*:}"
    if [[ "$port_part" =~ ^[0-9]+$ ]] && [ "$port_part" -ge 1 ] && [ "$port_part" -le 65535 ]; then
      HUB_IP="$host"
      if [ "$HUB_PORT_EXPLICIT" -eq 0 ]; then
        HUB_PORT="$port_part"
      fi
      return
    fi
  fi

  HUB_IP="$raw"
}

detect_default_ssh_user() {
  if [ -f /etc/os-release ]; then
    # shellcheck source=/dev/null
    . /etc/os-release
    case "${ID:-}" in
      ubuntu|debian) echo "ubuntu"; return ;;
    esac
    case "${ID_LIKE:-}" in
      *ubuntu*|*debian*) echo "ubuntu"; return ;;
    esac
  fi
  if id -u ubuntu >/dev/null 2>&1; then
    echo "ubuntu"
    return
  fi
  echo "root"
}

while [ $# -gt 0 ]; do
  case "$1" in
    --hub-ip) HUB_IP="$2"; shift 2 ;;
    --hub-port) HUB_PORT="$2"; HUB_PORT_EXPLICIT=1; shift 2 ;;
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

if [ -n "$HUB_IP" ]; then
  normalize_hub_address
fi

if [ "$EUID" -ne 0 ]; then
  echo "USK_ERR: run_as_root"
  exit 1
fi

prompt_if_empty() {
  local varname="$1"
  local prompt="$2"
  local default="${3:-}"
  local current fd input
  current=$(eval "echo \${$varname}")
  if [ -n "$current" ]; then
    return 0
  fi
  if ! can_prompt; then
    return 1
  fi
  fd=$(read_fd)
  if [ -n "$default" ]; then
    read -r -p "$prompt [$default]: " input < "$fd"
    input=${input:-$default}
  else
    read -r -p "$prompt: " input < "$fd"
  fi
  eval "$varname=\"\$input\""
}

prompt_secret() {
  local varname="$1"
  local prompt="$2"
  local current fd input
  current=$(eval "echo \${$varname}")
  if [ -n "$current" ]; then
    return 0
  fi
  if ! can_prompt; then
    return 1
  fi
  fd=$(read_fd)
  read -r -s -p "$prompt: " input < "$fd"
  echo ""
  eval "$varname=\"\$input\""
}

detect_public_ip() {
  curl -4 -s --max-time 8 ifconfig.me 2>/dev/null \
    || curl -4 -s --max-time 8 icanhazip.com 2>/dev/null \
    || hostname -I 2>/dev/null | awk '{print $1}'
}

echo "=== unlimitsky Node installer ==="
echo "NOTE: --ssh-user/--ssh-pass = THIS Node server (where you run this script), NOT the Hub/panel server."

if ! all_required_set; then
  if ! can_prompt; then
    show_missing_args_error
    exit 1
  fi
  prompt_if_empty HUB_IP "Hub panel IP or domain (IP only — no :port)" || true
  if [ -n "$HUB_IP" ]; then
    normalize_hub_address
  fi
  prompt_if_empty HUB_PORT "Hub panel port" "8082" || true
  prompt_if_empty REGISTER_SECRET "Hub registration password (from Admin → Nodes)" || true
  def_ssh=$(detect_default_ssh_user)
  prompt_if_empty SSH_USER "SSH user on THIS Node (NOT Hub login; ubuntu if you ssh ubuntu@this-server)" "$def_ssh" || true
  prompt_secret SSH_PASS "SSH password for ${SSH_USER:-$def_ssh} on THIS Node (NOT Hub/panel password)" || true
  prompt_if_empty NODE_NAME "Node name" "$(hostname -s 2>/dev/null || echo node)" || true
  if [ -z "$CONNECT_HOST" ]; then
    def_ip=$(detect_public_ip)
    prompt_if_empty CONNECT_HOST "Connect address for buyers (IP or domain)" "${def_ip:-}" || true
  fi
fi

if ! all_required_set; then
  if ! can_prompt; then
    show_missing_args_error
  else
    for req in HUB_IP HUB_PORT REGISTER_SECRET SSH_USER SSH_PASS NODE_NAME CONNECT_HOST; do
      val=$(eval "echo \${$req}")
      if [ -z "$val" ]; then
        echo "USK_ERR: missing_${req,,}"
        exit 1
      fi
    done
  fi
  exit 1
fi

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

mkdir -p "$NODE_ROOT/bin" "$NODE_ROOT/data/relay"
chmod 755 "$NODE_ROOT" "$NODE_ROOT/bin" "$NODE_ROOT/data"

HUB_BASE="${HUB_SCHEME}://${HUB_IP}:${HUB_PORT}"
echo "[*] Downloading worker scripts from Hub (${HUB_BASE})..."

BIN_LIST=(
  node-receive-script.sh
  setup-node-relay.sh
  setup-node-tunnel.sh
  remove-node-relay.sh
)

for f in "${BIN_LIST[@]}"; do
  if ! curl -fsSL "${HUB_BASE}/bin/${f}" -o "${NODE_ROOT}/bin/${f}"; then
    echo "USK_ERR: download_failed file=${f}"
    exit 1
  fi
  chmod +x "${NODE_ROOT}/bin/${f}"
done

# Hub sync (SSH push / curl) must be able to refresh the whole node tree as the SSH user
chown -R "${SSH_USER}:${SSH_USER}" "${NODE_ROOT}"
chmod 755 "${NODE_ROOT}/bin"

# Allow Hub SSH user to run provisioning without interactive sudo password
SUDOERS="/etc/sudoers.d/unlimitsky-node"
cat > "$SUDOERS" <<EOF
# unlimitsky Hub remote provisioning
${SSH_USER} ALL=(root) NOPASSWD: /bin/bash ${NODE_ROOT}/bin/*.sh
${SSH_USER} ALL=(root) NOPASSWD: /bin/mkdir -p ${NODE_ROOT}/bin, /bin/mkdir -p ${NODE_ROOT}/data, /bin/mkdir -p ${NODE_ROOT}/data/*
${SSH_USER} ALL=(root) NOPASSWD: /bin/chown -R ${SSH_USER}\\:${SSH_USER} ${NODE_ROOT}
${SSH_USER} ALL=(root) NOPASSWD: /usr/bin/tee ${NODE_ROOT}/bin/*
${SSH_USER} ALL=(root) NOPASSWD: /bin/chmod 755 ${NODE_ROOT}/bin/*
${SSH_USER} ALL=(root) NOPASSWD: /usr/bin/test -s ${NODE_ROOT}/bin/*
EOF
chmod 440 "$SUDOERS"

echo "[*] Initializing port relay (iptables forwarding to Hub)..."
if [ -x "${NODE_ROOT}/bin/setup-node-relay.sh" ]; then
  USK_NODE_ROOT="$NODE_ROOT" bash "${NODE_ROOT}/bin/setup-node-relay.sh" init || echo "USK_WARN: relay_init_failed run manually on Hub"
fi

echo "[*] Initializing WireGuard egress tunnel worker..."
if [ -x "${NODE_ROOT}/bin/setup-node-tunnel.sh" ]; then
  USK_NODE_ROOT="$NODE_ROOT" bash "${NODE_ROOT}/bin/setup-node-tunnel.sh" init || echo "USK_WARN: tunnel_init_failed run from Hub when creating a service"
fi

is_private_ip() {
  local ip="$1"
  [[ "$ip" =~ ^10\. ]] && return 0
  [[ "$ip" =~ ^192\.168\. ]] && return 0
  [[ "$ip" =~ ^127\. ]] && return 0
  [[ "$ip" =~ ^172\.(1[6-9]|2[0-9]|3[01])\. ]] && return 0
  return 1
}

resolve_ssh_host() {
  local detected
  detected=$(detect_public_ip)
  if [ -n "$detected" ] && ! is_private_ip "$detected"; then
    echo "$detected"
    return
  fi
  if [ -n "$CONNECT_HOST" ] && ! is_private_ip "$CONNECT_HOST"; then
    echo "$CONNECT_HOST"
    return
  fi
  [ -n "$detected" ] && echo "$detected" || echo "$CONNECT_HOST"
}

SSH_HOST=$(resolve_ssh_host)
echo "[*] Hub will SSH to ${SSH_HOST}:${SSH_PORT} (connect_host=${CONNECT_HOST})"

payload=$(jq -nc \
  --arg name "$NODE_NAME" \
  --arg host "$SSH_HOST" \
  --argjson port "$SSH_PORT" \
  --arg user "$SSH_USER" \
  --arg pass "$SSH_PASS" \
  --arg connect "$CONNECT_HOST" \
  '{name:$name, ssh_host:$host, ssh_port:$port, ssh_user:$user, ssh_password:$pass, connect_host:$connect}')

echo "[*] Registering node with Hub..."
resp_file=$(mktemp)
http_code=$(curl -sS -w '%{http_code}' -o "$resp_file" -X POST "${HUB_BASE}/api/node-register.php" \
  -H "Content-Type: application/json" \
  -H "X-USK-Register-Secret: ${REGISTER_SECRET}" \
  -d "$payload") || http_code="000"
resp=$(cat "$resp_file" 2>/dev/null || true)
rm -f "$resp_file"

if [ "$http_code" != "200" ]; then
  api_err=$(echo "$resp" | jq -r '.error // empty' 2>/dev/null)
  api_detail=$(echo "$resp" | jq -r '.detail // empty' 2>/dev/null)
  echo "USK_ERR: hub_register_failed http=${http_code}"
  if [ -n "$api_err" ]; then
    echo "USK_ERR: api_error=${api_err}"
  fi
  if [ -n "$api_detail" ]; then
    echo "USK_DETAIL: ${api_detail}"
  fi
  if [ "$api_err" = "ssh_connect_failed" ]; then
    if echo "$api_detail" | grep -qi 'permission denied'; then
      echo "USK_HINT: Hub could not SSH as user=${SSH_USER}. On Ubuntu cloud images root password login is usually disabled."
      echo "USK_HINT: Re-run with --ssh-user ubuntu and the ubuntu user's password (the same password you use for: ssh ubuntu@this-server)."
      echo "USK_HINT: Verify from Hub: sshpass -p 'PASSWORD' ssh -o StrictHostKeyChecking=no ${SSH_USER}@${SSH_HOST}"
    fi
  fi
  if [ -z "$api_err" ] && [ -n "$resp" ]; then
    echo "$resp"
  elif [ -z "$api_err" ]; then
    echo "curl failed (http_code=${http_code})"
  fi
  exit 1
fi

if ! echo "$resp" | jq -e '.ok == true' >/dev/null 2>&1; then
  echo "USK_ERR: hub_rejected"
  echo "$resp"
  exit 1
fi

node_id=$(echo "$resp" | jq -r '.node_id // empty')
echo "USK_OK: node_registered id=${node_id} name=${NODE_NAME} connect_host=${CONNECT_HOST}"
echo "USK_INFO: node_root=${NODE_ROOT} hub=${HUB_BASE}"
exit 0
