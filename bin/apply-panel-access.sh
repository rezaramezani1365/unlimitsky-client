#!/bin/bash
# Apply admin panel domain + port (nginx + config.php)
set -euo pipefail

WEB_ROOT="${1:-/var/www/unlimitsky}"
NEW_PORT="${2:-8082}"
PANEL_DOMAIN="${3:-}"

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

sed -i "s/^\([[:space:]]*\)listen [0-9]\+;/\1listen ${NEW_PORT};/" "$SITE_FILE"
sed -i "s/^\([[:space:]]*\)listen \[::\]:[0-9]\+;/\1listen [::]:${NEW_PORT};/" "$SITE_FILE"

if [ -n "$PANEL_DOMAIN" ]; then
  sed -i "s/^\([[:space:]]*\)server_name .*;/\1server_name ${PANEL_DOMAIN};/" "$SITE_FILE"
else
  sed -i "s/^\([[:space:]]*\)server_name .*;/\1server_name _;/" "$SITE_FILE"
fi

if ! nginx -t >/dev/null 2>&1; then
  echo "USK_ERR: nginx_test_failed"
  exit 1
fi
systemctl reload nginx

if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
  ufw allow "${NEW_PORT}/tcp" >/dev/null 2>&1 || true
fi

if [ -n "$PANEL_DOMAIN" ]; then
  HOST="$PANEL_DOMAIN"
else
  HOST="$(hostname -I 2>/dev/null | awk '{print $1}')"
  [ -z "$HOST" ] && HOST="127.0.0.1"
fi
PUBLIC_URL="http://${HOST}:${NEW_PORT}"

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
  'public_url': '$PUBLIC_URL',
  'nginx_site': '$SITE_FILE',
  'applied_at': datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat()
}, ensure_ascii=False))
" > "$STATE_FILE" 2>/dev/null || cat > "$STATE_FILE" <<JSON
{"port":${NEW_PORT},"panel_domain":"${PANEL_DOMAIN}","public_url":"${PUBLIC_URL}","nginx_site":"${SITE_FILE}","applied_at":"$(date -Iseconds)"}
JSON
chown www-data:www-data "$STATE_FILE" 2>/dev/null || true
chmod 664 "$STATE_FILE" 2>/dev/null || true

python3 -c "import json; print('USK_JSON:'+json.dumps({'ok':True,'public_url':'$PUBLIC_URL','admin_url':'${PUBLIC_URL}/admin/login.php','port':int('$NEW_PORT'),'panel_domain':'$PANEL_DOMAIN','nginx_site':'$SITE_FILE'}, ensure_ascii=False))" 2>/dev/null \
  || echo "USK_JSON:{\"ok\":true,\"public_url\":\"$PUBLIC_URL\",\"admin_url\":\"${PUBLIC_URL}/admin/login.php\",\"port\":${NEW_PORT},\"panel_domain\":\"$PANEL_DOMAIN\"}"
