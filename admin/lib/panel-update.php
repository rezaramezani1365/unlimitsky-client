<?php

class USK_Panel_Update
{
    const INSTALL_DIR = '/opt/unlimitsky';

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

    public static function isLegacyPanel()
    {
        return is_file(USK_ROOT . '/admin/pages/coupons.php')
            || !is_file(USK_ROOT . '/admin/lib/backup.php')
            || !is_file(USK_ROOT . '/admin/pages/updates.php');
    }

    public static function featureChecks()
    {
        return array(
            'backup module' => is_file(USK_ROOT . '/admin/lib/backup.php'),
            'backup page'   => is_file(USK_ROOT . '/admin/pages/backup.php'),
            'updates page'  => is_file(USK_ROOT . '/admin/pages/updates.php'),
            'coupons removed' => !is_file(USK_ROOT . '/admin/pages/coupons.php'),
        );
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

    public static function canRunFromWeb()
    {
        return self::updateScriptPath() !== null;
    }

    public static function runUpdate()
    {
        $script = self::updateScriptPath();
        if ($script === null) {
            return array('ok' => false, 'output' => 'Update script not found.', 'code' => 127);
        }

        $webRoot = self::webRoot();
        $cmd = 'sudo /bin/bash ' . escapeshellarg($script) . ' ' . escapeshellarg($webRoot) . ' 2>&1';
        $output = array();
        $code = 0;
        exec($cmd, $output, $code);

        return array(
            'ok' => ($code === 0),
            'output' => implode("\n", $output),
            'code' => $code,
        );
    }
}
