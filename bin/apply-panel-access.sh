#!/bin/bash
# Apply admin panel domain + port + HTTPS URL + block direct IP access (nginx + config.php)
set -euo pipefail

WEB_ROOT="${1:-/var/www/unlimitsky}"
NEW_PORT="${2:-8082}"
PANEL_DOMAIN="${3:-}"
HTTPS_ENABLED="${4:-0}"
LOCK_DOMAIN="${5:-0}"

if [ "$EUID" -ne 0 ]; then
  echo "USK_ERR: root_required"
  exit 1
fi

if ! [[ "$NEW_PORT" =~ ^[0-9]+$ ]] || [ "$NEW_PORT" -lt 1024 ] || [ "$NEW_PORT" -gt 65535 ]; then
  echo "USK_ERR: invalid_port"
  exit 1
fi

if [ -n "$PANEL_DOMAIN" ]; then
  if ! echo "$PANEL_DOMAIN" | grep -Eq '^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$'; then
    echo "USK_ERR: invalid_domain"
    exit 1
  fi
fi

SITE_FILE="/etc/nginx/sites-available/unlimitsky-client"
if [ ! -f "$SITE_FILE" ]; then
  SITE_FILE="$(grep -rl "root ${WEB_ROOT};" /etc/nginx/sites-available/ 2>/dev/null | head -1 || true)"
fi
if [ -z "$SITE_FILE" ] || [ ! -f "$SITE_FILE" ]; then
  echo "USK_ERR: nginx_site_not_found"
  exit 1
fi

PHP_SOCK="$(grep -o 'unix:/[^;]*' "$SITE_FILE" 2>/dev/null | head -1 | sed 's/unix://' || true)"
if [ -z "$PHP_SOCK" ]; then
  for sock in /run/php/php*-fpm.sock; do
    [ -S "$sock" ] && PHP_SOCK="$sock" && break
  done
fi
if [ -z "$PHP_SOCK" ] || [ ! -S "$PHP_SOCK" ]; then
  echo "USK_ERR: php_fpm_socket_not_found"
  exit 1
fi

SERVER_NAMES="_"
DEFAULT_BLOCK=""
if [ -n "$PANEL_DOMAIN" ]; then
  SERVER_NAMES="$PANEL_DOMAIN"
  if [ "$LOCK_DOMAIN" = "1" ]; then
    DEFAULT_BLOCK="1"
  fi
fi

write_panel_server() {
  local listen_extra="${1:-}"
  cat <<NGX
server {
    listen ${NEW_PORT}${listen_extra};
    listen [::]:${NEW_PORT}${listen_extra};
    server_name ${SERVER_NAMES};
    root ${WEB_ROOT};
    index home.php install/index.php;

    client_max_body_size 32m;
    server_tokens off;

    location ^~ /admin/data/ { deny all; return 404; }
    location ^~ /sql/ { deny all; return 404; }
    location = /config.php { deny all; return 404; }
    location ~ /install/\.db-provision\.json$ { deny all; return 404; }

    location / {
        try_files \$uri \$uri/ /home.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 600s;
        fastcgi_send_timeout 600s;
        fastcgi_param HTTP_X_FORWARDED_PROTO \$http_x_forwarded_proto;
        fastcgi_param HTTP_CF_VISITOR \$http_cf_visitor;
    }

    location ~ /\. { deny all; }
}
NGX
}

if [ -n "$DEFAULT_BLOCK" ]; then
  {
    cat <<NGX
server {
    listen ${NEW_PORT} default_server;
    listen [::]:${NEW_PORT} default_server;
    server_name _;
    return 444;
}

NGX
    write_panel_server ""
  } > "$SITE_FILE"
else
  write_panel_server "" > "$SITE_FILE"
fi

if ! nginx -t >/dev/null 2>&1; then
  echo "USK_ERR: nginx_test_failed"
  exit 1
fi
systemctl reload nginx

if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
  ufw allow "${NEW_PORT}/tcp" >/dev/null 2>&1 || true
fi

SCHEME="http"
[ "$HTTPS_ENABLED" = "1" ] && SCHEME="https"

if [ -n "$PANEL_DOMAIN" ]; then
  HOST="$PANEL_DOMAIN"
else
  HOST="$(hostname -I 2>/dev/null | awk '{print $1}')"
  [ -z "$HOST" ] && HOST="127.0.0.1"
fi

# HTTPS without port only when nginx listens on 80/443 (Cloudflare). Port 8082 needs :8082 in URLs.
if [ "$HTTPS_ENABLED" = "1" ] && [ -n "$PANEL_DOMAIN" ] && { [ "$NEW_PORT" = "443" ] || [ "$NEW_PORT" = "80" ]; }; then
  PUBLIC_URL="${SCHEME}://${HOST}"
elif [ -n "$PANEL_DOMAIN" ]; then
  PUBLIC_URL="http://${HOST}:${NEW_PORT}"
else
  PUBLIC_URL="http://${HOST}:${NEW_PORT}"
fi

CONFIG="${WEB_ROOT}/config.php"
if [ ! -f "$CONFIG" ]; then
  echo "USK_ERR: config_missing"
  exit 1
fi

php -r "
\$f = '$CONFIG';
\$url = '$PUBLIC_URL';
\$c = file_get_contents(\$f);
if (!preg_match(\"/(['\\\"]domain['\\\"]\\s*=>\\s*['\\\"])([^'\\\"]*)(['\\\"])/\", \$c)) {
  fwrite(STDERR, 'domain key missing');
  exit(1);
}
\$c = preg_replace(\"/(['\\\"]domain['\\\"]\\s*=>\\s*['\\\"])([^'\\\"]*)(['\\\"])/\", '\\1' . addslashes(\$url) . '\\3', \$c, 1);
if (file_put_contents(\$f, \$c) === false) exit(1);
@chmod(\$f, 0640);
" || {
  echo "USK_ERR: config_update_failed"
  exit 1
}

STATE_FILE="${WEB_ROOT}/data/settings/panel-access-applied.json"
mkdir -p "$(dirname "$STATE_FILE")"
python3 -c "
import json, datetime
print(json.dumps({
  'port': int('$NEW_PORT'),
  'panel_domain': '$PANEL_DOMAIN',
  'https_enabled': '$HTTPS_ENABLED' == '1',
  'lock_domain': '$LOCK_DOMAIN' == '1',
  'public_url': '$PUBLIC_URL',
  'nginx_site': '$SITE_FILE',
  'applied_at': datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat()
}, ensure_ascii=False))
" > "$STATE_FILE" 2>/dev/null || cat > "$STATE_FILE" <<JSON
{"port":${NEW_PORT},"panel_domain":"${PANEL_DOMAIN}","https_enabled":$([ "$HTTPS_ENABLED" = "1" ] && echo true || echo false),"lock_domain":$([ "$LOCK_DOMAIN" = "1" ] && echo true || echo false),"public_url":"${PUBLIC_URL}","nginx_site":"${SITE_FILE}","applied_at":"$(date -Iseconds)"}
JSON
chown www-data:www-data "$STATE_FILE" 2>/dev/null || true
chmod 664 "$STATE_FILE" 2>/dev/null || true

python3 -c "import json; print('USK_JSON:'+json.dumps({'ok':True,'public_url':'$PUBLIC_URL','admin_url':'${PUBLIC_URL}/admin/login.php','port':int('$NEW_PORT'),'panel_domain':'$PANEL_DOMAIN','https_enabled':('$HTTPS_ENABLED'=='1'),'lock_domain':('$LOCK_DOMAIN'=='1'),'nginx_site':'$SITE_FILE'}, ensure_ascii=False))" 2>/dev/null \
  || echo "USK_JSON:{\"ok\":true,\"public_url\":\"$PUBLIC_URL\",\"admin_url\":\"${PUBLIC_URL}/admin/login.php\",\"port\":${NEW_PORT},\"panel_domain\":\"$PANEL_DOMAIN\"}"
