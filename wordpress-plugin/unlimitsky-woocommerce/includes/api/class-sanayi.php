<?php

defined('ABSPATH') || exit;

/**
 * Sanaei / 3x-ui panel API – ported from UnlimitSky api/sanayi.php
 */
class USK_Sanayi
{
    private string $base_url;
    private string $session;
    private array $headers;

    public function __construct(string $base_url, string $session)
    {
        $this->base_url = rtrim($base_url, '/');
        $this->session  = $session;
        $host           = str_replace(['https://', 'http://'], ['', ''], $this->base_url);

        $this->headers = [
            'Cookie: session=' . $this->session,
            'User-Agent: Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:103.0) Gecko/20100101 Firefox/103.0',
            'Connection: keep-alive',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Host: ' . $host,
            'Origin: ' . $this->base_url,
            'Referer: ' . $this->base_url . '/panel/inbounds',
            'X-Requested-With: XMLHttpRequest',
        ];
    }

    private function convert_to_bytes(string $from): ?int
    {
        $units  = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $number = substr($from, 0, -2);
        $suffix = strtoupper(substr($from, -2));

        if (is_numeric(substr($suffix, 0, 1))) {
            return (int) preg_replace('/[^\d]/', '', $from);
        }

        $exponent = array_flip($units)[$suffix] ?? null;
        return $exponent === null ? null : (int) ($number * (1024 ** $exponent));
    }

    private function gen_uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    private function generate_random_string(int $length = 8): string
    {
        return bin2hex(random_bytes($length));
    }

    public function get_sub_port(): ?array
    {
        $curl = curl_init($this->base_url . '/panel/setting/all');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_HTTPHEADER     => $this->headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $result = json_decode(curl_exec($curl), true);
        curl_close($curl);
        return $result;
    }

    public function get_port_by_id(string $id): ?array
    {
        $curl = curl_init($this->base_url . '/panel/inbound/list');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_HTTPHEADER     => $this->headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $result = json_decode(curl_exec($curl), true);
        curl_close($curl);

        if (empty($result['obj'])) {
            return null;
        }

        foreach ($result['obj'] as $value) {
            if ((string) $value['id'] === (string) $id) {
                return ['status' => true, 'port' => $value['port']];
            }
        }

        return ['status' => false];
    }

    public function add_client(string $name, string $inbound_id, int $days, int $limit_gb): array
    {
        $url   = $this->base_url . '/panel/inbound/addClient';
        $total = $this->convert_to_bytes($limit_gb . 'GB');
        $date  = (int) ((time() + ($days * 86400)) * 1000);
        $uuid  = $this->gen_uuid();
        $subid = $this->generate_random_string();

        $sub_port_data = $this->get_sub_port();
        $sub_port      = $sub_port_data['obj']['subPort'] ?? null;
        $parts         = parse_url($this->base_url);

        $settings = wp_json_encode([
            'clients' => [[
                'id'         => $uuid,
                'flow'       => '',
                'email'      => $name,
                'limitIp'    => 1,
                'totalGB'    => $total,
                'expiryTime' => $date,
                'enable'     => true,
                'tgId'       => '',
                'subId'      => $subid,
            ]],
        ]);

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $this->headers,
            CURLOPT_POSTFIELDS     => "id={$inbound_id}&settings={$settings}",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $resp = json_decode(curl_exec($curl), true);
        curl_close($curl);

        if (!empty($resp['success'])) {
            $subscribe = str_replace($parts['port'] ?? '', $sub_port, $this->base_url) . '/sub/' . $subid;
            return [
                'status'  => true,
                'results' => [
                    'subscribe' => $subscribe,
                    'id'        => $uuid,
                    'remark'    => $name,
                    'subId'     => $subid,
                ],
            ];
        }

        return ['status' => false, 'msg' => 'unsuccessful'];
    }

    public function build_config_link(string $example_link, array $results, string $panel_link, string $inbound_id): string
    {
        $port_data = $this->get_port_by_id($inbound_id);
        $port      = $port_data['port'] ?? parse_url($panel_link, PHP_URL_PORT);

        $host = str_replace(
            [parse_url($panel_link, PHP_URL_PORT) ?? ''],
            [$port],
            str_replace(['https://', 'http://'], ['', ''], $panel_link)
        );

        return str_replace(
            ['%s1', '%s2', '%s3'],
            [$results['id'], $host, $results['remark']],
            $example_link
        );
    }
}
