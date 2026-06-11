#!/bin/bash
# Remove all relay rules from a Node (or one rule by id).
# Usage:
#   remove-node-relay.sh [rule_id]
#
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NODE_ROOT="${USK_NODE_ROOT:-/opt/unlimitsky-node}"
RULES_FILE="${NODE_ROOT}/data/relay/rules.json"

usk_err() { echo "USK_ERR: $*"; exit 1; }

RULE_ID="${1:-}"

if [ ! -x "${DIR}/setup-node-relay.sh" ]; then
  usk_err "setup_script_missing"
fi

run_setup() {
  if [ "$EUID" -eq 0 ]; then
    /bin/bash "${DIR}/setup-node-relay.sh" "$@"
  else
    sudo -n /bin/bash "${DIR}/setup-node-relay.sh" "$@"
  fi
}

if [ -n "$RULE_ID" ]; then
  run_setup remove "$RULE_ID"
  exit 0
fi

if [ ! -f "$RULES_FILE" ]; then
  echo "USK_OK: relay_cleared count=0"
  exit 0
fi

command -v jq >/dev/null 2>&1 || usk_err "jq_required"

ids=$(jq -r '.[].id' "$RULES_FILE" 2>/dev/null || true)
count=0
for id in $ids; do
  [ -z "$id" ] && continue
  run_setup remove "$id" >/dev/null 2>&1 || true
  count=$((count + 1))
done

echo "USK_OK: relay_cleared count=${count}"
