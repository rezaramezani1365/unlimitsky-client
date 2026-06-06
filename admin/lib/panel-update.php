<?php

class USK_Panel_Update
{
    const INSTALL_DIR = '/opt/unlimitsky';
    const RUNNING_TIMEOUT_SEC = 900;

    public static function webRoot()
    {
        return USK_ROOT;
    }

    public static function localDeployRev()
    {
        $f = USK_ROOT . '/admin/data/.deploy-rev';
        if (!is_file($f)) {
            return null;
        }
        $rev = trim((string) file_get_contents($f));
        return $rev !== '' ? $rev : null;
    }

    public static function gitHeadRev($dir = null)
    {
        $dir = $dir ?: self::INSTALL_DIR;
        if (!is_dir($dir . '/.git')) {
            return null;
        }
        $cmd = 'git -C ' . escapeshellarg($dir) . ' rev-parse HEAD 2>/dev/null';
        $out = trim((string) shell_exec($cmd));
        return $out !== '' ? $out : null;
    }

    public static function gitHeadShort($dir = null)
    {
        $full = self::gitHeadRev($dir);
        return $full ? substr($full, 0, 12) : null;
    }

    public static function repoUrl()
    {
        return usk_github_repo_url();
    }

    /** True when expected v3 panel features are missing (not orphan files on disk). */
    public static function isLegacyPanel()
    {
        foreach (self::featureChecks() as $ok) {
            if (!$ok) {
                return true;
            }
        }
        return false;
    }

    public static function featureChecks()
    {
        $nav = function_exists('usk_admin_nav') ? usk_admin_nav() : array();
        $removed = function_exists('usk_admin_removed_pages') ? usk_admin_removed_pages() : array();

        return array(
            __('update_feat_backup') => is_file(USK_ROOT . '/admin/lib/backup.php'),
            __('update_feat_backup_page') => is_file(USK_ROOT . '/admin/pages/backup.php'),
            __('update_feat_updates') => is_file(USK_ROOT . '/admin/pages/updates.php'),
            __('update_feat_coupons_nav') => !isset($nav['coupons']) && in_array('coupons', $removed, true),
        );
    }

    /** Git deploy stamp differs from /opt/unlimitsky HEAD (12-char prefix compare). */
    public static function isDeployOutdated()
    {
        $localRev = self::localDeployRev();
        $gitRev = self::gitHeadShort();
        if (!$localRev || !$gitRev) {
            return false;
        }
        return substr($localRev, 0, 12) !== substr($gitRev, 0, 12);
    }

    public static function updateScriptPath()
    {
        $local = USK_ROOT . '/scripts/panel-self-update.sh';
        if (is_file($local)) {
            return $local;
        }
        $fallback = self::INSTALL_DIR . '/scripts/panel-self-update.sh';
        return is_file($fallback) ? $fallback : null;
    }

    public static function workerScriptPath()
    {
        $local = USK_ROOT . '/bin/run-panel-update.sh';
        return is_file($local) ? $local : null;
    }

    public static function status_file()
    {
        return USK_ROOT . '/data/settings/panel-update.json';
    }

    public static function log_file()
    {
        return USK_ROOT . '/data/settings/panel-update.log';
    }

    public static function lock_file()
    {
        return USK_ROOT . '/data/settings/panel-update.lock';
    }

    public static function canRunFromWeb()
    {
        return self::updateScriptPath() !== null && self::workerScriptPath() !== null;
    }

    public static function write_status($state, $message = '')
    {
        $dir = dirname(self::status_file());
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(self::status_file(), json_encode(array(
            'state' => (string) $state,
            'message' => (string) $message,
            'at' => date('c'),
        ), JSON_UNESCAPED_UNICODE));
    }

    public static function get_status_raw()
    {
        $f = self::status_file();
        if (!is_file($f)) {
            return array('state' => 'idle');
        }
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? $d : array('state' => 'idle');
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

    public static function is_update_running()
    {
        self::poll_update_status();
        $s = self::get_status_raw();
        if (($s['state'] ?? '') !== 'running') {
            return false;
        }
        return self::running_age_sec() < self::RUNNING_TIMEOUT_SEC;
    }

    public static function poll_update_status()
    {
        $s = self::get_status_raw();
        if (($s['state'] ?? '') !== 'running') {
            return $s;
        }

        if (self::running_age_sec() >= self::RUNNING_TIMEOUT_SEC) {
            self::write_status('failed', 'stale_timeout');
            return self::get_status_raw();
        }

        $log = self::log_file();
        if (!is_file($log)) {
            return $s;
        }

        $tail = (string) file_get_contents($log);
        if (strpos($tail, 'USK_OK') !== false) {
            self::write_status('ok', 'complete');
            @unlink(self::lock_file());
            return self::get_status_raw();
        }
        if (strpos($tail, 'USK_ERR') !== false) {
            self::write_status('failed', substr($tail, -500));
            @unlink(self::lock_file());
            return self::get_status_raw();
        }

        return $s;
    }

    public static function sshUpdateCommand()
    {
        $web = self::webRoot();
        return 'sudo bash ' . self::INSTALL_DIR . '/scripts/panel-self-update.sh ' . $web;
    }

    public static function curlUpdateCommand()
    {
        return 'curl -fsSL https://raw.githubusercontent.com/rezaramezani1365/unlimitsky-client/main/scripts/install.sh | sudo bash -s -- --port 8082 --open-firewall';
    }

    /**
     * Run update in background so php-fpm restart does not 502 the current request.
     *
     * @return array{ok:bool,async?:bool,msg?:string,output?:string,code?:int}
     */
    public static function start_update_async()
    {
        if (self::is_update_running()) {
            return array('ok' => true, 'async' => true, 'msg' => 'already_running');
        }

        $script = self::updateScriptPath();
        $worker = self::workerScriptPath();
        if ($script === null || $worker === null) {
            return array('ok' => false, 'output' => 'Update script not found.', 'code' => 127);
        }

        $dir = dirname(self::status_file());
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents(self::log_file(), "=== queued " . date('c') . " ===\n");
        self::write_status('running', 'started');

        $webRoot = self::webRoot();
        $inner = 'bash ' . escapeshellarg($worker)
            . ' ' . escapeshellarg($webRoot)
            . ' ' . escapeshellarg($script)
            . ' ' . escapeshellarg(self::log_file());
        $cmd = 'nohup sudo -n ' . $inner . ' </dev/null >/dev/null 2>&1 &';
        shell_exec($cmd);

        return array('ok' => true, 'async' => true, 'msg' => 'update_started');
    }

    /** @deprecated synchronous — causes 502 when php-fpm restarts */
    public static function runUpdate()
    {
        return self::start_update_async();
    }
}
