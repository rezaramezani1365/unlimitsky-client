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
}
