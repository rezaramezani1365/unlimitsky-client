<?php

class USK_PhpZip
{
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

    public static function can_install_from_web()
    {
        return is_file(self::install_script());
    }

    public static function install()
    {
        if (self::available_cli()) {
            return array('ok' => true, 'output' => 'already_installed');
        }

        $script = self::install_script();
        if (!is_file($script)) {
            return array('ok' => false, 'output' => 'install-php-zip.sh missing');
        }

        $cmd = 'sudo -n bash ' . escapeshellarg($script) . ' ' . escapeshellarg(USK_ROOT) . ' 2>&1';
        $output = (string) shell_exec($cmd);
        $ok = strpos($output, 'USK_OK') !== false || self::available_cli();

        return array('ok' => $ok, 'output' => trim($output));
    }
}
