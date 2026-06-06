#!/bin/bash
# Install PHP ZipArchive extension (Ubuntu/Debian).
# Called from admin panel: sudo bash bin/install-php-zip.sh /var/www/unlimitsky
set -euo pipefail

WEB_ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
LIB="$WEB_ROOT/install/lib.sh"

if [ ! -f "$LIB" ]; then
    echo "USK_ERR: missing install/lib.sh"
    exit 1
fi

# shellcheck source=/dev/null
source "$LIB"

if php -r 'exit(class_exists("ZipArchive") ? 0 : 1);' 2>/dev/null; then
    echo "USK_OK: ZipArchive already available"
    exit 0
fi

usk_ensure_php_zip

if php -r 'exit(class_exists("ZipArchive") ? 0 : 1);' 2>/dev/null; then
    echo "USK_OK: ZipArchive installed"
    exit 0
fi

echo "USK_ERR: ZipArchive still missing after apt install"
exit 1
