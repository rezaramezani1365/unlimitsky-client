<?php

class USK_ProtocolConnections
{
    public static function enforce_all()
    {
        $script = USK_ROOT . '/bin/enforce-connection-limits.sh';
        if (!is_file($script)) {
            return array('ok' => false, 'error' => 'script_missing', 'trimmed' => 0);
        }

        $cmd = 'sudo -n /bin/bash ' . escapeshellarg($script) . ' 2>&1';
        $raw = @shell_exec($cmd);
        if ($raw === null || trim($raw) === '') {
            return array('ok' => false, 'error' => 'empty_response', 'trimmed' => 0);
        }

        $data = json_decode(trim($raw), true);
        if (!is_array($data) && preg_match('/(\{.*\})/s', $raw, $m)) {
            $data = json_decode($m[1], true);
        }
        if (!is_array($data)) {
            return array('ok' => false, 'error' => 'invalid_response', 'trimmed' => 0, 'log' => substr($raw, 0, 500));
        }

        return $data;
    }

    public static function max_connections_for(array $rec)
    {
        $max = (int) ($rec['max_connections'] ?? ($rec['meta']['max_connections'] ?? 1));
        return max(1, min(99, $max));
    }

    public static function last_run_file()
    {
        return USK_ROOT . '/data/clients/connections-last-run.json';
    }

    public static function save_last_run(array $report)
    {
        $dir = USK_ROOT . '/data/clients';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $report['ran_at'] = date('c');
        file_put_contents(self::last_run_file(), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public static function get_last_run()
    {
        $f = self::last_run_file();
        if (!is_file($f)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($f), true);
        return is_array($data) ? $data : null;
    }
}
