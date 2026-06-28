<?php



class USK_Admin_Auth

{

    private static $booted = false;

    private static $max_attempts = 5;

    private static $lockout_seconds = 900;



    public static function boot()

    {

        if (self::$booted) {

            return;

        }

        if (session_status() === PHP_SESSION_NONE) {

            session_start();

        }

        self::$booted = true;

        self::migrate_legacy_auth();

    }



    private static function sql()

    {

        global $sql;

        if (!($sql instanceof mysqli) || $sql->connect_error) {

            return null;

        }

        return $sql;

    }



    private static function admin_id()

    {

        $db = self::sql();

        if (!$db) {

            return 0;

        }

        $row = $db->query('SELECT `id` FROM `panel_admin` ORDER BY `id` ASC LIMIT 1');

        if (!$row || $row->num_rows === 0) {

            return 0;

        }

        return (int) $row->fetch_assoc()['id'];

    }



    private static function ensure_panel_admin_table()
    {
        $db = self::sql();
        if (!$db) {
            return;
        }
        $db->query("CREATE TABLE IF NOT EXISTS `panel_admin` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `username` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
            `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
            `must_change` tinyint(1) NOT NULL DEFAULT 1,
            `language` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fa',
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function table_ready()
    {
        $db = self::sql();
        if (!$db) {
            return false;
        }
        self::ensure_panel_admin_table();
        $r = $db->query("SHOW TABLES LIKE 'panel_admin'");
        return $r && $r->num_rows > 0;
    }



    private static function migrate_legacy_auth()

    {

        if (!self::table_ready()) {

            return;

        }

        $db = self::sql();

        $count = $db->query('SELECT COUNT(*) AS c FROM `panel_admin`');

        if (!$count || (int) $count->fetch_assoc()['c'] > 0) {

            return;

        }



        $auth_file = dirname(__DIR__) . '/data/auth.json';

        if (!file_exists($auth_file)) {

            return;

        }



        $data = json_decode(file_get_contents($auth_file), true);

        if (!is_array($data) || empty($data['username']) || empty($data['password'])) {

            return;

        }



        $user = $db->real_escape_string($data['username']);

        $hash = $db->real_escape_string($data['password']);

        $must = !empty($data['must_change']) ? 1 : 0;

        $lang = in_array($data['language'] ?? '', array('fa', 'en'), true) ? $data['language'] : 'fa';

        $db->query("INSERT INTO `panel_admin` (`username`, `password_hash`, `must_change`, `language`) VALUES ('{$user}', '{$hash}', {$must}, '{$lang}')");

        @rename($auth_file, $auth_file . '.migrated.bak');

    }



    public static function get_data()

    {

        self::boot();

        if (!self::table_ready()) {

            return array();

        }

        $row = self::sql()->query('SELECT `username`, `password_hash` AS `password`, `must_change`, `language` FROM `panel_admin` ORDER BY `id` ASC LIMIT 1');

        if (!$row || $row->num_rows === 0) {

            return array();

        }

        $data = $row->fetch_assoc();

        $data['must_change'] = !empty($data['must_change']);

        return $data;

    }



    public static function check()

    {

        self::boot();

        return !empty($_SESSION['usk_admin_logged']);

    }



    public static function require_login()

    {

        if (!self::check()) {

            header('Location: ' . usk_admin_base() . '/login.php');

            exit;

        }

    }



    private static function is_locked_out()

    {

        self::boot();

        return (int) ($_SESSION['usk_login_lock_until'] ?? 0) > time();

    }



    private static function register_failed_attempt()

    {

        self::boot();

        $_SESSION['usk_login_attempts'] = (int) ($_SESSION['usk_login_attempts'] ?? 0) + 1;

        if ($_SESSION['usk_login_attempts'] >= self::$max_attempts) {

            $_SESSION['usk_login_lock_until'] = time() + self::$lockout_seconds;

            $_SESSION['usk_login_attempts'] = 0;

        }

    }



    private static function clear_failed_attempts()

    {

        unset($_SESSION['usk_login_attempts'], $_SESSION['usk_login_lock_until']);

    }



    public static function login($user, $pass)

    {

        self::boot();

        if (self::is_locked_out() || !self::table_ready()) {

            return false;

        }



        $data = self::get_data();

        if ($user !== ($data['username'] ?? '') || !password_verify($pass, $data['password'] ?? '')) {

            self::register_failed_attempt();

            return false;

        }



        self::clear_failed_attempts();

        $_SESSION['usk_admin_logged'] = true;

        $_SESSION['usk_admin_user'] = $user;

        if (!empty($data['language'])) {

            $_SESSION['usk_lang'] = $data['language'];

        }

        return true;

    }



    public static function logout()

    {

        self::boot();

        unset($_SESSION['usk_admin_logged'], $_SESSION['usk_admin_user']);

    }



    public static function current_user()

    {

        return $_SESSION['usk_admin_user'] ?? 'admin';

    }



    public static function change_password($new_pass)

    {

        self::boot();

        $id = self::admin_id();

        if ($id <= 0) {

            return;

        }

        $hash = self::sql()->real_escape_string(password_hash($new_pass, PASSWORD_DEFAULT));

        self::sql()->query("UPDATE `panel_admin` SET `password_hash` = '{$hash}', `must_change` = 0 WHERE `id` = {$id}");

    }



    public static function update_account($username, $password = null, $language = null)

    {

        self::boot();

        $id = self::admin_id();

        if ($id <= 0) {

            return;

        }



        $db = self::sql();

        $sets = array();



        if ($username !== null && $username !== '') {

            $sets[] = "`username` = '" . $db->real_escape_string($username) . "'";

        }

        if ($password !== null && $password !== '') {

            $sets[] = "`password_hash` = '" . $db->real_escape_string(password_hash($password, PASSWORD_DEFAULT)) . "'";

            $sets[] = '`must_change` = 0';

        }

        if ($language !== null && in_array($language, array('fa', 'en'), true)) {

            $sets[] = "`language` = '" . $db->real_escape_string($language) . "'";

            $_SESSION['usk_lang'] = $language;

        }



        if (empty($sets)) {

            return;

        }



        $db->query('UPDATE `panel_admin` SET ' . implode(', ', $sets) . " WHERE `id` = {$id}");



        if ($username !== null && $username !== '') {

            $_SESSION['usk_admin_user'] = $username;

        }

    }



    public static function must_change_password()

    {

        self::boot();

        $data = self::get_data();

        return !empty($data['must_change']);

    }



    public static function verify_password($password)

    {

        self::boot();

        $data = self::get_data();

        if ($password === '' || empty($data['password'])) {

            return false;

        }

        return password_verify($password, $data['password']);

    }



    public static function create_from_install($username, $password, $language = 'fa', $must_change = false)

    {

        self::boot();

        if (!self::table_ready()) {

            return false;

        }



        $db = self::sql();

        $db->query('DELETE FROM `panel_admin`');

        $user = $db->real_escape_string($username);

        $hash = $db->real_escape_string(password_hash($password, PASSWORD_DEFAULT));

        $must = $must_change ? 1 : 0;

        $lang = in_array($language, array('fa', 'en'), true) ? $language : 'fa';



        return $db->query("INSERT INTO `panel_admin` (`username`, `password_hash`, `must_change`, `language`) VALUES ('{$user}', '{$hash}', {$must}, '{$lang}')");

    }



    public static function lockout_remaining_seconds()

    {

        self::boot();

        $until = (int) ($_SESSION['usk_login_lock_until'] ?? 0);

        return max(0, $until - time());

    }

}


