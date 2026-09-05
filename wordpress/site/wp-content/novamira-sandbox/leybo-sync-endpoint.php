<?php
/**
 * A crank handle for the price/stock sync.
 *
 * A full pass over the mapped catalogue takes ~10 s per article and blows past
 * both the PHP request ceiling and the admin API timeout, and WP-CLI is not an
 * option on this host (RusToLat fatals under PHP 8.2 in CLI). WP-cron only fires
 * on visits, so it cannot be relied on to grind a backlog either.
 *
 * So: one slice per request, driven from outside in a loop. The resume mode of
 * leybo_sync_run() means a dropped or timed-out request costs nothing - the next
 * call simply picks up whatever has not been touched in the last hour.
 *
 * Guarded by a secret, not by a login, because the caller is a shell loop.
 */

if (!defined('ABSPATH')) { exit; }

function leybo_sync_key() {
    $k = get_option('leybo_sync_key', '');
    if ($k === '') {
        $k = wp_generate_password(28, false, false);
        update_option('leybo_sync_key', $k, false);
    }
    return $k;
}

add_action('rest_api_init', function () {
    register_rest_route('leybo/v1', '/sync', [
        'methods'  => 'GET',
        'callback' => function (WP_REST_Request $req) {
            $limit = max(1, min(20, (int)$req->get_param('limit') ?: 8));
            if (!function_exists('leybo_sync_run')) {
                return new WP_Error('no_sync', 'leybo-sync.php не загружен', ['status' => 500]);
            }
            @set_time_limit(300);
            // offset >= 0 walks the catalogue by position and ignores the
            // freshness window - needed after a source outage, when everything
            // on record is stale but still inside the window.
            $off = $req->get_param('offset');
            $off = ($off === null || $off === '') ? -1 : max(0, (int)$off);
            $log = leybo_sync_run(false, $off, $limit);
            return [
                'обработано'     => isset($log['products']) ? $log['products'] : 0,
                'цен_изменилось' => isset($log['price_changed']) ? $log['price_changed'] : 0,
                'размеров_выкл'  => isset($log['sizes_off']) ? $log['sizes_off'] : 0,
                'размеров_вкл'   => isset($log['sizes_on']) ? $log['sizes_on'] : 0,
                'не_найдено'     => isset($log['not_found']) ? $log['not_found'] : [],
                'ошибки'         => isset($log['errors']) ? $log['errors'] : [],
                'осталось'       => leybo_sync_pending(),
            ];
        },
        'permission_callback' => function (WP_REST_Request $req) {
            $given = (string)$req->get_param('key');
            return $given !== '' && hash_equals(leybo_sync_key(), $given);
        },
    ]);
});

/** How many mapped products have not been synced in the last hour. */
function leybo_sync_pending() {
    if (!function_exists('leybo_mapped_products')) { return -1; }
    $cut = strtotime(current_time('mysql')) - (defined('LEYBO_FRESH_FOR') ? LEYBO_FRESH_FOR : 39600);
    $n = 0;
    foreach (leybo_mapped_products() as $pid) {
        $t = get_post_meta($pid, '_leybo_sync_attempt', true);
        if (!$t) { $t = get_post_meta($pid, '_leybo_synced', true); }
        if (!$t || strtotime($t) <= $cut) { $n++; }
    }
    return $n;
}
