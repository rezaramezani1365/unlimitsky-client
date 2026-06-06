<?php

class USK_PanelBackup
{
    const FORMAT = 'unlimitsky-backup';
    const VERSION = 1;

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

        $manifest = array(
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'panel_version' => usk_panel_version(),
            'created_at' => gmdate('c'),
            'hostname' => php_uname('n'),
            'tables' => self::tables(),
            'includes_vpn_runtime' => false,
            'notes' => 'v1 panel backup — reinstall protocols on new VPS; replay VPN users in v2',
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
     * @return array{ok:bool, error?:string, manifest?:array, stats?:array}
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

        USK_Migration::clear_license_cache();
        if ($licenseKey !== '') {
            USK_Migration::mark_after_import($manifest, $licenseKey);
        } else {
            USK_Migration::clear();
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
        $zip->close();
        if ($manifestRaw === false) {
            return array('ok' => false, 'error' => 'manifest_missing');
        }

        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT) {
            return array('ok' => false, 'error' => 'manifest_invalid');
        }

        return array(
            'ok' => true,
            'summary' => array(
                'created_at' => $manifest['created_at'] ?? '',
                'panel_version' => $manifest['panel_version'] ?? '',
                'hostname' => $manifest['hostname'] ?? '',
                'tables' => $manifest['tables'] ?? array(),
                'includes_vpn_runtime' => !empty($manifest['includes_vpn_runtime']),
            ),
        );
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
            self::copy_tree($src, $dest);
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
        return self::copy_tree($srcRoot, $destRoot, true);
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

    private static function copy_tree($src, $dest, $merge = false)
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

        $items = scandir($src);
        if (!is_array($items)) {
            return 0;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $from = $src . '/' . $item;
            $to = $dest . '/' . $item;
            if (is_dir($from)) {
                $count += self::copy_tree($from, $to, true);
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
