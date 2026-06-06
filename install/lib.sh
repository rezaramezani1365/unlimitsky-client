#!/bin/bash
# Shared helpers for unlimitsky Ubuntu installers (fresh Ubuntu VPS)
# shellcheck disable=SC2034

usk_rand_alnum() {
    local len="${1:-16}"
    local raw=""
    if command -v openssl >/dev/null 2>&1; then
        raw=$(openssl rand -base64 256 | tr -dc 'a-zA-Z0-9')
    else
        set +o pipefail
        raw=$(tr -dc 'a-zA-Z0-9' </dev/urandom 2>/dev/null | head -c "$len")
        set -o pipefail
    fi
    if [ -z "$raw" ]; then
        echo "ERROR: cannot generate random string" >&2
        return 1
    fi
    printf '%s' "${raw:0:len}"
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
    # Fresh Ubuntu: root connects via unix socket (auth_socket) — no password needed.
    if [ "$(id -u)" -eq 0 ] && mysql -e "SELECT 1" >/dev/null 2>&1; then
        mysql "$@"
        return $?
    fi
    if mysql -uroot -e "SELECT 1" >/dev/null 2>&1; then
        mysql -uroot "$@"
        return $?
    fi
    if command -v sudo >/dev/null 2>&1 && sudo mysql -e "SELECT 1" >/dev/null 2>&1; then
        sudo mysql "$@"
        return $?
    fi
    echo "ERROR: Cannot connect to MySQL. Is mysql-server installed and running?" >&2
    return 1
}

usk_mysql_wait() {
    local i
    for i in $(seq 1 45); do
        if usk_mysql_cmd -e "SELECT 1" >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    echo "ERROR: MySQL did not become ready in time." >&2
    return 1
}

usk_mysql_ensure() {
    systemctl enable mysql >/dev/null 2>&1 || systemctl enable mariadb >/dev/null 2>&1 || true
    systemctl start mysql >/dev/null 2>&1 || systemctl start mariadb >/dev/null 2>&1 || true
    usk_mysql_wait
}

usk_mysql_create_app_db() {
    local prefix="$1"
    usk_mysql_ensure || return 1

    local suffix
    suffix="$(usk_rand_alnum 8)" || return 1
    suffix="${suffix,,}"

    USK_DB_NAME="${prefix}_${suffix}"
    USK_DB_USER="${prefix}_u_${suffix}"
    USK_DB_PASS="$(usk_rand_pass)" || return 1

    if ! usk_mysql_cmd <<SQL
CREATE DATABASE IF NOT EXISTS \`${USK_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${USK_DB_USER}'@'localhost' IDENTIFIED BY '${USK_DB_PASS}';
ALTER USER '${USK_DB_USER}'@'localhost' IDENTIFIED BY '${USK_DB_PASS}';
GRANT ALL PRIVILEGES ON \`${USK_DB_NAME}\`.* TO '${USK_DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
    then
        echo "ERROR: MySQL failed to create database/user." >&2
        return 1
    fi
}

usk_mysql_harden() {
    usk_mysql_ensure || return 0
    local cnf
    for cnf in /etc/mysql/mysql.conf.d/mysqld.cnf /etc/mysql/mariadb.conf.d/50-server.cnf; do
        [ -f "$cnf" ] || continue
        if grep -q '^bind-address' "$cnf"; then
            sed -i 's/^bind-address.*/bind-address = 127.0.0.1/' "$cnf"
        else
            printf '\n# unlimitsky — local only\nbind-address = 127.0.0.1\n' >> "$cnf"
        fi
    done
    systemctl restart mysql >/dev/null 2>&1 || systemctl restart mariadb >/dev/null 2>&1 || true
    usk_mysql_wait || true
}

usk_config_incomplete() {
    local web_root="$1"
    [ -f "${web_root}/config.php" ] && grep -q '\[\*DB-USER\*\]' "${web_root}/config.php"
}

usk_reset_incomplete_install() {
    local web_root="$1"
    if usk_config_incomplete "$web_root"; then
        echo "[*] Incomplete install detected — resetting config for a clean setup..."
        rm -f "${web_root}/install/unlimitsky.install"
        if [ -f "${web_root}/config.sample.php" ]; then
            cp "${web_root}/config.sample.php" "${web_root}/config.php"
        fi
    fi
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
        echo "# unlimitsky credentials — $(date -Iseconds)"
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

usk_restart_php_fpm() {
    local _fpm
    for _fpm in /lib/systemd/system/php*-fpm.service; do
        [ -f "$_fpm" ] && systemctl restart "$(basename "$_fpm" .service)" 2>/dev/null || true
    done
}

usk_ensure_php_zip() {
    if php -r 'exit(class_exists("ZipArchive") ? 0 : 1);' 2>/dev/null; then
        return 0
    fi
    echo "[*] Installing PHP zip extension (backup export/import)..."
    apt-get update -qq
    local ver pkg
    ver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "")"
    for pkg in "php${ver}-zip" php-zip php8.3-zip php8.2-zip php8.1-zip php8.0-zip; do
        [ -n "$pkg" ] || continue
        if apt-cache show "$pkg" >/dev/null 2>&1; then
            apt-get install -y "$pkg"
            usk_restart_php_fpm
            php -r 'exit(class_exists("ZipArchive") ? 0 : 1);' 2>/dev/null && return 0
        fi
    done
    echo "[!] Warning: ZipArchive not available — backup export may fail until php zip is installed." >&2
    return 0
}

usk_panel_is_installed() {
    local web_root="$1"
    [ -f "${web_root}/install/unlimitsky.install" ] && ! usk_config_incomplete "$web_root"
}

usk_deploy_panel_files() {
    local src_dir="$1"
    local web_root="$2"
    echo "[*] Deploying panel files to ${web_root}..."
    mkdir -p "$web_root"
    rsync -a --exclude '.git' --exclude 'install-ubuntu.sh' --exclude 'REPOSITORY.md' \
        --exclude 'config.php' --exclude 'install/unlimitsky.install' --exclude 'install/.db-provision.json' \
        --exclude 'admin/data/api-keys.json' --exclude 'admin/data/license.json' \
        "$src_dir/" "$web_root/" 2>/dev/null \
        || cp -r "$src_dir"/* "$web_root/"
    chmod +x "$web_root"/bin/*.sh "$web_root"/bin/*.py 2>/dev/null || true
    chmod +x "$web_root"/scripts/*.sh 2>/dev/null || true
    chown -R www-data:www-data "$web_root"
    chmod -R 755 "$web_root"
    mkdir -p "$web_root/data/protocols" "$web_root/admin/data" "$web_root/data/clients" \
        "$web_root/data/backups/tmp" "$web_root/data/settings"
    chmod -R 775 "$web_root/admin/data" "$web_root/data" "$web_root/install" 2>/dev/null || true
    chown -R www-data:www-data "$web_root/data" "$web_root/admin/data"
    usk_verify_panel_deploy "$web_root"
    usk_write_deploy_stamp "$web_root" "$src_dir"
}

usk_ensure_web_update_sudoers() {
    local web_root="$1"
    local sudoers="/etc/sudoers.d/unlimitsky"
    if [ ! -f "$sudoers" ]; then
        return 0
    fi
    if ! grep -qF 'panel-self-update.sh' "$sudoers" 2>/dev/null; then
        echo "www-data ALL=(root) NOPASSWD: /bin/bash ${web_root}/scripts/panel-self-update.sh *" >> "$sudoers"
    fi
    if ! grep -qF 'install-php-zip.sh' "$sudoers" 2>/dev/null; then
        echo "www-data ALL=(root) NOPASSWD: /bin/bash ${web_root}/bin/install-php-zip.sh *" >> "$sudoers"
    fi
    chmod 440 "$sudoers" 2>/dev/null || true
}

usk_write_deploy_stamp() {
    local web_root="$1"
    local src_dir="$2"
    local stamp="${web_root}/admin/data/.deploy-rev"
    mkdir -p "${web_root}/admin/data"
    if [ -d "${src_dir}/.git" ]; then
        git -C "$src_dir" rev-parse HEAD > "$stamp" 2>/dev/null || true
    elif [ -f "${src_dir}/admin/lib/backup.php" ]; then
        date -u +'%Y-%m-%dT%H:%M:%SZ' > "$stamp"
    fi
    chmod 640 "$stamp" 2>/dev/null || true
    chown www-data:www-data "$stamp" 2>/dev/null || true
}

usk_verify_panel_deploy() {
    local web_root="$1"
    local missing=0
    local f
    for f in \
        admin/lib/backup.php \
        admin/backup-action.php \
        admin/pages/backup.php \
        admin/includes/backup-panel.php \
        admin/lib/migration.php; do
        if [ ! -f "${web_root}/${f}" ]; then
            echo "ERROR: missing ${web_root}/${f}" >&2
            missing=1
        fi
    done
    if ! grep -q "'backup'" "${web_root}/admin/lib/init.php" 2>/dev/null; then
        echo "ERROR: admin/lib/init.php has no backup nav item — stale panel files?" >&2
        missing=1
    fi
    return "$missing"
}
