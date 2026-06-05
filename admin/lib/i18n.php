<?php

class USK_I18n
{
    private static $lang = 'fa';
    private static $strings = array();
    private static $loaded = false;

    public static function boot($lang = null)
    {
        if ($lang !== null && in_array($lang, array('fa', 'en'), true)) {
            self::$lang = $lang;
        } elseif (!empty($_SESSION['usk_lang']) && in_array($_SESSION['usk_lang'], array('fa', 'en'), true)) {
            self::$lang = $_SESSION['usk_lang'];
        } elseif (isset($GLOBALS['sql']) && $GLOBALS['sql'] instanceof mysqli && !$GLOBALS['sql']->connect_error) {
            $tbl = $GLOBALS['sql']->query("SHOW TABLES LIKE 'panel_admin'");
            if ($tbl && $tbl->num_rows > 0) {
                $adm = $GLOBALS['sql']->query('SELECT `language` FROM `panel_admin` ORDER BY `id` ASC LIMIT 1');
                if ($adm && ($row = $adm->fetch_assoc()) && in_array($row['language'], array('fa', 'en'), true)) {
                    self::$lang = $row['language'];
                }
            }
        } elseif (file_exists(USK_ROOT . '/install/unlimitsky.install')) {
            $inst = json_decode(file_get_contents(USK_ROOT . '/install/unlimitsky.install'), true);
            if (!empty($inst['language'])) {
                self::$lang = $inst['language'];
            }
        }
        $_SESSION['usk_lang'] = self::$lang;
        self::load();
    }

    private static function load()
    {
        if (self::$loaded) {
            return;
        }
        $file = dirname(__DIR__) . '/lang/' . self::$lang . '.json';
        if (file_exists($file)) {
            self::$strings = json_decode(file_get_contents($file), true) ?: array();
        }
        self::$loaded = true;
    }

    public static function lang()
    {
        return self::$lang;
    }

    public static function is_rtl()
    {
        return self::$lang === 'fa';
    }

    public static function dir()
    {
        return self::is_rtl() ? 'rtl' : 'ltr';
    }

    public static function html_lang()
    {
        return self::$lang === 'fa' ? 'fa' : 'en';
    }

    public static function set_lang($lang)
    {
        if (!in_array($lang, array('fa', 'en'), true)) {
            return;
        }
        self::$lang = $lang;
        self::$loaded = false;
        $_SESSION['usk_lang'] = $lang;
        self::load();
        if (class_exists('USK_Admin_Auth', false)) {
            USK_Admin_Auth::update_account(null, null, $lang);
        }
    }

    public static function t($key, $default = '')
    {
        self::load();
        return isset(self::$strings[$key]) ? self::$strings[$key] : ($default !== '' ? $default : $key);
    }
}

function __($key, $default = '')
{
    return USK_I18n::t($key, $default);
}

function usk_lang()
{
    return USK_I18n::lang();
}

function usk_dir()
{
    return USK_I18n::dir();
}
