<?php

class USK_NodeSsh
{
    public static function sshpass_available()
    {
        $which = trim((string) @shell_exec('command -v sshpass 2>/dev/null'));
        return $which !== '';
    }

    public static function test_connection($host, $port, $user, $password)
    {
        $host = trim((string) $host);
        $user = trim((string) $user);
        $password = (string) $password;
        $port = (int) $port;
        if ($host === '' || $user === '' || $password === '') {
            return array('ok' => false, 'error' => 'missing_ssh_fields');
        }
        if (!self::sshpass_available()) {
            return array('ok' => false, 'error' => 'sshpass_missing', 'detail' => 'On hub: apt install sshpass');
        }

        $remote = "echo USK_SSH_OK";
        $cmd = self::build_ssh_cmd($host, $port, $user, $password, $remote, 25);
        $out = @shell_exec($cmd);
        if ($out !== null && strpos($out, 'USK_SSH_OK') !== false) {
            return array('ok' => true);
        }
        return array(
            'ok' => false,
            'error' => 'ssh_connect_failed',
            'detail' => trim(substr((string) $out, -400)),
        );
    }

    public static function run(array $node, $remoteCommand, $timeout = 120)
    {
        require_once __DIR__ . '/nodes.php';
        $cred = USK_Nodes::ssh_credentials($node);
        if ($cred['host'] === '' || $cred['user'] === '' || $cred['password'] === '') {
            return array('ok' => false, 'error' => 'node_credentials_missing');
        }
        if (!self::sshpass_available()) {
            return array('ok' => false, 'error' => 'sshpass_missing');
        }

        $remoteCommand = trim((string) $remoteCommand);
        if ($remoteCommand === '') {
            return array('ok' => false, 'error' => 'empty_command');
        }

        $wrapped = '(' . $remoteCommand . '); echo USK_EXIT:$?';
        $cmd = self::build_ssh_cmd(
            $cred['host'],
            $cred['port'],
            $cred['user'],
            $cred['password'],
            $wrapped,
            max(15, (int) $timeout)
        );
        $out = @shell_exec($cmd);
        $text = $out !== null ? (string) $out : '';
        $exitCode = self::parse_remote_exit($text);

        if (strpos($text, 'USK_ERR:') !== false) {
            return array('ok' => false, 'error' => 'remote_error', 'log' => $text, 'exit_code' => $exitCode);
        }

        if ($exitCode !== 0) {
            return array('ok' => false, 'error' => 'remote_exit_' . $exitCode, 'log' => $text, 'exit_code' => $exitCode);
        }

        return array('ok' => true, 'log' => $text, 'exit_code' => 0);
    }

    /** Strip USK_EXIT:N trailer appended to every remote command. */
    private static function parse_remote_exit(&$text)
    {
        $text = (string) $text;
        $exitCode = 0;
        if (preg_match('/USK_EXIT:(\d+)\s*$/', $text, $m)) {
            $exitCode = (int) $m[1];
            $text = preg_replace('/\n?USK_EXIT:\d+\s*$/', '', $text);
        }
        return $exitCode;
    }

    public static function run_script(array $node, $scriptName, array $args = array(), $timeout = 180)
    {
        $root = rtrim((string) ($node['remote_root'] ?? '/opt/unlimitsky-node'), '/');
        $script = $root . '/bin/' . basename((string) $scriptName);
        $parts = array('sudo', '-n', '/bin/bash', escapeshellarg($script));
        foreach ($args as $arg) {
            $parts[] = escapeshellarg((string) $arg);
        }
        $remote = implode(' ', $parts) . ' 2>&1';
        return self::run($node, $remote, $timeout);
    }

    private static function build_ssh_cmd($host, $port, $user, $password, $remoteCommand, $timeout)
    {
        $host = escapeshellarg($host);
        $user = escapeshellarg($user);
        $passFile = tempnam(sys_get_temp_dir(), 'usksshp');
        if ($passFile === false) {
            return '';
        }
        file_put_contents($passFile, (string) $password);
        @chmod($passFile, 0600);

        $sshOpts = '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=15';
        $remoteEsc = escapeshellarg($remoteCommand);
        $cmd = sprintf(
            'timeout %d sshpass -f %s ssh %s -p %d %s@%s %s 2>&1',
            (int) $timeout,
            escapeshellarg($passFile),
            $sshOpts,
            (int) $port,
            $user,
            $host,
            $remoteEsc
        );

        register_shutdown_function(function () use ($passFile) {
            @unlink($passFile);
        });

        return $cmd . '; rm -f ' . escapeshellarg($passFile);
    }
}
