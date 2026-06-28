<?php

/**
 * WooCommerce shop URL for customer self-service renewal links.
 */
class USK_WooCommerce_Shop
{
    private static function settings_file()
    {
        $dir = USK_ROOT . '/data/settings';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/woocommerce-shop.json';
    }

    public static function defaults()
    {
        return array(
            'enabled' => false,
            'shop_url' => '',
            'hint' => '',
            'updated_at' => null,
        );
    }

    public static function get()
    {
        $file = self::settings_file();
        if (!is_file($file)) {
            return self::defaults();
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return self::defaults();
        }
        return array_merge(self::defaults(), $data);
    }

    public static function save(array $input)
    {
        $cfg = self::get();
        $cfg['enabled'] = !empty($input['enabled']);
        $cfg['shop_url'] = self::normalize_url((string) ($input['shop_url'] ?? ''));
        $cfg['hint'] = trim((string) ($input['hint'] ?? ''));
        $cfg['updated_at'] = date('c');
        file_put_contents(
            self::settings_file(),
            json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        return $cfg;
    }

    public static function normalize_url($url)
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (!in_array($scheme, array('http', 'https'), true)) {
            $scheme = 'https';
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : 0;
        $host = strtolower((string) $parts['host']);
        $base = $scheme . '://' . $host;
        if ($port > 0 && !in_array($port, array(80, 443), true)) {
            $base .= ':' . $port;
        }
        $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
        if ($path !== '' && $path !== '/') {
            $base .= $path;
        }
        return rtrim($base, '/');
    }

    public static function is_enabled()
    {
        $cfg = self::get();
        return !empty($cfg['enabled']) && self::shop_url() !== '';
    }

    public static function shop_url()
    {
        $cfg = self::get();
        return self::normalize_url((string) ($cfg['shop_url'] ?? ''));
    }
}
