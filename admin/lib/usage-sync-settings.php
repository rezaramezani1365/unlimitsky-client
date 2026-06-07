<?php

class USK_UsageSyncSettings
{
    const MIN_INTERVAL = 1;
    const MAX_INTERVAL = 120;

    /** @var array<int, int> */
    public static function preset_intervals()
    {
        return array(1, 2, 3, 5, 10, 15, 30, 60);
    }

    private static function settings_file()
    {
        $dir = USK_ROOT . '/data/settings';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/usage-sync.json';
    }

    private static function state_file()
    {
        $dir = USK_ROOT . '/data/live';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/usage-sync-state.json';
    }

    public static function defaults()
    {
        return array(
            'enabled' => true,
            'interval_minutes' => 5,
            'hint' => '',
            'updated_at' => null,
        );
    }

    public static function get()
    {
        $file = self::settings_file();
        if (!is_file($file)) {
            return self::defaults();
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return self::defaults();
        }
        $cfg = array_merge(self::defaults(), $data);
        $cfg['interval_minutes'] = self::sanitize_interval($cfg['interval_minutes']);
        $cfg['enabled'] = !empty($cfg['enabled']);
        return $cfg;
    }

    public static function save(array $input)
    {
        $cfg = self::defaults();
        $cfg['enabled'] = !empty($input['enabled']);
        $cfg['interval_minutes'] = self::sanitize_interval($input['interval_minutes'] ?? $cfg['interval_minutes']);
        $cfg['hint'] = trim((string) ($input['hint'] ?? ''));
        $cfg['updated_at'] = date('c');
        file_put_contents(
            self::settings_file(),
            json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        return $cfg;
    }

    public static function sanitize_interval($value)
    {
        $n = (int) $value;
        if ($n < self::MIN_INTERVAL) {
            $n = self::MIN_INTERVAL;
        }
        if ($n > self::MAX_INTERVAL) {
            $n = self::MAX_INTERVAL;
        }
        return $n;
    }

    /** @return array<string, mixed> */
    private static function read_state()
    {
        $file = self::state_file();
        if (!is_file($file)) {
            return array();
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : array();
    }

    /** @param array<string, mixed> $state */
    private static function write_state(array $state)
    {
        file_put_contents(self::state_file(), json_encode($state, JSON_UNESCAPED_UNICODE));
    }

    public static function force_flag_path()
    {
        return USK_ROOT . '/data/live/request-sync.flag';
    }

    public static function request_force_sync()
    {
        $dir = dirname(self::force_flag_path());
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(self::force_flag_path(), (string) time());
        return true;
    }

    public static function has_force_request()
    {
        return is_file(self::force_flag_path());
    }

    public static function clear_force_request()
    {
        if (is_file(self::force_flag_path())) {
            @unlink(self::force_flag_path());
        }
    }

    public static function is_due()
    {
        $cfg = self::get();
        if (empty($cfg['enabled'])) {
            return false;
        }
        if (self::has_force_request()) {
            return true;
        }

        $state = self::read_state();
        $last = trim((string) ($state['last_sync_at'] ?? ''));
        if ($last === '') {
            return true;
        }
        $ts = strtotime($last);
        if ($ts === false) {
            return true;
        }
        $interval = max(1, (int) $cfg['interval_minutes']);
        return (time() - $ts) >= ($interval * 60);
    }

    public static function lock_path()
    {
        return USK_ROOT . '/data/live/native-limits.lock';
    }

    /** @return resource|false */
    public static function acquire_lock()
    {
        $path = self::lock_path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $fp = @fopen($path, 'c');
        if ($fp === false) {
            return false;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }
        return $fp;
    }

    /** @param resource $fp */
    public static function release_lock($fp)
    {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Sync usage from VPN, enforce volume/time limits, update connections.
     *
     * @return array<string, mixed>
     */
    public static function run_sync_job()
    {
        require_once __DIR__ . '/protocols/limits.php';
        require_once __DIR__ . '/protocols/connections.php';

        $report = USK_ProtocolLimits::enforce_all_with_connections();
        USK_ProtocolLimits::save_last_run($report);
        if (!empty($report['connections']) && is_array($report['connections'])) {
            USK_ProtocolConnections::save_last_run($report['connections']);
        }
        self::clear_force_request();

        $state = self::read_state();
        $state['last_sync_at'] = date('c');
        self::write_state($state);

        return $report;
    }

    /** Status for settings page. @return array<string, mixed> */
    public static function status()
    {
        require_once __DIR__ . '/protocols/limits.php';

        $cfg = self::get();
        $state = self::read_state();
        $lastRun = USK_ProtocolLimits::get_last_run();
        $lastSync = trim((string) ($state['last_sync_at'] ?? ''));
        $nextDue = null;
        if ($lastSync !== '') {
            $ts = strtotime($lastSync);
            if ($ts !== false) {
                $nextDue = date('c', $ts + ((int) $cfg['interval_minutes'] * 60));
            }
        }

        return array(
            'enabled' => !empty($cfg['enabled']),
            'interval_minutes' => (int) $cfg['interval_minutes'],
            'last_sync_at' => $lastSync,
            'next_due_at' => $nextDue,
            'last_run' => is_array($lastRun) ? $lastRun : null,
            'force_pending' => self::has_force_request(),
        );
    }
}
