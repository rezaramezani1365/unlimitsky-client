#!/bin/bash
# Shared helpers for unlimitsky user provisioning
# Note: no "set -e" — scripts must call usk_json_fail explicitly

DATA_ROOT="${USK_DATA_ROOT:-/var/lib/unlimitsky}"
mkdir -p "$DATA_ROOT"

usk_json_ok() {
  python3 -c "import json,sys; print('USK_JSON:'+json.dumps(sys.argv[1], ensure_ascii=False))" "$1" 2>/dev/null \
    || node -e "console.log('USK_JSON:'+JSON.stringify(process.argv[1]))" "$1" 2>/dev/null \
    || echo "USK_JSON:{\"ok\":true,\"config\":\"$1\"}"
}

usk_json_fail() {
  echo "USK_ERR: $1"
  exit 1
}

usk_server_ip() {
  if [ -n "$USK_SERVER_IP" ]; then
    echo "$USK_SERVER_IP"
    return
  fi
  local ip
  ip=$(curl -4 -s --max-time 5 ifconfig.me 2>/dev/null || true)
  if [ -n "$ip" ]; then
    echo "$ip"
    return
  fi
  hostname -I 2>/dev/null | awk '{print $1}'
}

usk_next_ip() {
  local subnet="$1"
  local registry="$2"
  local base="${subnet%.*}"
  local start="${subnet##*.}"
  start=$((start + 1))
  local used=""
  if [ -f "$registry" ]; then
    used=$(grep -oE "${base}\.[0-9]+" "$registry" 2>/dev/null || true)
  fi
  local i
  for i in $(seq "$start" 254); do
    if ! echo "$used" | grep -q "${base}.${i}"; then
      echo "${base}.${i}"
      return
    fi
  done
  usk_json_fail "no_free_ip"
}

ensure_jq() {
  command -v jq >/dev/null 2>&1 || apt-get install -y jq >/dev/null 2>&1 || true
}

usk_protocol_port() {
  local file="$1"
  local pattern="$2"
  local default="$3"
  if [ -n "$USK_PORT" ]; then
    echo "$USK_PORT"
    return
  fi
  if [ -f "$file" ]; then
    local p
    p=$(grep -E "$pattern" "$file" 2>/dev/null | head -1 | grep -oE '[0-9]+' | head -1)
    if [ -n "$p" ]; then
      echo "$p"
      return
    fi
  fi
  echo "$default"
}
