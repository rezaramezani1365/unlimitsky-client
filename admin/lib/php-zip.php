<?php

class USK_PhpZip
{
    const RUNNING_TIMEOUT_SEC = 300;

    public static function available()
    {
        return class_exists('ZipArchive');
    }

    public static function available_cli()
    {
        if (self::available()) {
            return true;
        }
        $bins = array('php');
        foreach (glob('/usr/bin/php[0-9]*') ?: array() as $bin) {
            if (is_executable($bin)) {
                $bins[] = $bin;
            }
        }
        $bins = array_unique($bins);
        foreach ($bins as $bin) {
            $cmd = escapeshellarg($bin) . ' -r ' . escapeshellarg('exit(class_exists("ZipArchive") ? 0 : 1);') . ' 2>/dev/null';
            exec($cmd, $out, $code);
            if ($code === 0) {
                return true;
            }
        }
        return false;
    }

    public static function install_script()
    {
        return USK_ROOT . '/bin/install-php-zip.sh';
    }

    public static function status_file()
    {
        return USK_ROOT . '/data/settings/php-zip-install.json';
    }

    public static function log_file()
    {
        return USK_ROOT . '/data/settings/php-zip-install.log';
    }

    public static function lock_file()
    {
        return USK_ROOT . '/data/settings/php-zip-install.lock';
    }

    public static function can_install_from_web()
    {
        return is_file(self::install_script());
    }

    public static function get_status()
    {
        self::poll_install_status();
        return self::get_status_raw();
    }

    public static function running_age_sec()
    {
        $s = self::get_status_raw();
        if (($s['state'] ?? '') !== 'running') {
            return 0;
        }
        $at = strtotime($s['at'] ?? '');
        return $at ? max(0, time() - $at) : 0;
    }

    public static function is_install_running()
    {
        self::poll_install_status();
        $s = self::get_status_raw();
        if (($s['state'] ?? '') !== 'running') {
            return false;
        }
        return self::running_age_sec() < self::RUNNING_TIMEOUT_SEC;
    }

    public static function is_install_stale()
    {
        $s = self::get_status_raw();
        if (($s['state'] ?? '') !== 'running') {
            return false;
        }
        return self::running_age_sec() >= self::RUNNING_TIMEOUT_SEC;
    }

    public static function write_status($state, $message = '')
    {
        $dir = dirname(self::status_file());
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $payload = array(
            'state' => (string) $state,
            'message' => (string) $message,
            'at' => date('c'),
        );
        @file_put_contents(self::status_file(), json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public static function cancel_install()
    {
        self::write_status('failed', 'cancelled_by_user');
        @unlink(self::lock_file());
    }

    /** @return array{php_version:string,zip_packages:array,fpm_packages:array} */
    public static function diagnostics()
    {
        $phpVer = trim((string) shell_exec('php -v 2>/dev/null | head -1'));
        $zipPkgs = array();
        $fpmPkgs = array();
        exec('apt-cache search --names-only ' . escapeshellarg('^php[0-9.]+-zip$') . ' 2>/dev/null | awk \'{print $1}\'', $zipPkgs);
        exec('dpkg -l 2>/dev/null | awk \'/^ii/ && /php/ && /-(fpm|cli)$/ {print $2}\'', $fpmPkgs);
        return array(
            'php_version' => $phpVer !== '' ? $phpVer : '?',
            'zip_packages' => array_values(array_filter($zipPkgs)),
            'fpm_packages' => array_values(array_filter($fpmPkgs)),
        );
    }

    public static function suggested_zip_package()
    {
        $diag = self::diagnostics();
        if (!empty($diag['zip_packages'])) {
            foreach ($diag['fpm_packages'] as $fpm) {
                if (preg_match('/^php([0-9.]+)-fpm$/', $fpm, $m)) {
                    $want = 'php' . $m[1] . '-zip';
                    if (in_array($want, $diag['zip_packages'], true)) {
                        return $want;
                    }
                }
            }
            return $diag['zip_packages'][0];
        }
        foreach ($diag['fpm_packages'] as $fpm) {
            if (preg_match('/^php([0-9.]+)-fpm$/', $fpm, $m)) {
                return 'php' . $m[1] . '-zip';
            }
        }
        return 'php-zip';
    }

    public static function poll_install_status()
    {
        if (self::available_cli()) {
            $s = self::get_status_raw();
            if (($s['state'] ?? '') === 'running') {
                self::write_status('ok', 'installed');
            }
            return array('state' => 'ok');
        }

        if (self::is_install_stale()) {
            self::write_status('failed', 'stale_timeout');
            return array('state' => 'failed', 'message' => 'stale_timeout');
        }

        $log = self::log_file();
        if (is_file($log)) {
            $tail = (string) file_get_contents($log);
            if (strpos($tail, 'USK_OK') !== false) {
                self::write_status('ok', 'installed');
                return array('state' => 'ok');
            }
            if (strpos($tail, 'USK_ERR') !== false) {
                self::write_status('failed', substr($tail, -400));
                return array('state' => 'failed', 'message' => substr($tail, -400));
            }
        }

        return self::get_status_raw();
    }

    private static function get_status_raw()
    {
        $f = self::status_file();
        if (!is_file($f)) {
            return array('state' => 'idle');
        }
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? $d : array('state' => 'idle');
    }

    public static function start_install_async()
    {
        if (self::available_cli()) {
            return array('ok' => true, 'async' => false, 'msg' => 'already_installed');
        }

        if (self::is_install_running()) {
            return array('ok' => true, 'async' => true, 'msg' => 'already_running');
        }

        if (self::is_install_stale()) {
            self::cancel_install();
        }

        $script = self::install_script();
        if (!is_file($script)) {
            return array('ok' => false, 'output' => 'install-php-zip.sh missing');
        }

        $dir = dirname(self::status_file());
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(self::log_file(), "=== started " . date('c') . " ===\n", FILE_APPEND);

        self::write_status('running', 'started');

        $inner = 'bash ' . escapeshellarg($script) . ' ' . escapeshellarg(USK_ROOT);
        $cmd = 'nohup sudo -n ' . $inner . ' </dev/null >/dev/null 2>&1 &';
        shell_exec($cmd);

        return array('ok' => true, 'async' => true, 'msg' => 'install_started');
    }

    /** @deprecated use start_install_async */
    public static function install()
    {
        return self::start_install_async();
    }
}
