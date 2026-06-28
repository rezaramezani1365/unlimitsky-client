#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
[ -n "$USERNAME" ] || usk_json_fail "username_required"

CHAP="/etc/ppp/chap-secrets"
if [ -f "$CHAP" ]; then
  sed -i "/^${USERNAME} /d" "$CHAP"
fi

echo "USK_OK"
exit 0
