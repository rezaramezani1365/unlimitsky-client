<?php

defined('ABSPATH') || exit;

class USK_Db_Migrate
{
    public static function maybe_upgrade(): void
    {
        $current = get_option('USK_WC_db_version', '0');
        if (version_compare($current, USK_WC_VERSION, '>=')) {
            return;
        }

        USK_Activator::activate();
        update_option('USK_WC_db_version', USK_WC_VERSION);

        if (class_exists('USK_Subscription_Proxy')) {
            USK_Subscription_Proxy::flush_rules();
        }
    }
}
