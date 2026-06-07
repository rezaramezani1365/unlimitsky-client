<?php

/**
 * Lightweight helpers only — NO periodic collect loop.
 * Heavy sync runs via cron (native-limits.php) or a rate-limited manual spawn.
 */
class USK_LiveStats
{
    const SPAWN_COOLDOWN_SEC = 90;
    const STALE_SEC = 120;

    public static function live_dir()
    {
        return USK_ROOT . '/data/live';
    }

    public static function lock_path()
    {
        return self::live_dir() . '/sync.lock';
    }

    public static function spawn_cooldown_path()
    {
        return self::live_dir() . '/last-spawn.ts';
    }

    /** Queue one native-limits run (max once per 90s, non-blocking). */
    public static function request_background_sync()
    {
        $dir = self::live_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (self::spawn_cooldown_active()) {
            return false;
        }

        if (self::sync_running()) {
            return false;
        }

        $script = USK_ROOT . '/cron/native-limits.php';
        if (!is_file($script)) {
            return false;
        }

        @file_put_contents(self::spawn_cooldown_path(), (string) time());

        $php = self::php_cli_bin();
        $log = $dir . '/native-limits.log';
        $cmd = 'nohup timeout 180 ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
        @shell_exec($cmd);
        return true;
    }

    public static function spawn_cooldown_active()
    {
        $path = self::spawn_cooldown_path();
        if (!is_file($path)) {
            return false;
        }
        $last = (int) trim((string) @file_get_contents($path));
        return $last > 0 && (time() - $last) < self::SPAWN_COOLDOWN_SEC;
    }

    public static function sync_running()
    {
        $lockFp = @fopen(self::lock_path(), 'c');
        if ($lockFp === false) {
            return false;
        }
        $busy = !flock($lockFp, LOCK_EX | LOCK_NB);
        if (!$busy) {
            flock($lockFp, LOCK_UN);
        }
        fclose($lockFp);
        return $busy;
    }

    public static function cache_age_sec()
    {
        require_once __DIR__ . '/protocols/limits.php';
        $last = USK_ProtocolLimits::get_last_run();
        if (!is_array($last) || empty($last['ran_at'])) {
            return null;
        }
        $ts = strtotime((string) $last['ran_at']);
        if ($ts === false) {
            return null;
        }
        return max(0, time() - $ts);
    }

    public static function is_fresh($maxAge = null)
    {
        $maxAge = $maxAge === null ? self::STALE_SEC : max(1, (int) $maxAge);
        $age = self::cache_age_sec();
        return $age !== null && $age <= $maxAge;
    }

    private static function php_cli_bin()
    {
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && stripos(PHP_BINARY, 'fpm') === false) {
            return PHP_BINARY;
        }
        $which = trim((string) @shell_exec('command -v php 2>/dev/null'));
        return $which !== '' ? $which : 'php';
    }
}
