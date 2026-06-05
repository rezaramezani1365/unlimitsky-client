#!/bin/bash
# Shared helpers for UnlimitSky Ubuntu installers
# shellcheck disable=SC2034

usk_rand_alnum() {
    local len="${1:-16}"
    tr -dc 'a-zA-Z0-9' </dev/urandom | head -c "$len"
}

usk_rand_pass() {
    usk_rand_alnum 20
}

usk_detect_ip() {
    local ip=""
    ip=$(hostname -I 2>/dev/null | awk '{print $1}')
    if [ -z "$ip" ]; then
        ip=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src") print $(i+1)}')
    fi
    echo "${ip:-127.0.0.1}"
}

usk_detect_php_sock() {
    local sock
    sock=$(find /var/run/php -name 'php*-fpm.sock' 2>/dev/null | sort -V | tail -1)
    echo "${sock:-/var/run/php/php-fpm.sock}"
}

usk_mysql_cmd() {
    if mysql -e "SELECT 1" >/dev/null 2>&1; then
        mysql "$@"
    elif mysql -uroot -e "SELECT 1" >/dev/null 2>&1; then
        mysql -uroot "$@"
    else
        echo "ERROR: Cannot connect to MySQL. Run: sudo mysql" >&2
        return 1
    fi
}

usk_mysql_ensure() {
    systemctl enable mysql >/dev/null 2>&1 || systemctl enable mariadb >/dev/null 2>&1 || true
    systemctl start mysql >/dev/null 2>&1 || systemctl start mariadb >/dev/null 2>&1 || true
    usk_mysql_cmd -e "SELECT 1" >/dev/null 2>&1 || {
        echo "ERROR: MySQL is not running. Try: sudo systemctl start mysql" >&2
        return 1
    }
}

usk_mysql_create_app_db() {
    local prefix="$1"
    usk_mysql_ensure || return 1

    local suffix
    suffix=$(usk_rand_alnum 8 | tr '[:upper:]' '[:lower:]')
    USK_DB_NAME="${prefix}_${suffix}"
    USK_DB_USER="${prefix}_u_${suffix}"
    USK_DB_PASS="$(usk_rand_pass)"

    usk_mysql_cmd <<SQL
CREATE DATABASE IF NOT EXISTS \`${USK_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${USK_DB_USER}'@'localhost' IDENTIFIED BY '${USK_DB_PASS}';
ALTER USER '${USK_DB_USER}'@'localhost' IDENTIFIED BY '${USK_DB_PASS}';
GRANT ALL PRIVILEGES ON \`${USK_DB_NAME}\`.* TO '${USK_DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
}

usk_mysql_harden() {
    usk_mysql_ensure || return 0
    local cnf
    for cnf in /etc/mysql/mysql.conf.d/mysqld.cnf /etc/mysql/mariadb.conf.d/50-server.cnf; do
        [ -f "$cnf" ] || continue
        if grep -q '^bind-address' "$cnf"; then
            sed -i 's/^bind-address.*/bind-address = 127.0.0.1/' "$cnf"
        else
            printf '\n# UnlimitSky — local only\nbind-address = 127.0.0.1\n' >> "$cnf"
        fi
    done
    systemctl restart mysql >/dev/null 2>&1 || systemctl restart mariadb >/dev/null 2>&1 || true
}

usk_save_db_provision() {
    local file="$1"
    local db_name="$2"
    local db_user="$3"
    local db_pass="$4"
    umask 077
    cat > "$file" <<JSON
{
  "db_name": "${db_name}",
  "db_user": "${db_user}",
  "db_pass": "${db_pass}",
  "created_at": "$(date -Iseconds)"
}
JSON
    chmod 640 "$file"
    chown root:www-data "$file" 2>/dev/null || chmod 640 "$file"
}

usk_secure_app_files() {
    local web_root="$1"
    [ -f "${web_root}/config.php" ] && chmod 640 "${web_root}/config.php" && chown root:www-data "${web_root}/config.php" 2>/dev/null || true
    rm -f "${web_root}/install/.db-provision.json" 2>/dev/null || true
}

usk_firewall_allow_port() {
    local port="$1"
    if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
        ufw allow "${port}/tcp" >/dev/null 2>&1 || true
    fi
}

usk_save_credentials() {
    local file="$1"
    shift
    umask 077
    {
        echo "# UnlimitSky credentials — $(date -Iseconds)"
        "$@"
    } > "$file"
    chmod 600 "$file"
}

usk_print_box() {
    echo ""
    echo "============================================"
    while [ $# -gt 0 ]; do
        echo " $1"
        shift
    done
    echo "============================================"
}
