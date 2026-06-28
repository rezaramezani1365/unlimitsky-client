<?php

class USK_SchemaUpgrade
{
    public static function run()
    {
        global $sql;
        if (!$sql instanceof mysqli || $sql->connect_error) {
            return;
        }

        $cols = $sql->query("SHOW COLUMNS FROM `category` LIKE 'connections'");
        if ($cols && $cols->num_rows === 0) {
            @$sql->query(
                "ALTER TABLE `category` ADD COLUMN `connections` varchar(10) COLLATE utf8mb4_bin NOT NULL DEFAULT '1' AFTER `status`"
            );
        }
    }
}
