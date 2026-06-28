#!/bin/bash
# unlimitsky Client — install from GitHub (reseller VPS)
#
# One-liner (replace YOUR_GITHUB_USER with your repo):
#   curl -fsSL https://raw.githubusercontent.com/YOUR_GITHUB_USER/unlimitsky-client/main/scripts/install.sh | sudo bash -s -- \
#     --port 8082 --admin-pass 'Pass123' --open-firewall \
#     --license-url 'https://license.yourdomain.com/api/v1.php' \
#     --license-token 'SECRET'
#
set -euo pipefail

REPO_URL="${USK_REPO_URL:-https://github.com/rezaramezani1365/unlimitsky-client.git}"
INSTALL_DIR="${USK_INSTALL_DIR:-/opt/unlimitsky}"
BRANCH="${USK_BRANCH:-main}"

if [ "$EUID" -ne 0 ]; then
    echo "Run as root: curl ... | sudo bash -s -- [options]"
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive
if ! command -v git >/dev/null 2>&1; then
    apt-get update -qq
    apt-get install -y git
fi

if [ -d "$INSTALL_DIR/.git" ]; then
    echo "[*] Updating $INSTALL_DIR ..."
    git -C "$INSTALL_DIR" fetch --depth 1 origin "$BRANCH"
    git -C "$INSTALL_DIR" checkout "$BRANCH"
    git -C "$INSTALL_DIR" reset --hard "origin/$BRANCH"
else
    echo "[*] Cloning $REPO_URL -> $INSTALL_DIR ..."
    mkdir -p "$(dirname "$INSTALL_DIR")"
    git clone --branch "$BRANCH" --depth 1 "$REPO_URL" "$INSTALL_DIR"
fi

COMMIT="$(git -C "$INSTALL_DIR" rev-parse --short HEAD 2>/dev/null || echo unknown)"
echo "[*] Git source commit: $COMMIT"

echo "[*] Running client installer ..."
cd "$INSTALL_DIR"
exec bash install-ubuntu.sh --auto "$@"
