#!/bin/bash
# Stage a Hub-pushed script into the node bin directory (sudo from Hub sync).
set -euo pipefail

src="${1:?}"
dest="${2:?}"
node_bin="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ "$dest" != "${node_bin}/"* ]]; then
  echo "USK_ERR: invalid_dest" >&2
  exit 1
fi
if [ ! -s "$src" ]; then
  echo "USK_ERR: empty_source" >&2
  exit 1
fi

install -m 755 "$src" "$dest"
