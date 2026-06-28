<?php

class USK_PanelBackup
{
    const FORMAT = 'unlimitsky-backup';
    const VERSION = 2;

    /** @return string[] */
    public static function tables()
    {
        return array(
            'panel_admin',
            'category',
            'category_limit',
            'category_date',
            'orders',
            'panels',
            'hiddify_panels',
            'marzban_inbounds',
            'sanayi_panel_setting',
            'test_account_setting',
            'spam_setting',
            'notes',
        );
    }

    /** @return string[] paths relative to USK_ROOT */
    public static function data_paths()
    {
        return array(
            'data/protocols',
            'data/clients',
            'data/settings',
            'data/protocol-installed',
        );
    }

    /** @return string[] filenames under admin/data */
    public static function admin_data_files()
    {
        return array('api-keys.json', 'license.json');
    }

    /** Runtime files under data/settings — not included in backup. */
    private static function settings_skip_files()
    {
        return array(
            'php-zip-install.lock',
            'panel-update.lock',
        );
    }

    public static function zip_available()
    {
        return class_exists('ZipArchive');
    }

    /**
     * @return array{ok:bool, error?:string, path?:string, filename?:string}
     */
    public static function export()
    {
        if (!self::zip_available()) {
            return array('ok' => false, 'error' => 'zip_missing');
        }

        global $sql, $config;
        if (!($sql instanceof mysqli) || $sql->connect_error) {
            return array('ok' => false, 'error' => 'db_unavailable');
        }

        $tmpdir = self::temp_dir();
        if ($tmpdir === '') {
            return array('ok' => false, 'error' => 'temp_unavailable');
        }

        $work = $tmpdir . '/usk-export-' . bin2hex(random_bytes(4));
        if (!@mkdir($work, 0700, true) && !is_dir($work)) {
            return array('ok' => false, 'error' => 'temp_unavailable');
        }

        $snapshot = self::build_panel_snapshot();
        $manifest = array(
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'panel_version' => usk_panel_version(),
            'created_at' => gmdate('c'),
            'hostname' => php_uname('n'),
            'tables' => self::tables(),
            'data_paths' => self::data_paths(),
            'includes_vpn_runtime' => false,
            'includes_panel_urls' => true,
            'public_url' => (string) ($snapshot['public_url'] ?? ''),
            'settings_files' => self::list_settings_files(),
            'notes' => 'v2 panel backup — plans, services, client registry, panel/WC URLs, DNS. Reinstall protocols on new VPS; re-apply panel access in Settings.',
        );

        $sqlPath = $work . '/database.sql';
        $written = self::write_database_sql($sql, $sqlPath);
        if (!$written['ok']) {
            self::remove_tree($work);
            return $written;
        }

        file_put_contents(
            $work . '/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        file_put_contents(
            $work . '/panel-snapshot.json',
            json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        self::copy_export_data($work . '/data');
        self::copy_export_admin_data($work . '/admin-data');

        $filename = 'unlimitsky-backup-' . gmdate('Y-m-d-His') . '.uskbackup';
        $zipPath = $tmpdir . '/' . $filename;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            self::remove_tree($work);
            return array('ok' => false, 'error' => 'zip_create_failed');
        }

        self::add_tree_to_zip($zip, $work, '');
        $zip->close();
        self::remove_tree($work);

        return array('ok' => true, 'path' => $zipPath, 'filename' => $filename);
    }

    /**
     * @return array{ok:bool, error?:string, manifest?:array, stats?:array, had_pro_license?:bool, needs_panel_access_reapply?:bool, restored_public_url?:string}
     */
    public static function import($uploadPath)
    {
        if (!self::zip_available()) {
            return array('ok' => false, 'error' => 'zip_missing');
        }

        global $sql;
        if (!($sql instanceof mysqli) || $sql->connect_error) {
            return array('ok' => false, 'error' => 'db_unavailable');
        }

        if (!is_uploaded_file($uploadPath) && !is_file($uploadPath)) {
            return array('ok' => false, 'error' => 'invalid_upload');
        }

        $tmpdir = self::temp_dir();
        if ($tmpdir === '') {
            return array('ok' => false, 'error' => 'temp_unavailable');
        }

        $work = $tmpdir . '/usk-import-' . bin2hex(random_bytes(4));
        if (!@mkdir($work, 0700, true) && !is_dir($work)) {
            return array('ok' => false, 'error' => 'temp_unavailable');
        }

        $zip = new ZipArchive();
        if ($zip->open($uploadPath) !== true) {
            self::remove_tree($work);
            return array('ok' => false, 'error' => 'invalid_archive');
        }

        if (!$zip->extractTo($work)) {
            $zip->close();
            self::remove_tree($work);
            return array('ok' => false, 'error' => 'extract_failed');
        }
        $zip->close();

        $manifestFile = $work . '/manifest.json';
        if (!is_file($manifestFile)) {
            self::remove_tree($work);
            return array('ok' => false, 'error' => 'manifest_missing');
        }

        $manifest = json_decode(file_get_contents($manifestFile), true);
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT) {
            self::remove_tree($work);
            return array('ok' => false, 'error' => 'manifest_invalid');
        }

        $version = (int) ($manifest['version'] ?? 0);
        if ($version < 1 || $version > self::VERSION) {
            self::remove_tree($work);
            return array('ok' => false, 'error' => 'version_unsupported');
        }

        $sqlFile = $work . '/database.sql';
        if (!is_file($sqlFile)) {
            self::remove_tree($work);
            return array('ok' => false, 'error' => 'database_missing');
        }

        $imported = self::import_database_sql($sql, $sqlFile);
        if (!$imported['ok']) {
            self::remove_tree($work);
            return $imported;
        }

        $filesCopied = self::import_data_tree($work . '/data', USK_ROOT . '/data');
        $adminCopied = self::import_admin_data($work . '/admin-data', false);
        $licenseKey = self::read_backup_license_key($work . '/admin-data/license.json');

        $urlRestore = self::restore_panel_urls($work, $manifest);

        USK_Migration::clear_license_cache();
        if ($licenseKey !== '') {
            USK_Migration::mark_after_import($manifest, $licenseKey, array(
                'needs_panel_access_reapply' => true,
                'restored_public_url' => (string) ($urlRestore['public_url'] ?? ''),
            ));
        } else {
            USK_Migration::clear();
            if (!empty($urlRestore['public_url'])) {
                USK_Migration::mark_after_import($manifest, '', array(
                    'needs_license_reactivation' => false,
                    'needs_panel_access_reapply' => true,
                    'restored_public_url' => (string) $urlRestore['public_url'],
                ));
            }
        }

        self::remove_tree($work);

        return array(
            'ok' => true,
            'manifest' => $manifest,
            'stats' => array(
                'sql_statements' => $imported['statements'] ?? 0,
                'files_copied' => $filesCopied + $adminCopied,
            ),
            'had_pro_license' => $licenseKey !== '',
            'needs_panel_access_reapply' => !empty($urlRestore['needs_reapply']),
            'restored_public_url' => (string) ($urlRestore['public_url'] ?? ''),
            'config_domain_updated' => !empty($urlRestore['config_updated']),
        );
    }

    /**
     * @return array{ok:bool, error?:string, summary?:array}
     */
    public static function preview($uploadPath)
    {
        if (!self::zip_available()) {
            return array('ok' => false, 'error' => 'zip_missing');
        }

        $zip = new ZipArchive();
        if ($zip->open($uploadPath) !== true) {
            return array('ok' => false, 'error' => 'invalid_archive');
        }

        $manifestRaw = $zip->getFromName('manifest.json');
        $snapshotRaw = $zip->getFromName('panel-snapshot.json');
        $zip->close();
        if ($manifestRaw === false) {
            return array('ok' => false, 'error' => 'manifest_missing');
        }

        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT) {
            return array('ok' => false, 'error' => 'manifest_invalid');
        }

        $snapshot = is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : null;

        return array(
            'ok' => true,
            'summary' => array(
                'created_at' => $manifest['created_at'] ?? '',
                'panel_version' => $manifest['panel_version'] ?? '',
                'hostname' => $manifest['hostname'] ?? '',
                'tables' => $manifest['tables'] ?? array(),
                'includes_vpn_runtime' => !empty($manifest['includes_vpn_runtime']),
                'includes_panel_urls' => !empty($manifest['includes_panel_urls']),
                'public_url' => (string) ($manifest['public_url'] ?? ($snapshot['public_url'] ?? '')),
                'settings_files' => $manifest['settings_files'] ?? array(),
            ),
        );
    }

    /** @return array<string,mixed> */
    public static function build_panel_snapshot()
    {
        global $config;

        $snapshot = array(
            'config_domain' => rtrim(trim((string) ($config['domain'] ?? '')), '/'),
            'public_url' => '',
            'panel_access' => array(),
            'connect_host' => array(),
            'woocommerce_shop' => array(),
            'client_dns' => array(),
            'panel_access_applied' => null,
        );

        if (class_exists('USK_PanelAccess')) {
            $snapshot['panel_access'] = USK_PanelAccess::get();
            $snapshot['public_url'] = USK_PanelAccess::current_public_url();
        }
        if (class_exists('USK_ConnectHost')) {
            $snapshot['connect_host'] = USK_ConnectHost::get();
        }
        if (class_exists('USK_WooCommerce_Shop')) {
            $snapshot['woocommerce_shop'] = USK_WooCommerce_Shop::get();
        }
        if (class_exists('USK_ClientDns')) {
            $snapshot['client_dns'] = USK_ClientDns::get();
        }

        $appliedFile = USK_ROOT . '/data/settings/panel-access-applied.json';
        if (is_file($appliedFile)) {
            $applied = json_decode((string) file_get_contents($appliedFile), true);
            if (is_array($applied)) {
                $snapshot['panel_access_applied'] = $applied;
            }
        }

        if ($snapshot['public_url'] === '' && $snapshot['config_domain'] !== '') {
            $snapshot['public_url'] = $snapshot['config_domain'];
        }

        return $snapshot;
    }

    /** @return string[] */
    private static function list_settings_files()
    {
        $dir = USK_ROOT . '/data/settings';
        if (!is_dir($dir)) {
            return array();
        }
        $skip = array_flip(self::settings_skip_files());
        $files = array();
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..' || isset($skip[$item])) {
                continue;
            }
            if (is_file($dir . '/' . $item)) {
                $files[] = $item;
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Restore config.php domain from backup snapshot or imported settings.
     *
     * @return array{public_url?:string,config_updated?:bool,needs_reapply?:bool,error?:string}
     */
    private static function restore_panel_urls($workDir, array $manifest)
    {
        $snapshot = null;
        $snapshotFile = $workDir . '/panel-snapshot.json';
        if (is_file($snapshotFile)) {
            $snapshot = json_decode((string) file_get_contents($snapshotFile), true);
        }

        $publicUrl = '';
        if (is_array($snapshot)) {
            $publicUrl = rtrim(trim((string) ($snapshot['public_url'] ?? '')), '/');
            if ($publicUrl === '') {
                $publicUrl = rtrim(trim((string) ($snapshot['config_domain'] ?? '')), '/');
            }
        }
        if ($publicUrl === '') {
            $publicUrl = rtrim(trim((string) ($manifest['public_url'] ?? '')), '/');
        }
        if ($publicUrl === '' && class_exists('USK_PanelAccess')) {
            $publicUrl = USK_PanelAccess::public_url_from_settings();
        }

        $result = array(
            'public_url' => $publicUrl,
            'config_updated' => false,
            'needs_reapply' => true,
        );

        if ($publicUrl === '' || !class_exists('USK_PanelAccess')) {
            return $result;
        }

        $updated = USK_PanelAccess::update_config_domain($publicUrl);
        $result['config_updated'] = !empty($updated['ok']);
        if (empty($updated['ok'])) {
            $result['error'] = (string) ($updated['error'] ?? 'config_update_failed');
        }

        return $result;
    }

    private static function temp_dir()
    {
        $candidates = array(
            USK_ROOT . '/data/backups/tmp',
            sys_get_temp_dir(),
        );
        foreach ($candidates as $dir) {
            if ($dir === '' || !is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                return rtrim($dir, '/\\');
            }
        }
        return '';
    }

    /**
     * @return array{ok:bool, error?:string}
     */
    private static function write_database_sql(mysqli $db, $path)
    {
        $lines = array(
            '-- unlimitsky panel backup v' . self::VERSION,
            'SET NAMES utf8mb4;',
            'SET FOREIGN_KEY_CHECKS=0;',
        );

        foreach (self::tables() as $table) {
            $check = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
            if (!$check || $check->num_rows === 0) {
                continue;
            }

            $lines[] = 'DELETE FROM `' . $table . '`;';
            $res = $db->query('SELECT * FROM `' . $table . '`');
            if (!$res) {
                return array('ok' => false, 'error' => 'db_export_failed');
            }

            while ($row = $res->fetch_assoc()) {
                $cols = array();
                $vals = array();
                foreach ($row as $col => $val) {
                    $cols[] = '`' . str_replace('`', '``', $col) . '`';
                    if ($val === null) {
                        $vals[] = 'NULL';
                    } else {
                        $vals[] = "'" . $db->real_escape_string((string) $val) . "'";
                    }
                }
                $lines[] = 'INSERT INTO `' . $table . '` (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ');';
            }
            $res->free();
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';

        if (file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            return array('ok' => false, 'error' => 'db_write_failed');
        }

        return array('ok' => true);
    }

    /**
     * @return array{ok:bool, error?:string, statements?:int}
     */
    private static function import_database_sql(mysqli $db, $path)
    {
        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            return array('ok' => false, 'error' => 'database_empty');
        }

        $db->query('SET FOREIGN_KEY_CHECKS=0');
        $statements = 0;

        foreach (self::split_sql($sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || strpos($statement, '--') === 0) {
                continue;
            }
            if (!$db->query($statement)) {
                return array('ok' => false, 'error' => 'db_import_failed', 'statements' => $statements);
            }
            $statements++;
        }

        $db->query('SET FOREIGN_KEY_CHECKS=1');

        return array('ok' => true, 'statements' => $statements);
    }

    /** @return string[] */
    private static function split_sql($sql)
    {
        $parts = preg_split('/;\s*\n/', $sql);
        return is_array($parts) ? $parts : array($sql);
    }

    private static function copy_export_data($destDataRoot)
    {
        if (!is_dir($destDataRoot)) {
            @mkdir($destDataRoot, 0755, true);
        }
        foreach (self::data_paths() as $rel) {
            $rel = str_replace('\\', '/', $rel);
            $src = USK_ROOT . '/' . $rel;
            if (!is_dir($src)) {
                continue;
            }
            $sub = preg_replace('#^data/#', '', $rel);
            $dest = $destDataRoot . '/' . $sub;
            $skipFiles = ($sub === 'settings') ? self::settings_skip_files() : array();
            self::copy_tree($src, $dest, false, $skipFiles);
        }
    }

    private static function copy_export_admin_data($destRoot)
    {
        if (!is_dir($destRoot)) {
            @mkdir($destRoot, 0755, true);
        }
        $adminData = dirname(__DIR__) . '/data';
        foreach (self::admin_data_files() as $file) {
            $src = $adminData . '/' . $file;
            if (is_file($src)) {
                copy($src, $destRoot . '/' . $file);
            }
        }
    }

    private static function import_data_tree($srcRoot, $destRoot)
    {
        if (!is_dir($srcRoot)) {
            return 0;
        }

        $count = 0;
        $items = scandir($srcRoot);
        if (!is_array($items)) {
            return 0;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $from = $srcRoot . '/' . $item;
            $to = $destRoot . '/' . $item;
            if (!is_dir($from)) {
                continue;
            }
            if (is_dir($to)) {
                self::remove_tree($to);
            }
            $count += self::copy_tree($from, $to, false);
        }

        return $count;
    }

    private static function read_backup_license_key($path)
    {
        if (!is_file($path)) {
            return '';
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return '';
        }
        $key = strtoupper(trim((string) ($data['license_key'] ?? '')));
        if ($key === '' || ($data['tier'] ?? '') !== 'pro') {
            return '';
        }
        return $key;
    }

    private static function import_admin_data($srcRoot, $includeLicense = true)
    {
        if (!is_dir($srcRoot)) {
            return 0;
        }
        $dest = dirname(__DIR__) . '/data';
        if (!is_dir($dest)) {
            @mkdir($dest, 0755, true);
        }
        $count = 0;
        foreach (self::admin_data_files() as $file) {
            if (!$includeLicense && $file === 'license.json') {
                continue;
            }
            $src = $srcRoot . '/' . $file;
            if (is_file($src)) {
                copy($src, $dest . '/' . $file);
                $count++;
            }
        }
        return $count;
    }

    private static function copy_tree($src, $dest, $merge = false, array $skipFiles = array())
    {
        $count = 0;
        if (!is_dir($src)) {
            return 0;
        }
        if (!$merge && is_dir($dest)) {
            self::remove_tree($dest);
        }
        if (!is_dir($dest)) {
            @mkdir($dest, 0755, true);
        }

        $skip = array_flip($skipFiles);
        $items = scandir($src);
        if (!is_array($items)) {
            return 0;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (isset($skip[$item])) {
                continue;
            }
            $from = $src . '/' . $item;
            $to = $dest . '/' . $item;
            if (is_dir($from)) {
                $count += self::copy_tree($from, $to, true, $skipFiles);
            } elseif (is_file($from)) {
                @mkdir(dirname($to), 0755, true);
                if (copy($from, $to)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private static function add_tree_to_zip(ZipArchive $zip, $dir, $prefix)
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $prefix = $prefix === '' ? '' : rtrim(str_replace('\\', '/', $prefix), '/') . '/';

        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            $local = $prefix . $item;
            if (is_dir($path)) {
                self::add_tree_to_zip($zip, $path, $local);
            } elseif (is_file($path)) {
                $zip->addFile($path, $local);
            }
        }
    }

    private static function remove_tree($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::remove_tree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
