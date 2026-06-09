<?php

class USK_SudoRunner
{
    public static function wrapper_path()
    {
        return USK_ROOT . '/bin/usk-run-root.sh';
    }

    /**
     * Path relative to panel root, e.g. bin/add-user-wireguard.sh
     *
     * @param string $rel
     * @param array<int,string> $args
     */
    public static function cmd_rel($rel, array $args = array())
    {
        $rel = str_replace('\\', '/', ltrim((string) $rel, '/'));
        $parts = array(escapeshellarg($rel));
        foreach ($args as $arg) {
            $parts[] = escapeshellarg((string) $arg);
        }
        return 'sudo -n /bin/bash ' . escapeshellarg(self::wrapper_path()) . ' ' . implode(' ', $parts);
    }

    /**
     * @param string $absScript Absolute path under USK_ROOT
     * @param array<int,string> $args
     */
    public static function cmd_abs($absScript, array $args = array())
    {
        $root = rtrim(str_replace('\\', '/', USK_ROOT), '/');
        $absScript = str_replace('\\', '/', (string) $absScript);
        if (strpos($absScript, $root . '/') === 0) {
            $rel = substr($absScript, strlen($root) + 1);
        } else {
            $rel = 'bin/' . basename($absScript);
        }
        return self::cmd_rel($rel, $args);
    }

    /**
     * @param string $rel
     * @param array<int,string> $args
     */
    public static function cmd_nohup($rel, array $args = array())
    {
        return 'nohup ' . self::cmd_rel($rel, $args) . ' </dev/null >/dev/null 2>&1 &';
    }

    /** Quick test: can www-data sudo the wrapper? */
    public static function probe_ok()
    {
        if (!is_file(self::wrapper_path())) {
            return false;
        }
        $cmd = self::cmd_rel('bin/probe-protocol.sh', array('wireguard', USK_ROOT)) . ' 2>&1';
        $out = @shell_exec($cmd);
        return is_string($out) && strpos($out, 'USK_OK') !== false;
    }
}
