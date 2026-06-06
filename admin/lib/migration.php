<?php

class USK_Migration
{
    private static function file_path()
    {
        return dirname(__DIR__) . '/data/migration-pending.json';
    }

    /**
     * Called after panel backup import — Pro cache is not restored; user must re-activate.
     */
    public static function mark_after_import(array $manifest, $licenseKey = '', array $extra = array())
    {
        $licenseKey = strtoupper(trim((string) $licenseKey));
        $data = array(
            'imported_at' => gmdate('c'),
            'from_hostname' => (string) ($manifest['hostname'] ?? ''),
            'from_panel_version' => (string) ($manifest['panel_version'] ?? ''),
            'license_key_hint' => $licenseKey,
            'needs_license_reactivation' => array_key_exists('needs_license_reactivation', $extra)
                ? !empty($extra['needs_license_reactivation'])
                : ($licenseKey !== ''),
            'needs_panel_access_reapply' => !empty($extra['needs_panel_access_reapply']),
            'restored_public_url' => (string) ($extra['restored_public_url'] ?? ''),
        );

        $dir = dirname(self::file_path());
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents(
            self::file_path(),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        @chmod(self::file_path(), 0640);
    }

    public static function get_pending()
    {
        $file = self::file_path();
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    public static function needs_license_reactivation()
    {
        $pending = self::get_pending();
        return is_array($pending) && !empty($pending['needs_license_reactivation']);
    }

    public static function license_key_hint()
    {
        $pending = self::get_pending();
        if (!is_array($pending)) {
            return '';
        }
        return (string) ($pending['license_key_hint'] ?? '');
    }

    public static function needs_panel_access_reapply()
    {
        $pending = self::get_pending();
        return is_array($pending) && !empty($pending['needs_panel_access_reapply']);
    }

    public static function restored_public_url()
    {
        $pending = self::get_pending();
        if (!is_array($pending)) {
            return '';
        }
        return (string) ($pending['restored_public_url'] ?? '');
    }

    public static function clear()
    {
        $file = self::file_path();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public static function clear_license_cache()
    {
        $licenseFile = dirname(__DIR__) . '/data/license.json';
        if (is_file($licenseFile)) {
            @unlink($licenseFile);
        }
    }
}

function usk_license_error_message($code)
{
    $code = strtolower(trim((string) $code));
    $key = 'license_err_' . preg_replace('/[^a-z0-9_]/', '_', $code);
    $msg = __($key, $code);
    return ($msg !== $key && $msg !== $code) ? $msg : $code;
}
