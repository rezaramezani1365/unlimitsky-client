<?php

/**
 * Sync scheduling helpers — NO background PHP spawn from web requests.
 * Heavy work runs only from cron/native-limits.php (flock + timeout).
 */
class USK_LiveStats
{
    const STALE_SEC = 600;

    public static function live_dir()
    {
        return USK_ROOT . '/data/live';
    }

    public static function request_sync_flag_path()
    {
        return self::live_dir() . '/request-sync.flag';
    }

    /** Mark that admin requested sync — next cron picks it up (no extra process). */
    public static function request_background_sync()
    {
        $dir = self::live_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(self::request_sync_flag_path(), (string) time());
        return true;
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
}
