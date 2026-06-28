<?php

require_once __DIR__ . '/woocommerce-shop.php';
require_once __DIR__ . '/license.php';
require_once __DIR__ . '/protocols/manager.php';
require_once __DIR__ . '/protocols/limits.php';

class USK_CustomerRenewal
{
    public static function renew_signature($serviceCode, $planCode, $protocol, $downloadToken)
    {
        $serviceCode = preg_replace('/[^0-9]/', '', (string) $serviceCode);
        $planCode = preg_replace('/[^0-9]/', '', (string) $planCode);
        $protocol = USK_ProtocolManager::sanitize_key((string) $protocol);
        $downloadToken = preg_replace('/[^a-f0-9]/', '', strtolower((string) $downloadToken));
        if ($serviceCode === '' || $planCode === '' || $protocol === '' || $downloadToken === '') {
            return '';
        }

        return hash_hmac('sha256', $serviceCode . '|' . $planCode . '|' . $protocol, $downloadToken);
    }

    public static function verify_signature($serviceCode, $planCode, $protocol, $downloadToken, $signature)
    {
        $expected = self::renew_signature($serviceCode, $planCode, $protocol, $downloadToken);
        if ($expected === '') {
            return false;
        }
        $signature = preg_replace('/[^a-f0-9]/', '', strtolower((string) $signature));
        return hash_equals($expected, $signature);
    }

    public static function build_renew_url($serviceCode, $planCode, $protocol, $downloadToken)
    {
        $shop = USK_WooCommerce_Shop::shop_url();
        if ($shop === '') {
            return '';
        }
        $sig = self::renew_signature($serviceCode, $planCode, $protocol, $downloadToken);
        if ($sig === '') {
            return '';
        }

        $query = array(
            'usk_renew' => '1',
            'usk_service' => preg_replace('/[^0-9]/', '', (string) $serviceCode),
            'usk_plan' => preg_replace('/[^0-9]/', '', (string) $planCode),
            'usk_protocol' => USK_ProtocolManager::sanitize_key((string) $protocol),
            'usk_sig' => $sig,
        );

        return $shop . '/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Active panel plans for renewal — same protocol enforced in signature + WC/API.
     *
     * @return array<int, array{plan_code:string,name:string,volume_gb:int,duration_days:int,price:string,url:string}>
     */
    public static function plans_for_service($protocol, $serviceCode, $downloadToken)
    {
        if (!USK_WooCommerce_Shop::is_enabled()) {
            return array();
        }

        $protocol = USK_ProtocolManager::sanitize_key((string) $protocol);
        if ($protocol === '') {
            return array();
        }

        $serviceCode = preg_replace('/[^0-9]/', '', (string) $serviceCode);
        $downloadToken = preg_replace('/[^a-f0-9]/', '', strtolower((string) $downloadToken));
        if ($serviceCode === '' || $downloadToken === '') {
            return array();
        }

        $out = array();
        foreach (USK_License::list_active_plans() as $plan) {
            $planCode = preg_replace('/[^0-9]/', '', (string) ($plan['code'] ?? ''));
            if ($planCode === '') {
                continue;
            }
            $url = self::build_renew_url($serviceCode, $planCode, $protocol, $downloadToken);
            if ($url === '') {
                continue;
            }
            $out[] = array(
                'plan_code' => $planCode,
                'name' => (string) ($plan['name'] ?? ''),
                'volume_gb' => (int) ($plan['limit'] ?? 0),
                'duration_days' => (int) ($plan['date'] ?? 0),
                'price' => (string) ($plan['price'] ?? ''),
                'connections' => (int) ($plan['connections'] ?? 1),
                'url' => $url,
            );
        }

        return $out;
    }

    /**
     * @return array{ok:bool,error?:string,order?:array,native?:array,client?:array}
     */
    public static function resolve_renew_target($serviceCode)
    {
        global $sql;

        $serviceCode = preg_replace('/[^0-9]/', '', (string) $serviceCode);
        if ($serviceCode === '' || !$sql instanceof mysqli) {
            return array('ok' => false, 'error' => 'invalid_request');
        }

        $code_esc = $sql->real_escape_string($serviceCode);
        $order = $sql->query("SELECT * FROM `orders` WHERE `code`='$code_esc' LIMIT 1")->fetch_assoc();
        if (!$order || ($order['type'] ?? '') !== 'native') {
            return array('ok' => false, 'error' => 'not_found');
        }

        $native = USK_ProtocolLimits::find_client_for_order($order);
        if (!$native) {
            return array('ok' => false, 'error' => 'removed');
        }

        $client = $native['client'] ?? array();
        if (($client['status'] ?? '') === 'revoked') {
            return array('ok' => false, 'error' => 'removed');
        }

        return array(
            'ok' => true,
            'order' => $order,
            'native' => $native,
            'client' => $client,
            'protocol' => (string) ($native['protocol'] ?? ($order['protocol'] ?? '')),
        );
    }

    public static function extend_service($serviceCode, $planCode, $protocol, $signature, $wcOrderId = null)
    {
        require_once __DIR__ . '/protocols/limits.php';

        $target = self::resolve_renew_target($serviceCode);
        if (empty($target['ok'])) {
            return $target;
        }

        $order = $target['order'];
        $native = $target['native'];
        $client = $target['client'];
        $serviceProtocol = USK_ProtocolManager::sanitize_key((string) ($target['protocol'] ?? ''));
        $protocol = USK_ProtocolManager::sanitize_key((string) $protocol);
        $planCode = preg_replace('/[^0-9]/', '', (string) $planCode);

        if ($protocol === '' || $serviceProtocol === '' || $protocol !== $serviceProtocol) {
            return array('ok' => false, 'error' => 'protocol_mismatch');
        }

        $token = (string) ($client['download_token'] ?? ($client['meta']['download_token'] ?? ''));
        if (!self::verify_signature($serviceCode, $planCode, $protocol, $token, $signature)) {
            return array('ok' => false, 'error' => 'invalid_signature');
        }

        $planRow = USK_License::get_plan_by_code($planCode);
        if ($planRow === null) {
            return array('ok' => false, 'error' => 'plan_inactive_or_missing');
        }

        $extraDays = max(0, (int) ($planRow['date'] ?? 0));
        $extraGb = max(0, (int) ($planRow['limit'] ?? 0));
        if ($extraDays < 1 && $extraGb < 1) {
            return array('ok' => false, 'error' => 'plan_has_no_extension');
        }

        $result = USK_ProtocolLimits::extend_client(
            $native['protocol'],
            $native['username'],
            $extraDays,
            $extraGb
        );

        if (empty($result['ok'])) {
            return array('ok' => false, 'error' => $result['error'] ?? 'extend_failed');
        }

        $updatedClient = $result['client'] ?? $client;
        if ($wcOrderId) {
            $clients = USK_ProtocolLimits::load_protocol_clients($native['protocol']);
            if (isset($clients[$native['username']])) {
                $clients[$native['username']]['wc_order_id'] = (int) $wcOrderId;
                $clients[$native['username']]['last_renew_at'] = date('c');
                $clients[$native['username']]['last_renew_plan'] = $planCode;
                USK_ProtocolLimits::save_protocol_clients($native['protocol'], $clients);
                $updatedClient = $clients[$native['username']];
            }
        }

        require_once __DIR__ . '/customer-portal.php';
        $portalUrl = usk_customer_portal_url($order['code'], $token);

        return array(
            'ok' => true,
            'service_code' => (string) $order['code'],
            'protocol' => $protocol,
            'username' => (string) ($native['username'] ?? ''),
            'portal_url' => $portalUrl,
            'volume_gb' => (int) ($updatedClient['volume_gb'] ?? ($order['volume'] ?? 0)),
            'duration_days' => (int) ($updatedClient['duration_days'] ?? ($order['date'] ?? 0)),
            'expires_at' => (string) ($updatedClient['expires_at'] ?? ''),
            'extra_days' => $extraDays,
            'extra_gb' => $extraGb,
            'plan_code' => $planCode,
        );
    }
}
