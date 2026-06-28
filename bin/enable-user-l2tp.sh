#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
PASSWORD="${2:-}"
[ -n "$USERNAME" ] || usk_json_fail "username_required"
[ -n "$PASSWORD" ] || usk_json_fail "password_required"

CHAP="/etc/ppp/chap-secrets"
touch "$CHAP"
grep -q "^${USERNAME} " "$CHAP" 2>/dev/null || echo "${USERNAME} l2tpd ${PASSWORD} *" >> "$CHAP"

echo "USK_OK"
exit 0
