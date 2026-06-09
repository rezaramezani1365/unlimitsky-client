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
        for line in "$@"; do
            echo "$line"
        done
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

usk_php_discover_versions() {
    local v bin seen=""
    add_ver() {
        v="$1"
        [ -z "$v" ] && return
        case " $seen " in
            *" $v "*) return ;;
        esac
        seen="$seen $v"
        echo "$v"
    }

    if command -v php >/dev/null 2>&1; then
        add_ver "$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
    fi

    for bin in /usr/bin/php[0-9]*.[0-9]* /usr/bin/php[0-9]*; do
        [ -x "$bin" ] || continue
        add_ver "$("$bin" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
    done

    local svc
    for svc in /lib/systemd/system/php*-fpm.service; do
        [ -f "$svc" ] || continue
        v="$(basename "$svc" .service | sed -n 's/^php\([0-9.]*\)-fpm$/\1/p')"
        add_ver "$v"
    done
}

usk_php_cli_bins() {
    local bin
    if command -v php >/dev/null 2>&1; then
        echo php
    fi
    for bin in /usr/bin/php[0-9]*.[0-9]* /usr/bin/php[0-9]*; do
        [ -x "$bin" ] || continue
        echo "$bin"
    done
}

usk_zip_cli_ok() {
    local bin
    for bin in $(usk_php_cli_bins); do
        [ -x "$bin" ] 2>/dev/null || [ "$bin" = php ] || continue
        if "$bin" -r 'exit(class_exists("ZipArchive") ? 0 : 1);' 2>/dev/null; then
            return 0
        fi
    done
    return 1
}

usk_apt_with_timeout() {
    local secs="$1"
    shift
    export DEBIAN_FRONTEND=noninteractive
    export NEEDRESTART_MODE=a
    if command -v timeout >/dev/null 2>&1; then
        timeout "$secs" "$@"
    else
        "$@"
    fi
}

usk_apt_zip_package_candidates() {
    local v seen="" pkg
    for v in $(usk_php_discover_versions | sort -u -V); do
        [ -z "$v" ] && continue
        pkg="php${v}-zip"
        case " $seen " in *" $pkg "*) ;; *) seen="$seen $pkg"; echo "$pkg" ;; esac
    done
    if command -v apt-cache >/dev/null 2>&1; then
        while read -r pkg; do
            [ -z "$pkg" ] && continue
            case " $seen " in *" $pkg "*) ;; *) seen="$seen $pkg"; echo "$pkg" ;; esac
        done < <(apt-cache search --names-only '^php[0-9.]+-zip$' 2>/dev/null | awk '{print $1}' | sort -u -V)
    fi
    if dpkg -l php-*-fpm php-*-cli 2>/dev/null | awk '/^ii/ {print $2}' | grep -q .; then
        while read -r pkg; do
            [ -z "$pkg" ] && continue
            case " $seen " in *" $pkg "*) ;; *) seen="$seen $pkg"; echo "$pkg" ;; esac
        done < <(dpkg -l 'php*-fpm' 'php*-cli' 2>/dev/null | awk '/^ii/ {print $2}' | sed -n 's/^php\([0-9.]*\)-.*/php\1-zip/p' | sort -u -V)
    fi
    case " $seen " in *" php-zip "*) ;; *) echo "php-zip" ;; esac
}

usk_apt_ensure_universe() {
    local log="${1:-/tmp/usk-php-zip-apt.log}"
    if apt-cache search --names-only '^php[0-9.]+-zip$' 2>/dev/null | grep -q .; then
        return 0
    fi
    echo "[*] No php*-zip in apt index — enabling universe repository..."
    if command -v add-apt-repository >/dev/null 2>&1; then
        usk_apt_with_timeout 60 add-apt-repository -y universe >>"$log" 2>&1 || true
        usk_apt_with_timeout 120 apt-get update -qq >>"$log" 2>&1 || true
    fi
}

usk_apt_pkg_available() {
    local pkg="$1"
    apt-cache show "$pkg" >/dev/null 2>&1
}

usk_ensure_php_zip() {
    if usk_zip_cli_ok; then
        return 0
    fi

    local log="${USK_APT_LOG:-/tmp/usk-php-zip-apt.log}"
    echo "[*] Installing PHP zip extension..."
    : > "$log"

    echo "[*] apt-get update (timeout 120s)..."
    usk_apt_with_timeout 120 apt-get update >>"$log" 2>&1 || echo "[!] apt-get update warning (see log)" >&2

    usk_apt_ensure_universe "$log"

    echo "[*] PHP versions: $(usk_php_discover_versions | tr '\n' ' ')"
    echo "[*] Candidate packages: $(usk_apt_zip_package_candidates | tr '\n' ' ')"

    local pkg v rc apt_rc
    while IFS= read -r pkg; do
        [ -z "$pkg" ] && continue
        if ! usk_apt_pkg_available "$pkg"; then
            echo "[!] Package not in apt index: $pkg (apt-cache policy $pkg)" >&2
            apt-cache policy "$pkg" 2>/dev/null | head -5 >&2 || true
            continue
        fi
        echo "[*] apt-get install -y --no-install-recommends $pkg (timeout 300s)"
        set +e
        usk_apt_with_timeout 300 apt-get install -y --no-install-recommends \
            -o Dpkg::Options::=--force-confdef \
            -o Dpkg::Options::=--force-confold \
            "$pkg" >>"$log" 2>&1
        apt_rc=$?
        set -e
        if [ "$apt_rc" -eq 124 ]; then
            echo "[!] TIMEOUT (300s) installing $pkg — VPS too slow or apt stuck" >&2
            continue
        fi
        if [ "$apt_rc" -ne 0 ]; then
            echo "[!] apt exit $apt_rc for $pkg" >&2
            tail -5 "$log" 2>/dev/null >&2 || true
            continue
        fi
        echo "[*] installed $pkg"
        v="$(echo "$pkg" | sed -n 's/^php\([0-9.]*\)-zip$/\1/p')"
        if [ -n "$v" ] && command -v phpenmod >/dev/null 2>&1; then
            phpenmod -v "$v" zip 2>/dev/null || phpenmod zip 2>/dev/null || true
        fi
        if usk_zip_cli_ok; then
            return 0
        fi
    done < <(usk_apt_zip_package_candidates)

    echo "[!] ZipArchive still missing after apt." >&2
    echo "[!] apt log tail:" >&2
    tail -15 "$log" 2>/dev/null >&2 || true
    return 1
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
    if ! grep -qF 'apply-panel-access.sh' "$sudoers" 2>/dev/null; then
        echo "www-data ALL=(root) NOPASSWD: /bin/bash ${web_root}/bin/apply-panel-access.sh *" >> "$sudoers"
    fi
    if ! grep -qF 'run-panel-update.sh' "$sudoers" 2>/dev/null; then
        echo "www-data ALL=(root) NOPASSWD: /bin/bash ${web_root}/bin/run-panel-update.sh *" >> "$sudoers"
    fi
    if ! grep -qF 'collect-usage-stats.sh' "$sudoers" 2>/dev/null; then
        echo "www-data ALL=(root) NOPASSWD: /bin/bash ${web_root}/bin/collect-usage-stats.sh" >> "$sudoers"
    fi
    if ! grep -qF 'run-native-limits.sh' "$sudoers" 2>/dev/null; then
        echo "www-data ALL=(root) NOPASSWD: /bin/bash ${web_root}/bin/run-native-limits.sh" >> "$sudoers"
    fi
    if ! grep -qF 'xray-fix-stats-api.sh' "$sudoers" 2>/dev/null; then
        echo "www-data ALL=(root) NOPASSWD: /bin/bash ${web_root}/bin/xray-fix-stats-api.sh" >> "$sudoers"
    fi
    if ! grep -qF 'enforce-connection-limits.sh' "$sudoers" 2>/dev/null; then
        echo "www-data ALL=(root) NOPASSWD: /bin/bash ${web_root}/bin/enforce-connection-limits.sh" >> "$sudoers"
    fi
    if ! grep -qF 'enforce-xray-iplimit.sh' "$sudoers" 2>/dev/null; then
        echo "www-data ALL=(root) NOPASSWD: /bin/bash ${web_root}/bin/enforce-xray-iplimit.sh" >> "$sudoers"
    fi
    if ! grep -qF 'install-fail2ban-iplimit.sh' "$sudoers" 2>/dev/null; then
        echo "www-data ALL=(root) NOPASSWD: /bin/bash ${web_root}/bin/install-fail2ban-iplimit.sh *" >> "$sudoers"
    fi
    if ! grep -qF 'xray-emergency-fix.sh' "$sudoers" 2>/dev/null; then
        echo "www-data ALL=(root) NOPASSWD: /bin/bash ${web_root}/bin/xray-emergency-fix.sh" >> "$sudoers"
    fi
    if ! grep -qF 'panel-self-update.sh' "$sudoers" 2>/dev/null; then
        echo "www-data ALL=(root) NOPASSWD: /bin/bash ${web_root}/scripts/panel-self-update.sh *" >> "$sudoers"
    fi
    chmod 440 "$sudoers" 2>/dev/null || true
}

# Cron gate runs every minute; heavy sync only when interval in data/settings/usage-sync.json elapses.
usk_ensure_usage_cron() {
    local web_root="$1"
    local php_bin
    php_bin="$(command -v php 2>/dev/null || true)"
    [ -n "$php_bin" ] || return 0
    [ -f "${web_root}/cron/usage-sync-gate.php" ] || return 0

    local lock_file="/var/run/unlimitsky-limits.lock"
    local cron_file="/etc/cron.d/unlimitsky-limits"
    cat > "$cron_file" <<EOF
# unlimitsky — usage gate every minute (interval set in panel Settings)
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
* * * * * root flock -n ${lock_file} timeout 130 ${php_bin} ${web_root}/cron/usage-sync-gate.php >> /var/log/unlimitsky-limits.log 2>&1
EOF
    chmod 644 "$cron_file" 2>/dev/null || true
    touch /var/log/unlimitsky-limits.log 2>/dev/null || true
    chmod 644 /var/log/unlimitsky-limits.log 2>/dev/null || true

    mkdir -p "${web_root}/data/settings" "${web_root}/data/live" 2>/dev/null || true
    if [ ! -f "${web_root}/data/settings/usage-sync.json" ]; then
        printf '%s\n' '{"enabled":true,"interval_minutes":5,"hint":"","updated_at":null}' \
            > "${web_root}/data/settings/usage-sync.json" 2>/dev/null || true
        chown www-data:www-data "${web_root}/data/settings/usage-sync.json" 2>/dev/null || true
    fi
}

# Removed: separate connections cron (merged into native-limits.php).
usk_remove_connections_cron() {
    rm -f /etc/cron.d/unlimitsky-connections 2>/dev/null || true
}

# Configure Fail2ban IP-limit jail when Xray is present (3x-ui style). Installs fail2ban if missing.
usk_ensure_fail2ban_iplimit() {
    local web_root="$1"
    local script="${web_root}/bin/install-fail2ban-iplimit.sh"
    [ -f "$script" ] || return 0
    [ -f "${web_root}/bin/xray-common.sh" ] || return 0
    # shellcheck disable=SC1091
    source "${web_root}/bin/xray-common.sh" 2>/dev/null || true
    [ -f "${XRAY_CFG:-/var/lib/unlimitsky/xray/config.json}" ] || return 0
    if command -v fail2ban-client >/dev/null 2>&1 && fail2ban-client status usk-ipl >/dev/null 2>&1; then
        return 0
    fi
    bash "$script" 30 >> /var/log/unlimitsky-fail2ban-install.log 2>&1 || true
}

# Stop daemon and kill orphan sync processes that pile up on small VPS.
usk_disable_live_stats_daemon() {
    local web_root="$1"

    systemctl stop unlimitsky-live-stats.service 2>/dev/null || true
    systemctl disable unlimitsky-live-stats.service 2>/dev/null || true
    rm -f /etc/systemd/system/unlimitsky-live-stats.service 2>/dev/null || true
    systemctl daemon-reload 2>/dev/null || true

    pkill -f 'live-stats-daemon.sh' 2>/dev/null || true
    pkill -f 'live-stats-worker.php' 2>/dev/null || true
    pkill -f 'cron/native-limits.php' 2>/dev/null || true
    pkill -f 'collect-usage-stats.sh' 2>/dev/null || true

    rm -f "${web_root}/data/live/worker.lock" "${web_root}/data/live/sync.lock" 2>/dev/null || true
    rm -f /var/run/unlimitsky-limits.lock 2>/dev/null || true
}

# Event-driven connection slot enforcement (Xray log watcher + OpenVPN client-connect).
usk_ensure_connection_slot_hooks() {
    local web_root="$1"
    local guard="${web_root}/bin/xray-connection-guard.sh"
    [ -f "$guard" ] || return 0

    chmod +x "${web_root}/bin/xray-on-connect.sh" \
        "${web_root}/bin/xray-connection-guard.sh" \
        "${web_root}/bin/openvpn-client-connect.sh" \
        "${web_root}/bin/openvpn-ensure-slot-hook.sh" \
        "${web_root}/bin/connection-slots-common.sh" 2>/dev/null || true

    bash "${web_root}/bin/openvpn-ensure-slot-hook.sh" >>/var/log/unlimitsky-slot-hooks.log 2>&1 || true

    local unit="/etc/systemd/system/unlimitsky-xray-guard.service"
    local tpl="${web_root}/install/unlimitsky-xray-guard.service"
    if [ -f "$tpl" ]; then
        sed "s|__WEB_ROOT__|${web_root}|g" "$tpl" > "$unit"
    else
        cat > "$unit" <<EOF
[Unit]
Description=UnlimitSky Xray connection slot guard
After=network-online.target

[Service]
Type=simple
Environment=WEB_ROOT=${web_root}
ExecStart=/bin/bash ${web_root}/bin/xray-connection-guard.sh
Restart=always
RestartSec=5
User=root

[Install]
WantedBy=multi-user.target
EOF
    fi
    systemctl daemon-reload 2>/dev/null || true
    systemctl enable unlimitsky-xray-guard.service >/dev/null 2>&1 || true
    systemctl restart unlimitsky-xray-guard.service 2>/dev/null || true
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
