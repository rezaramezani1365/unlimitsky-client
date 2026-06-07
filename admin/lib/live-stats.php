<?php

/** @deprecated Use USK_UsageSyncSettings — kept for backward compatibility. */
class USK_LiveStats
{
    public static function request_background_sync()
    {
        require_once __DIR__ . '/usage-sync-settings.php';
        return USK_UsageSyncSettings::request_force_sync();
    }

    public static function cache_age_sec()
    {
        require_once __DIR__ . '/usage-sync-settings.php';
        $status = USK_UsageSyncSettings::status();
        $last = trim((string) ($status['last_sync_at'] ?? ''));
        if ($last === '') {
            return null;
        }
        $ts = strtotime($last);
        return $ts === false ? null : max(0, time() - $ts);
    }

    public static function is_fresh($maxAge = 600)
    {
        $age = self::cache_age_sec();
        return $age !== null && $age <= max(1, (int) $maxAge);
    }
}
