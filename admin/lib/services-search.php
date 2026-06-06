<?php

require_once __DIR__ . '/service-config-view.php';
require_once __DIR__ . '/protocols/limits.php';

class USK_ServicesSearch
{
    const MIN_QUERY_LEN = 2;
    const MAX_RESULTS = 25;

    public static function sanitize_query($raw)
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string) $raw));
    }

    /**
     * @return array{ok:bool,query:string,results:array,count:int,error?:string}
     */
    public static function search($rawQuery)
    {
        global $sql;
        if (!($sql instanceof mysqli) || $sql->connect_error) {
            return array('ok' => false, 'query' => '', 'results' => array(), 'count' => 0, 'error' => 'db_unavailable');
        }

        $query = self::sanitize_query($rawQuery);
        if ($query === '') {
            return array('ok' => true, 'query' => '', 'results' => array(), 'count' => 0);
        }
        if (strlen($query) < self::MIN_QUERY_LEN) {
            return array('ok' => true, 'query' => $query, 'results' => array(), 'count' => 0, 'error' => 'query_too_short');
        }

        $like = '%' . $sql->real_escape_string($query) . '%';
        $limit = (int) self::MAX_RESULTS;
        $res = $sql->query(
            "SELECT * FROM `orders` WHERE `code` LIKE '$like' ORDER BY `row` DESC LIMIT $limit"
        );
        if (!$res) {
            return array('ok' => false, 'query' => $query, 'results' => array(), 'count' => 0, 'error' => 'search_failed');
        }

        $results = array();
        while ($row = $res->fetch_assoc()) {
            $results[] = self::format_result($row);
        }
        $res->free();

        return array(
            'ok' => true,
            'query' => $query,
            'results' => $results,
            'count' => count($results),
        );
    }

    /** @return array<string,mixed> */
    public static function format_result(array $order)
    {
        $row = usk_service_list_row($order);
        $usage = is_array($row['usage']) ? $row['usage'] : null;

        return array(
            'id' => $row['id'],
            'code' => $row['code'],
            'location' => $row['location'],
            'volume' => $row['volume'],
            'date' => $row['date'],
            'protocol' => $row['protocol'],
            'status' => $row['status'],
            'badge_class' => $row['badge_class'],
            'badge_label' => $row['badge_label'],
            'usage_display' => $row['usage_display'],
            'usage_percent' => $row['usage_percent'],
            'used_gb' => $usage ? (float) ($usage['used_gb'] ?? 0) : null,
            'remaining_gb' => $usage ? $usage['remaining_gb'] : null,
            'view_url' => $row['view_url'],
            'portal_url' => $row['portal_url'],
        );
    }
}
