<?php

require_once __DIR__ . '/protocols/limits.php';
require_once __DIR__ . '/protocols/usage.php';

class USK_LiveStats
{
    const DEFAULT_INTERVAL_SEC = 5;
    const STALE_SEC = 20;

    public static function live_dir()
    {
        return USK_ROOT . '/data/live';
    }

    public static function cache_path()
    {
        return self::live_dir() . '/stats-cache.json';
    }

    public static function lock_path()
    {
        return self::live_dir() . '/worker.lock';
    }

    public static function heartbeat_path()
    {
        return self::live_dir() . '/heartbeat.json';
    }

    public static function force_sync_path()
    {
        return self::live_dir() . '/force-sync.flag';
    }

    /** @return array<string, mixed>|null */
    public static function read_cache()
    {
        $path = self::cache_path();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public static function cache_age_sec()
    {
        $cache = self::read_cache();
        if (!$cache || empty($cache['updated_at'])) {
            return null;
        }
        $ts = strtotime((string) $cache['updated_at']);
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

    /** Queue immediate sync (non-blocking HTTP). */
    public static function request_background_sync()
    {
        $dir = self::live_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(self::force_sync_path(), (string) time());
        self::spawn_worker_once();
        return true;
    }

    public static function spawn_worker_once()
    {
        $script = USK_ROOT . '/cron/live-stats-worker.php';
        if (!is_file($script)) {
            return false;
        }
        $php = self::php_cli_bin();
        $log = self::live_dir() . '/worker.log';
        if (!is_dir(self::live_dir())) {
            @mkdir(self::live_dir(), 0755, true);
        }
        $cmd = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
        @shell_exec($cmd);
        return true;
    }

    /** One collect + sync + cache write (CLI / daemon). */
    public static function tick()
    {
        $dir = self::live_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $lockFp = @fopen(self::lock_path(), 'c');
        if ($lockFp === false) {
            return array('ok' => false, 'error' => 'lock_open_failed');
        }
        if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
            fclose($lockFp);
            return array('ok' => false, 'error' => 'worker_busy');
        }

        $report = array('ok' => true, 'updated_at' => date('c'));
        try {
            $usageUpdated = USK_ProtocolUsage::sync_all();
            $meta = USK_ProtocolUsage::last_collect_meta();
            $report['usage_updated'] = (int) $usageUpdated;
            $report['usage_meta'] = is_array($meta) ? $meta : array();
            $report['connections_synced'] = (int) ($meta['connections_synced'] ?? 0);
            $report['usage_synced'] = (int) ($meta['usage_synced'] ?? 0);

            $cache = self::build_cache($report);
            self::write_cache($cache);
            self::write_heartbeat($report);

            if (is_file(self::force_sync_path())) {
                @unlink(self::force_sync_path());
            }
        } catch (Throwable $e) {
            $report['ok'] = false;
            $report['error'] = $e->getMessage();
            error_log('USK live-stats tick: ' . $e->getMessage());
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private static function build_cache(array $report)
    {
        $clients = array();
        foreach (array('wireguard', 'openvpn', 'xray', 'amnezia') as $protocol) {
            $map = USK_ProtocolLimits::load_protocol_clients($protocol);
            foreach ($map as $username => $rec) {
                if (!is_array($rec)) {
                    continue;
                }
                $username = (string) $username;
                $usage = USK_ProtocolUsage::usage_stats($protocol, $rec, 0);
                $clients[$protocol][$username] = array(
                    'usage_bytes' => (int) ($usage['used_bytes'] ?? 0),
                    'active_connections' => (int) ($usage['active_connections'] ?? 0),
                    'usage_synced_at' => (string) ($usage['synced_at'] ?? ''),
                    'connections_synced_at' => (string) ($usage['connections_synced_at'] ?? ''),
                    'connections_display' => (string) ($usage['connections_display'] ?? ''),
                );
            }
        }

        return array(
            'ok' => !empty($report['ok']),
            'updated_at' => date('c'),
            'usage_updated' => (int) ($report['usage_updated'] ?? 0),
            'usage_meta' => $report['usage_meta'] ?? array(),
            'clients' => $clients,
        );
    }

    /** @param array<string, mixed> $cache */
    private static function write_cache(array $cache)
    {
        $path = self::cache_path();
        $tmp = $path . '.tmp.' . getmypid();
        $json = json_encode($cache, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        file_put_contents($tmp, $json, LOCK_EX);
        @rename($tmp, $path);
    }

    /** @param array<string, mixed> $report */
    private static function write_heartbeat(array $report)
    {
        $payload = array(
            'at' => date('c'),
            'ok' => !empty($report['ok']),
            'usage_updated' => (int) ($report['usage_updated'] ?? 0),
            'parse_ok' => !isset($report['usage_meta']['parse_ok']) || $report['usage_meta']['parse_ok'] !== false,
        );
        @file_put_contents(self::heartbeat_path(), json_encode($payload, JSON_UNESCAPED_UNICODE));
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
