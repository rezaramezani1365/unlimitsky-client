<?php

if (PHP_SAPI !== 'cli' && !defined('USK_INSTALL')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(array('status' => false, 'msg' => 'Forbidden', 'status_code' => 403)));
}

if (!isset($_GET['db_username'], $_GET['db_password'], $_GET['db_name'])) {
    die(json_encode(array('status' => false, 'msg' => 'Missing database parameters', 'error_code' => 401)));
}

function usk_schema_query($mysqli, $ddl)
{
    if (!$mysqli->query($ddl)) {
        throw new RuntimeException($mysqli->error ?: 'Schema query failed');
    }
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $sql = new mysqli('localhost', $_GET['db_username'], $_GET['db_password'], $_GET['db_name']);
    $sql->set_charset('utf8mb4');

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `users` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `from_id` bigint(13) NOT NULL,
    `step` varchar(50) COLLATE utf8mb4_bin DEFAULT 'none',
    `coin` int DEFAULT 0,
    `count_service` int DEFAULT 0,
    `count_charge` int DEFAULT 0,
    `phone` varchar(20) COLLATE utf8mb4_bin DEFAULT NULL,
    `test_account` varchar(10) COLLATE utf8mb4_bin DEFAULT 'no',
    `count_warn` varchar(10) COLLATE utf8mb4_bin DEFAULT '0',
    `view_status` varchar(20) COLLATE utf8mb4_bin DEFAULT 'active',
    `timestamp` varchar(50) COLLATE utf8mb4_bin DEFAULT '0',
    `status` varchar(15) COLLATE utf8mb4_bin DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `panels` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `name` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `login_link` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `username` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `password` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `count_create` varchar(50) COLLATE utf8mb4_bin DEFAULT '0',
    `qr_code` varchar(30) COLLATE utf8mb4_bin DEFAULT 'active',
    `protocols` varchar(50) COLLATE utf8mb4_bin DEFAULT 'vless|',
    `flow` varchar(15) COLLATE utf8mb4_bin DEFAULT 'flowon',
    `code` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `token` varchar(500) COLLATE utf8mb4_bin NOT NULL,
    `type` varchar(30) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(20) COLLATE utf8mb4_bin DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `hiddify_panels` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `name` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `login_link` TEXT COLLATE utf8mb4_bin NOT NULL,
    `token` TEXT COLLATE utf8mb4_bin NOT NULL,
    `count_create` varchar(50) COLLATE utf8mb4_bin DEFAULT '0',
    `qr_code` varchar(30) COLLATE utf8mb4_bin DEFAULT 'active',
    `code` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `type` varchar(30) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(20) COLLATE utf8mb4_bin DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `sanayi_panel_setting` (
    `code` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL,
    `inbound_id` varchar(10) COLLATE utf8mb4_bin DEFAULT NULL,
    `example_link` TEXT COLLATE utf8mb4_bin DEFAULT NULL,
    `flow` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `marzban_inbounds` (
    `panel` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL,
    `inbound` TEXT COLLATE utf8mb4_bin DEFAULT NULL,
    `code` varchar(20) COLLATE utf8mb4_bin DEFAULT NULL,
    `status` varchar(20) COLLATE utf8mb4_bin DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `orders` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `from_id` varchar(128) COLLATE utf8mb4_bin NOT NULL,
    `location` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `protocol` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL,
    `date` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `volume` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `link` TEXT COLLATE utf8mb4_bin NOT NULL,
    `price` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `code` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(15) COLLATE utf8mb4_bin NOT NULL,
    `type` varchar(20) COLLATE utf8mb4_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `factors` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `from_id` varchar(128) COLLATE utf8mb4_bin NOT NULL,
    `price` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `code` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(15) COLLATE utf8mb4_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `sends` (
    `send` varchar(50) PRIMARY KEY,
    `step` varchar(50) DEFAULT NULL,
    `user` INT(11) DEFAULT NULL,
    `type` varchar(50) DEFAULT NULL,
    `text` varchar(7000) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `category` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `limit` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `date` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `name` varchar(100) COLLATE utf8mb4_bin NOT NULL,
    `price` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `code` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(15) COLLATE utf8mb4_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `category_limit` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `limit` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `name` varchar(100) COLLATE utf8mb4_bin NOT NULL,
    `price` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `code` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(15) COLLATE utf8mb4_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `category_date` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `date` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `name` varchar(100) COLLATE utf8mb4_bin NOT NULL,
    `price` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `code` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(15) COLLATE utf8mb4_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `service_factors` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `from_id` varchar(128) COLLATE utf8mb4_bin NOT NULL,
    `location` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `protocol` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `plan` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `price` varchar(200) COLLATE utf8mb4_bin NOT NULL,
    `code` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(15) COLLATE utf8mb4_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `lock` (
    `id` int(11) AUTO_INCREMENT PRIMARY KEY,
    `chat_id` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL,
    `name` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `payment_setting` (
    `zarinpal_token` varchar(200) COLLATE utf8mb4_bin DEFAULT 'none',
    `idpay_token` varchar(200) COLLATE utf8mb4_bin DEFAULT 'none',
    `nowpayment_token` varchar(200) COLLATE utf8mb4_bin DEFAULT 'none',
    `card_number` varchar(20) COLLATE utf8mb4_bin DEFAULT 'none',
    `card_number_name` varchar(100) COLLATE utf8mb4_bin DEFAULT 'none',
    `zarinpal_status` varchar(15) COLLATE utf8mb4_bin DEFAULT 'inactive',
    `idpay_status` varchar(15) COLLATE utf8mb4_bin DEFAULT 'inactive',
    `nowpayment_status` varchar(15) COLLATE utf8mb4_bin DEFAULT 'inactive',
    `card_status` varchar(15) COLLATE utf8mb4_bin DEFAULT 'inactive'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `test_account_setting` (
    `panel` varchar(20) COLLATE utf8mb4_bin DEFAULT 'none',
    `volume` varchar(20) COLLATE utf8mb4_bin DEFAULT '0',
    `time` varchar(20) COLLATE utf8mb4_bin DEFAULT '0',
    `status` varchar(50) COLLATE utf8mb4_bin DEFAULT 'inactive'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `test_account` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `from_id` varchar(128) COLLATE utf8mb4_bin NOT NULL,
    `location` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `date` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `volume` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `link` TEXT COLLATE utf8mb4_bin NOT NULL,
    `price` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `code` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(15) COLLATE utf8mb4_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `admins` (
    `chat_id` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `settings` (
    `log_channel` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL,
    `count_warn_ban` varchar(50) COLLATE utf8mb4_bin DEFAULT '3'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `spam_setting` (
    `type` varchar(20) COLLATE utf8mb4_bin DEFAULT 'ban',
    `time` varchar(20) COLLATE utf8mb4_bin DEFAULT '3',
    `count_message` varchar(20) COLLATE utf8mb4_bin DEFAULT '10',
    `status` varchar(50) COLLATE utf8mb4_bin DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `copens` (
    `copen` varchar(100) COLLATE utf8mb4_bin NOT NULL,
    `percent` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `count_use` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(50) COLLATE utf8mb4_bin DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `notes` (
    `note` TEXT COLLATE utf8mb4_bin NOT NULL,
    `code` varchar(30) COLLATE utf8mb4_bin NOT NULL,
    `type` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(20) COLLATE utf8mb4_bin DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `auth_setting` (
    `iran_number` varchar(15) COLLATE utf8mb4_bin DEFAULT NULL,
    `virtual_number` varchar(15) COLLATE utf8mb4_bin DEFAULT NULL,
    `both_number` varchar(15) COLLATE utf8mb4_bin DEFAULT NULL,
    `status` varchar(15) COLLATE utf8mb4_bin DEFAULT 'inactive'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");

    usk_schema_query($sql, "CREATE TABLE IF NOT EXISTS `panel_admin` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `username` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
    `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `must_change` tinyint(1) NOT NULL DEFAULT 1,
    `language` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fa',
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $seed_checks = array(
        array("SELECT 1 FROM `auth_setting` LIMIT 1", "INSERT INTO `auth_setting` (`iran_number`, `virtual_number`, `both_number`) VALUES ('inactive', 'inactive', 'inactive')"),
        array("SELECT 1 FROM `settings` LIMIT 1", "INSERT INTO `settings` (`count_warn_ban`) VALUES ('3')"),
        array("SELECT 1 FROM `spam_setting` LIMIT 1", "INSERT INTO `spam_setting` (`type`) VALUES ('ban')"),
        array("SELECT 1 FROM `test_account_setting` LIMIT 1", "INSERT INTO `test_account_setting` (`panel`) VALUES ('none')"),
        array("SELECT 1 FROM `sends` WHERE `send` = 'no' LIMIT 1", "INSERT INTO `sends` (`send`) VALUES ('no')"),
        array("SELECT 1 FROM `payment_setting` LIMIT 1", "INSERT INTO `payment_setting` (`zarinpal_token`, `idpay_token`, `nowpayment_token`) VALUES ('none', 'none', 'none')"),
    );
    foreach ($seed_checks as $seed) {
        $check = $sql->query($seed[0]);
        if ($check && $check->num_rows === 0) {
            usk_schema_query($sql, $seed[1]);
        }
    }

    // Upgrade legacy installs (from_id was varchar(20) — too short for native VPN usernames)
    $migrations = array(
        "ALTER TABLE `orders` MODIFY `from_id` varchar(128) COLLATE utf8mb4_bin NOT NULL",
        "ALTER TABLE `factors` MODIFY `from_id` varchar(128) COLLATE utf8mb4_bin NOT NULL",
        "ALTER TABLE `service_factors` MODIFY `from_id` varchar(128) COLLATE utf8mb4_bin NOT NULL",
        "ALTER TABLE `test_account` MODIFY `from_id` varchar(128) COLLATE utf8mb4_bin NOT NULL",
    );
    foreach ($migrations as $migration) {
        @$sql->query($migration);
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    echo json_encode(array('status' => true, 'msg' => 'Database setup completed successfully.', 'status_code' => 200));
} catch (Throwable $e) {
    mysqli_report(MYSQLI_REPORT_OFF);
    echo json_encode(array('status' => false, 'msg' => $e->getMessage(), 'status_code' => 500));
}
