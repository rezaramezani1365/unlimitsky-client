#!/bin/bash
# Background protocol install worker (called from panel — avoids nginx 504)
set +e

PROTO="${1:-}"
USK_ROOT="${2:-/var/www/unlimitsky}"
shift 2 2>/dev/null || true

if [ -z "$PROTO" ] || [ ! -d "$USK_ROOT/bin" ]; then
  echo "USK_ERR: invalid_args"
  exit 1
fi

JOB_FILE="$USK_ROOT/data/protocols/${PROTO}-install.job"
LOG_FILE="$USK_ROOT/data/protocols/${PROTO}-install-running.log"
SCRIPT="$USK_ROOT/bin/install-${PROTO}.sh"

mkdir -p "$USK_ROOT/data/protocols"
: > "$LOG_FILE"
echo "running $(date -Iseconds)" > "$JOB_FILE"

export USK_DATA_ROOT="${USK_DATA_ROOT:-/var/lib/unlimitsky}"
export USK_AMNEZIA_FAST="${USK_AMNEZIA_FAST:-1}"

{
  echo "=== unlimitsky install ${PROTO} $(date -Iseconds) ==="
  if [ ! -x "$SCRIPT" ]; then
    echo "USK_ERR: script_missing"
    echo "failed $(date -Iseconds)" > "$JOB_FILE"
    exit 1
  fi
  bash "$SCRIPT" "$@"
  echo "=== END $(date -Iseconds) ==="
} >> "$LOG_FILE" 2>&1

if grep -q 'USK_OK' "$LOG_FILE" 2>/dev/null; then
  echo "done $(date -Iseconds)" > "$JOB_FILE"
  exit 0
fi
echo "failed $(date -Iseconds)" > "$JOB_FILE"
exit 1
