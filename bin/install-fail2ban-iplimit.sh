#!/bin/bash
# Install/configure Fail2ban jail for Unlimitsky Xray IP limits (3x-ui usk-ipl pattern).
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$DIR/provision-common.sh" 2>/dev/null || true
# shellcheck disable=SC1091
source "$DIR/xray-stats-state.sh" 2>/dev/null || true

DATA_ROOT="${DATA_ROOT:-${USK_DATA_ROOT:-/var/lib/unlimitsky}}"
PANEL_ROOT="${PANEL_ROOT:-$(dirname "$DIR")}"
IP_LIMIT_LOG="${DATA_ROOT}/xray/iplimit.log"
IP_BANNED_LOG="${DATA_ROOT}/xray/iplimit-banned.log"
BANTIME_MIN="${1:-30}"
FILTER_SRC="${PANEL_ROOT}/install/fail2ban/filter-usk-ipl.conf"

if [ "$EUID" -ne 0 ]; then
  echo "USK_ERR: run_as_root" >&2
  exit 1
fi

mkdir -p "${DATA_ROOT}/xray" /etc/fail2ban/filter.d /etc/fail2ban/action.d /etc/fail2ban/jail.d 2>/dev/null || true
touch "$IP_LIMIT_LOG" "$IP_BANNED_LOG" 2>/dev/null || true
chmod 644 "$IP_LIMIT_LOG" "$IP_BANNED_LOG" 2>/dev/null || true

if ! command -v fail2ban-client >/dev/null 2>&1; then
  echo "[*] Installing fail2ban..."
  if command -v apt-get >/dev/null 2>&1; then
    apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y fail2ban nftables 2>/dev/null || apt-get install -y fail2ban
  elif command -v dnf >/dev/null 2>&1; then
    dnf install -y fail2ban nftables
  elif command -v yum >/dev/null 2>&1; then
    yum install -y epel-release 2>/dev/null || true
    yum install -y fail2ban
  else
    echo "USK_ERR: install_fail2ban_manually" >&2
    exit 1
  fi
fi

command -v fail2ban-client >/dev/null 2>&1 || { echo "USK_ERR: fail2ban_missing" >&2; exit 1; }

if [ -f "$FILTER_SRC" ]; then
  cp "$FILTER_SRC" /etc/fail2ban/filter.d/usk-ipl.conf
else
  cat > /etc/fail2ban/filter.d/usk-ipl.conf <<'EOF'
[Definition]
datepattern = ^%%Y/%%m/%%d %%H:%%M:%%S
failregex = \[LIMIT_IP\]\s*Email\s*=\s*<F-USER>.+\s*\|\|\s*Disconnecting OLD IP\s*=\s*<ADDR>\s*\|\|\s*Timestamp\s*=\s*\d+
ignoreregex =
EOF
fi

ssh_ports=$(grep -oP '^[[:space:]]*Port[[:space:]]+\K[0-9]+' /etc/ssh/sshd_config 2>/dev/null | paste -sd, - || true)
[ -n "$ssh_ports" ] || ssh_ports="22"
panel_port=""
if [ -f /etc/nginx/sites-enabled/unlimitsky ]; then
  panel_port=$(grep -oP 'listen\s+\K[0-9]+' /etc/nginx/sites-enabled/unlimitsky 2>/dev/null | head -1 || true)
fi
exempt_ports="$ssh_ports"
[ -n "$panel_port" ] && exempt_ports="${exempt_ports},${panel_port}"

cat > /etc/fail2ban/jail.d/usk-ipl.conf <<EOF
[usk-ipl]
enabled=true
backend=auto
filter=usk-ipl
action=usk-ipl
logpath=${IP_LIMIT_LOG}
maxretry=1
findtime=32
bantime=${BANTIME_MIN}m
EOF

cat > /etc/fail2ban/action.d/usk-ipl.conf <<EOF
[INCLUDES]
before = iptables-allports.conf

[Definition]
actionstart = iptables -N f2b-<name> 2>/dev/null || true
              iptables -A f2b-<name> -j <chain> 2>/dev/null || true
              iptables -I <chain> -p <protocol> -j f2b-<name> 2>/dev/null || true

actionstop = iptables -D <chain> -p <protocol> -j f2b-<name> 2>/dev/null || true
             iptables -F f2b-<name> 2>/dev/null || true
             iptables -X f2b-<name> 2>/dev/null || true

actioncheck = iptables -n -L f2b-<name> >/dev/null 2>&1

actionban = iptables -I f2b-<name> 1 -s <ip> -p <protocol> -m multiport ! --dports ${exempt_ports} -j <blocktype>
            echo "\$(date +'%Y/%m/%d %H:%M:%S') BAN [Email] = <email> [IP] = <ip> banned for <bantime>s." >> ${IP_BANNED_LOG}

actionunban = iptables -D f2b-<name> -s <ip> -p <protocol> -m multiport ! --dports ${exempt_ports} -j <blocktype>
              echo "\$(date +'%Y/%m/%d %H:%M:%S') UNBAN [Email] = <email> [IP] = <ip> unbanned." >> ${IP_BANNED_LOG}

[Init]
name = usk-ipl
protocol = tcp
chain = INPUT
EOF

if [ -f /etc/fail2ban/fail2ban.conf ]; then
  sed -i 's/#allowipv6 = auto/allowipv6 = auto/g' /etc/fail2ban/fail2ban.conf 2>/dev/null || true
fi

systemctl enable fail2ban 2>/dev/null || true
systemctl restart fail2ban 2>/dev/null || service fail2ban restart 2>/dev/null || true

if fail2ban-client status usk-ipl >/dev/null 2>&1; then
  echo "USK_OK: fail2ban_jail=usk-ipl log=${IP_LIMIT_LOG} bantime=${BANTIME_MIN}m"
  exit 0
fi

echo "USK_WARN: fail2ban_installed jail_check_failed — run: fail2ban-client status usk-ipl" >&2
exit 0
