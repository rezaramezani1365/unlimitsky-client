<?php

defined('ABSPATH') || exit;

class USK_Config_Rewriter
{
    /**
     * همه آدرس‌ها را به dns.iranip.online تبدیل می‌کند (IP و دامنه قبلی پنل).
     */
    public static function rewrite_to_connect_domain(string $content, string $connect_host, array $panel = []): string
    {
        $connect_host = trim($connect_host);
        if ($connect_host === '') {
            return $content;
        }

        $old_hosts = self::extract_hosts_from_panel($panel);

        $decoded = base64_decode($content, true);
        if ($decoded !== false && strpos($decoded, '://') !== false) {
            return base64_encode(self::rewrite_lines($decoded, $connect_host, $old_hosts, true));
        }

        return self::rewrite_lines($content, $connect_host, $old_hosts, true);
    }

    /**
     * @param string[] $old_hosts
     */
    public static function rewrite_lines(string $content, string $new_host, array $old_hosts = [], bool $force_domain = false): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $out   = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $out[] = self::rewrite_line($line, $new_host, $old_hosts, $force_domain);
        }

        return implode("\n", $out);
    }

    /**
     * @param string[] $old_hosts
     */
    public static function rewrite_line(string $line, string $new_host, array $old_hosts = [], bool $force_domain = false): string
    {
        if (stripos($line, 'vmess://') === 0) {
            return self::rewrite_vmess($line, $new_host, $force_domain);
        }

        foreach (['vless://', 'trojan://', 'ss://', 'hysteria2://', 'tuic://'] as $scheme) {
            if (stripos($line, $scheme) === 0) {
                return self::rewrite_uri_host($line, $new_host, $old_hosts, $force_domain);
            }
        }

        if ($force_domain) {
            return self::rewrite_uri_host($line, $new_host, $old_hosts, true);
        }

        foreach ($old_hosts as $old) {
            if ($old !== '' && stripos($line, $old) !== false) {
                $line = str_ireplace($old, $new_host, $line);
            }
        }

        return $line;
    }

    /**
     * @param string[] $old_hosts
     */
    private static function rewrite_uri_host(string $uri, string $new_host, array $old_hosts, bool $force_domain): string
    {
        if ($force_domain) {
            // uuid@HOST:port یا pass@HOST:port → همیشه dns.iranip.online
            $uri = preg_replace('/@([^:@\/?#]+)(?=:\d+)/', '@' . $new_host, $uri);
            $uri = preg_replace('/([?&;])(host|sni|peer)=([^&;]+)/i', '$1$2=' . $new_host, $uri);
            return $uri;
        }

        foreach ($old_hosts as $old) {
            if ($old === '') {
                continue;
            }
            $uri = preg_replace('/@' . preg_quote($old, '/') . '(?=[:/?#])/i', '@' . $new_host, $uri);
            $uri = preg_replace('/([?&;])(host|sni|peer)=(' . preg_quote($old, '/') . ')/i', '$1$2=' . $new_host, $uri);
        }

        return $uri;
    }

    private static function rewrite_vmess(string $line, string $new_host, bool $force_domain): string
    {
        $payload = substr($line, 8);
        $json    = base64_decode($payload, true);
        if ($json === false) {
            return preg_replace('/@([^:@\/?#]+)(?=:\d+)/', '@' . $new_host, $line);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $line;
        }

        if ($force_domain || !empty($data['add'])) {
            $data['add'] = $new_host;
        }
        if ($force_domain || !empty($data['host'])) {
            $data['host'] = $new_host;
        }
        if ($force_domain || !empty($data['sni'])) {
            $data['sni'] = $new_host;
        }

        return 'vmess://' . base64_encode(wp_json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public static function extract_hosts_from_panel(array $panel): array
    {
        $hosts = [];

        if (!empty($panel['backend_ip'])) {
            $hosts[] = $panel['backend_ip'];
        }
        if (!empty($panel['backend_host'])) {
            $hosts[] = $panel['backend_host'];
        }

        $parsed = wp_parse_url($panel['login_link'] ?? '');
        if (!empty($parsed['host'])) {
            $hosts[] = $parsed['host'];
        }

        return array_values(array_unique(array_filter($hosts)));
    }
}
