#!/bin/bash
# Create MySQL database for client web install
# Usage: sudo bash install/create-db.sh
set -euo pipefail

if [ "$EUID" -ne 0 ]; then
    echo "Run: sudo bash install/create-db.sh"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "$SCRIPT_DIR/lib.sh"

usk_mysql_create_app_db "usk_client"

IP="$(usk_detect_ip)"

echo ""
echo "============================================"
echo " MySQL ready — paste into install form"
echo "============================================"
echo ""
echo "  DB host:        localhost   ← NOT the database name!"
echo "  DB name:        ${USK_DB_NAME}"
echo "  DB user:        ${USK_DB_USER}"
echo "  DB password:    ${USK_DB_PASS}"
echo ""
echo "  Admin username: admin  (your choice)"
echo "  Admin password: (min 6 chars — your choice)"
echo ""
echo "============================================"
echo " Open: http://${IP}:8082/install/index.php"
echo "        (WSL from Windows: http://localhost:8082/install/index.php)"
echo "============================================"
