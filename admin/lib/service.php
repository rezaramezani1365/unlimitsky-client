<?php

require_once __DIR__ . '/init.php';

class USK_Service
{
    public static function create_on_panel($panel, $volume_gb, $duration_days, $username)
    {
        if ($panel['type'] === 'marzban') {
            return self::create_marzban($panel, $volume_gb, $duration_days, $username);
        }
        if ($panel['type'] === 'sanayi') {
            return self::create_sanayi($panel, $volume_gb, $duration_days, $username);
        }
        return array('ok' => false, 'error' => 'نوع پنل پشتیبانی نمی‌شود');
    }

    public static function create_native($protocol, $volume_gb, $duration_days, $username, array $meta = array())
    {
        $created = USK_ProtocolProvisioner::create($protocol, $username, $volume_gb, $duration_days, $meta);
        if (empty($created['ok'])) {
            $code = $created['error'] ?? 'create_failed';
            return array('ok' => false, 'error' => USK_ProtocolProvisioner::error_label($code), 'code' => $code, 'log' => $created['log'] ?? '');
        }
        $links = $created['links'] ?: $created['config'];
        $sub = $created['subscription'] ?: $links;
        return array(
            'ok' => true,
            'subscription' => $sub,
            'links' => $links,
            'config' => $created['config'] ?? $links,
            'username' => $username,
            'protocol' => $protocol,
            'qr_png' => $created['qr_png'] ?? '',
            'qr_conf_png' => $created['qr_conf_png'] ?? '',
            'vpn_uri' => $created['vpn_uri'] ?? '',
            'wg_conf' => $created['wg_conf'] ?? '',
            'expires_at' => $created['expires_at'] ?? null,
            'raw' => $created['raw'] ?? array(),
        );
    }

    private static function refresh_token($panel)
    {
        global $sql;
        if ($panel['type'] === 'marzban') {
            $login = loginPanel($panel['login_link'], $panel['username'], $panel['password']);
            if (!empty($login['access_token'])) {
                $t = $sql->real_escape_string($login['access_token']);
                $code = $sql->real_escape_string($panel['code']);
                $sql->query("UPDATE `panels` SET `token` = '$t' WHERE `code` = '$code'");
                $panel['token'] = $login['access_token'];
            }
        } elseif ($panel['type'] === 'sanayi') {
            $cookie = USK_ROOT . '/cookie.txt';
            $response = loginPanelSanayi($panel['login_link'], $panel['username'], $panel['password']);
            if (!empty($response['success']) && file_exists($cookie)) {
                $parts = explode('session	', file_get_contents($cookie));
                $session = isset($parts[1]) ? str_replace(array(" ", "\n", "\t"), array('', '', ''), $parts[1]) : '';
                if ($session) {
                    $session = $sql->real_escape_string($session);
                    $code = $sql->real_escape_string($panel['code']);
                    $sql->query("UPDATE `panels` SET `token` = '$session' WHERE `code` = '$code'");
                    $panel['token'] = $session;
                }
            }
        }
        return $panel;
    }

    private static function create_marzban($panel, $volume_gb, $days, $username)
    {
        $panel = self::refresh_token($panel);
        $protocols = array_filter(explode('|', $panel['protocols']));
        $proxies = array();
        foreach ($protocols as $protocol) {
            $proxies[$protocol] = ($protocol === 'vless' && $panel['flow'] === 'flowon')
                ? array('flow' => 'xtls-rprx-vision') : array();
        }
        $inbounds = array();
        global $sql;
        $res = $sql->query("SELECT * FROM `marzban_inbounds` WHERE `panel` = '{$panel['code']}' AND `status` = 'active'");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                foreach ($protocols as $protocol) {
                    $inbounds[$protocol][] = $row['inbound'];
                }
            }
        }
        $token = $panel['token'];
        if (!$token) {
            $login = loginPanel($panel['login_link'], $panel['username'], $panel['password']);
            $token = $login['access_token'] ?? '';
        }
        $raw = createService(
            $username,
            convertToBytes($volume_gb . 'GB'),
            strtotime("+ {$days} day"),
            $proxies,
            count($inbounds) ? $inbounds : 'null',
            $token,
            $panel['login_link']
        );
        $data = json_decode($raw, true);
        if (empty($data['username'])) {
            $err = isset($data['detail']) ? $data['detail'] : 'خطا در Marzban';
            return array('ok' => false, 'error' => is_string($err) ? $err : json_encode($err));
        }
        $sub = (strpos($data['subscription_url'], 'http') !== false)
            ? $data['subscription_url']
            : rtrim($panel['login_link'], '/') . $data['subscription_url'];
        $links = '';
        if (!empty($data['links']) && is_array($data['links'])) {
            foreach ($data['links'] as $l) {
                $links .= $l . "\n";
            }
        }
        return array('ok' => true, 'subscription' => $sub, 'links' => trim($links), 'username' => $username);
    }

    private static function create_sanayi($panel, $volume_gb, $days, $username)
    {
        global $sql;
        $panel = self::refresh_token($panel);
        $setting = $sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code` = '{$panel['code']}'")->fetch_assoc();
        if (!$setting || empty($setting['inbound_id']) || $setting['inbound_id'] === 'none') {
            return array('ok' => false, 'error' => 'inbound_id پنل سنایی تنظیم نشده');
        }
        $xui = new Sanayi($panel['login_link'], $panel['token']);
        $result = json_decode($xui->addClient($username, $setting['inbound_id'], $days, $volume_gb), true);
        if (empty($result['status'])) {
            return array('ok' => false, 'error' => 'خطا در Sanaei');
        }
        $port_data = json_decode($xui->getPortById($setting['inbound_id']), true);
        $port = isset($port_data['port']) ? $port_data['port'] : parse_url($panel['login_link'], PHP_URL_PORT);
        $host = str_replace(
            parse_url($panel['login_link'], PHP_URL_PORT) ? parse_url($panel['login_link'], PHP_URL_PORT) : '',
            $port,
            str_replace(array('https://', 'http://'), array('', ''), $panel['login_link'])
        );
        $link = str_replace(
            array('%s1', '%s2', '%s3'),
            array($result['results']['id'], $host, $result['results']['remark']),
            $setting['example_link']
        );
        $sub = isset($result['results']['subscribe']) ? $result['results']['subscribe'] : $link;
        return array('ok' => true, 'subscription' => $sub, 'links' => trim($link . "\n" . $sub), 'username' => $username);
    }
}
