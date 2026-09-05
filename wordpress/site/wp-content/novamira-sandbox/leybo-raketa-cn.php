<?php
/**
 * LEYBO -> Raketa CN (reseller.raketacn.ru).
 *
 * Replaces the older raketaapi.ru integration, which spoke a completely
 * different language: declarations, passports, INN, and a Chinese track number
 * that forced a two-step chain. The reseller contract needs three fields and
 * nothing else:
 *
 *     reseller_order_id   our own id for the line
 *     sku_id              dewu skuId of the exact size that was bought
 *     price_cny           what the pair costs on dewu, in yuan
 *
 * One Raketa order is one pair. A shop order with three pairs therefore
 * produces three Raketa orders, keyed "<order>-<item>-<n>", which also makes a
 * re-send naturally idempotent: their side sees the same id again.
 *
 * The size -> skuId link is the delicate part and is snapshotted onto the order
 * line the moment the order is created. Resolving it later would read whatever
 * dewu says today, and a customer who bought EU 42 must stay EU 42 even if the
 * catalogue moves underneath.
 *
 * Nothing leaves the server until the mode option says "live". Dry runs build
 * and validate the exact same payload, so what is tested is what is sent.
 */

if (!defined('ABSPATH')) { exit; }

const LEYBO_RCN_BASE   = 'https://reseller.raketacn.ru/api/v1';
const LEYBO_RCN_LOOKUP = 'https://leyboapi.vastubase.store/v1/product';

/* ----------------------------------------------------------------- options */

function leybo_rcn_opt($k, $default = '') {
    return get_option('leybo_rcn_' . $k, $default);
}

/** "dry" builds and logs the payload but sends nothing. Default on purpose. */
function leybo_rcn_is_live() {
    return leybo_rcn_opt('mode', 'dry') === 'live';
}

/* -------------------------------------------------------------- HTTP client */

/**
 * @return array{ok:bool,http:int,body:mixed,error:string}
 */
function leybo_rcn_request($method, $path, $body = null) {
    $token = trim((string)leybo_rcn_opt('token'));
    if ($token === '') {
        return ['ok' => false, 'http' => 0, 'body' => null, 'error' => 'нет токена'];
    }
    $args = [
        'method'  => $method,
        'timeout' => 45,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
    ];
    if ($body !== null) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = wp_json_encode($body);
    }
    $res = wp_remote_request(LEYBO_RCN_BASE . $path, $args);
    if (is_wp_error($res)) {
        return ['ok' => false, 'http' => 0, 'body' => null, 'error' => $res->get_error_message()];
    }
    $http = (int)wp_remote_retrieve_response_code($res);
    $raw  = wp_remote_retrieve_body($res);
    $json = json_decode($raw, true);
    return [
        'ok'    => $http >= 200 && $http < 300,
        'http'  => $http,
        'body'  => $json === null ? $raw : $json,
        'error' => '',
    ];
}

function leybo_rcn_balance() { return leybo_rcn_request('GET', '/balance'); }

/* ------------------------------------------------------- size normalisation */

/**
 * dewu writes EU thirds (36 2/3, 37 1/3); the shop mostly uses halves.
 * Kept in step with the same table in leybo-sync.php.
 */
function leybo_rcn_size_table() {
    return [
        '35 2/3' => '35.5', '36 2/3' => '36.5', '38 2/3' => '38.5', '40 2/3' => '40.5',
        '42 2/3' => '42.5', '44 2/3' => '44.5', '46 2/3' => '46.5', '48 2/3' => '48.5',
        '37 1/3' => '37.5', '39 1/3' => '39',   '41 1/3' => '41',   '43 1/3' => '43',
        '45 1/3' => '45',   '47 1/3' => '47.5', '49 1/3' => '49',   '51 1/3' => '51',
    ];
}

function leybo_rcn_norm_size($s) {
    $s = trim((string)$s);
    $s = str_replace(['⅔', '⅓'], [' 2/3', ' 1/3'], $s);
    $s = preg_replace('/\s+/', ' ', $s);
    $s = str_replace(',', '.', $s);
    if (preg_match('/^(\d+)\.0$/', $s, $m)) { $s = $m[1]; }
    return $s;
}

/**
 * Every shop label a given dewu size may appear under.
 *
 * Deliberately a plain list, not a keyed set: PHP turns the numeric string key
 * "35" into int 35, and array_keys() then hands back an integer that never
 * matches "35" under ===. Whole sizes silently stopped resolving while halves
 * ("36.5" is not an integer-like key) kept working.
 */
function leybo_rcn_aliases($dewu_size) {
    $n   = leybo_rcn_norm_size($dewu_size);
    $tbl = leybo_rcn_size_table();
    $out = [$n];
    if (isset($tbl[$n])) { $out[] = leybo_rcn_norm_size($tbl[$n]); }
    return array_values(array_unique($out));
}

/* ------------------------------------------------------- product resolution */

/** The dewu article a shop product is mapped to, or ''. */
function leybo_rcn_article($product_id, $parent_id = 0) {
    $a = get_post_meta($product_id, '_leybo_article', true);
    if (!$a && $parent_id) { $a = get_post_meta($parent_id, '_leybo_article', true); }
    return strtoupper(trim((string)$a));
}

/** Size label of a variation, or ''. */
function leybo_rcn_variation_size($variation_id) {
    $v = wc_get_product($variation_id);
    if (!$v) { return ''; }
    $attrs = $v->get_attributes();
    if (empty($attrs['pa_razmer'])) { return ''; }
    $term = get_term_by('slug', $attrs['pa_razmer'], 'pa_razmer');
    $name = $term ? $term->name : str_replace('-', '.', $attrs['pa_razmer']);
    return leybo_rcn_norm_size($name);
}

/**
 * article + size -> ['sku' => int, 'price_cny' => float, 'available' => bool].
 * Returns ['error' => string] when the pair cannot be established. Guessing is
 * not an option here: a wrong skuId means Raketa buys the wrong shoe.
 */
/**
 * One leyboapi call per article, cached.
 *
 * A live lookup costs ~8 s, and it happens per order line while the customer is
 * waiting on the checkout button. The size -> skuId map itself never changes, so
 * half an hour of staleness costs nothing and a two-pair order stops taking
 * sixteen seconds.
 */
function leybo_rcn_lookup($article) {
    $key    = 'leybo_rcn_' . md5($article);
    $cached = get_transient($key);
    if (is_array($cached)) { return $cached; }

    $url = add_query_arg(['article' => $article, 'sizes' => 'true'], LEYBO_RCN_LOOKUP);
    $res = wp_remote_get($url, [
        'timeout' => 45,
        'headers' => ['x-api-key' => get_option('leybo_api_key', '')],
    ]);
    if (is_wp_error($res)) { return ['error' => 'leyboapi: ' . $res->get_error_message()]; }
    if ((int)wp_remote_retrieve_response_code($res) !== 200) {
        return ['error' => 'leyboapi HTTP ' . wp_remote_retrieve_response_code($res)];
    }
    $data = json_decode(wp_remote_retrieve_body($res), true);
    if (!is_array($data)) { return ['error' => 'leyboapi: битый JSON']; }

    // Only cache a usable answer; a miss should be retried, not remembered.
    if (!empty($data['found'])) { set_transient($key, $data, 30 * MINUTE_IN_SECONDS); }
    return $data;
}

function leybo_rcn_resolve($article, $size) {
    if ($article === '') { return ['error' => 'у товара нет _leybo_article']; }

    $data = leybo_rcn_lookup($article);
    if (!empty($data['error'])) { return ['error' => $data['error']]; }
    if (empty($data['found'])) {
        return ['error' => 'артикул ' . $article . ' не найден на dewu'];
    }

    $cny = isset($data['price']['cny']) ? (float)$data['price']['cny'] : 0.0;
    if ($cny <= 0) { return ['error' => 'нет цены в ¥ для ' . $article]; }

    if ($size === '') {
        // No size attribute at all - a one-size product. Only safe when dewu
        // agrees there is exactly one sku.
        $sizes = isset($data['sizes']) ? $data['sizes'] : [];
        if (count($sizes) === 1) {
            return ['sku' => (int)$sizes[0]['sku'], 'price_cny' => $cny,
                    'available' => !empty($sizes[0]['available']), 'size' => (string)$sizes[0]['size']];
        }
        return ['error' => 'в заказе нет размера, а у товара их ' . count($sizes)];
    }

    foreach ((isset($data['sizes']) ? $data['sizes'] : []) as $row) {
        foreach (leybo_rcn_aliases(isset($row['size']) ? $row['size'] : '') as $alias) {
            if ($alias === $size) {
                return [
                    'sku'       => (int)$row['sku'],
                    'price_cny' => $cny,
                    'available' => !empty($row['available']),
                    'size'      => (string)$row['size'],
                ];
            }
        }
    }
    return ['error' => 'размер ' . $size . ' не найден у ' . $article . ' на dewu'];
}

/* ------------------------------------------------ snapshot at order creation */

/**
 * Freeze article / size / skuId / CNY onto the line while the customer is still
 * on the page. Doing it later would resolve against a catalogue that has moved.
 */
add_action('woocommerce_checkout_create_order_line_item', function ($item, $key, $values, $order) {
    $pid  = $item->get_product_id();
    $vid  = $item->get_variation_id();
    $art  = leybo_rcn_article($vid ?: $pid, $pid);
    $size = $vid ? leybo_rcn_variation_size($vid) : '';

    $item->add_meta_data('_leybo_article', $art, true);
    $item->add_meta_data('_leybo_size', $size, true);

    $r = leybo_rcn_resolve($art, $size);
    if (!empty($r['error'])) {
        $item->add_meta_data('_leybo_sku_error', $r['error'], true);
        return;
    }
    $item->add_meta_data('_leybo_sku', $r['sku'], true);
    $item->add_meta_data('_leybo_cny', $r['price_cny'], true);
    $item->add_meta_data('_leybo_dewu_size', $r['size'], true);
}, 10, 4);

/* --------------------------------------------------------- payload assembly */

/**
 * One entry per pair.
 * @return array{lines:array,problems:array}
 */
function leybo_rcn_build($order) {
    $lines = $problems = [];

    foreach ($order->get_items() as $item_id => $item) {
        $sku = (int)$item->get_meta('_leybo_sku');
        $cny = (float)$item->get_meta('_leybo_cny');

        // Orders placed before this plugin existed, or lines whose snapshot
        // failed, are resolved now rather than silently skipped.
        if ($sku <= 0 || $cny <= 0) {
            $art  = $item->get_meta('_leybo_article');
            $size = $item->get_meta('_leybo_size');
            if ($art === '') {
                $pid  = $item->get_product_id();
                $vid  = $item->get_variation_id();
                $art  = leybo_rcn_article($vid ?: $pid, $pid);
                $size = $vid ? leybo_rcn_variation_size($vid) : '';
            }
            $r = leybo_rcn_resolve($art, $size);
            if (!empty($r['error'])) {
                $problems[] = sprintf('позиция #%d (%s): %s', $item_id, $item->get_name(), $r['error']);
                continue;
            }
            $sku = $r['sku'];
            $cny = $r['price_cny'];
        }

        $qty = max(1, (int)$item->get_quantity());
        for ($n = 1; $n <= $qty; $n++) {
            $rid = $order->get_id() . '-' . $item_id . ($qty > 1 ? '-' . $n : '');
            $lines[] = [
                'reseller_order_id' => (string)$rid,
                'sku_id'            => (string)$sku,
                'price_cny'         => round($cny, 2),
            ];
        }
    }

    return ['lines' => $lines, 'problems' => $problems];
}

/* ------------------------------------------------------------------ sending */

function leybo_rcn_log($order, $entry) {
    $log = $order->get_meta('_leybo_rcn_log');
    if (!is_array($log)) { $log = []; }
    $log[] = $entry + ['ts' => current_time('mysql')];
    if (count($log) > 50) { $log = array_slice($log, -50); }
    $order->update_meta_data('_leybo_rcn_log', $log);
}

/**
 * @param bool $force resend lines that already succeeded.
 */
function leybo_rcn_send($order, $force = false) {
    if (is_numeric($order)) { $order = wc_get_order($order); }
    if (!$order) { return ['error' => 'нет заказа']; }

    $built = leybo_rcn_build($order);
    $order->update_meta_data('_leybo_rcn_payload', $built['lines']);

    $done = $order->get_meta('_leybo_rcn_done');
    if (!is_array($done)) { $done = []; }

    $live = leybo_rcn_is_live();
    $sent = $failed = $skipped = 0;

    foreach ($built['lines'] as $line) {
        $rid = $line['reseller_order_id'];
        if (!$force && isset($done[$rid])) { $skipped++; continue; }

        if (!$live) {
            leybo_rcn_log($order, ['mode' => 'dry', 'request' => $line,
                                   'http' => 0, 'response' => 'dry-run, наружу не ушло']);
            continue;
        }

        $res  = leybo_rcn_request('POST', '/orders', $line);
        $body = is_array($res['body']) ? $res['body'] : [];
        $data = isset($body['data'])  ? $body['data']  : [];
        $err  = isset($body['error']) ? $body['error'] : null;

        leybo_rcn_log($order, ['mode' => 'live', 'request' => $line,
                               'http' => $res['http'],
                               'response' => $res['error'] ?: $body]);

        // A purchase that never happened has to stay retryable: top up the
        // balance and the same reseller_order_id can simply be sent again.
        if ($err && isset($err['code']) && $err['code'] === 'INSUFFICIENT_BALANCE') {
            $order->add_order_note('Ракета: не хватает денег на балансе. ' . $err['message']);
            $failed++;
            continue;
        }
        if (!$res['ok'] || empty($body['success'])) {
            $order->add_order_note('Ракета: ошибка ' . ($err['code'] ?? ('HTTP ' . $res['http'])) . '. ' . ($err['message'] ?? $res['error']));
            $failed++;
            continue;
        }

        $done[$rid] = [
            'at'          => current_time('mysql'),
            'id'          => $data['id'] ?? '',
            'status_code' => $data['status_code'] ?? '',
            'status'      => $data['status'] ?? '',
            'poizon_no'   => $data['poizon_order_no'] ?? '',
            'platform'    => $data['platform'] ?? '',
            'price_cny'   => $data['price_cny'] ?? null,
            'agent_fee'   => $data['agent_fee'] ?? null,
            'total_rub'   => $data['total_amount'] ?? null,
        ];

        // "success" also covers a buy-out that failed: the call worked, the
        // purchase did not. A price move is the usual reason and only a human
        // can decide whether to pay the new price.
        if (strpos((string)$done[$rid]['status_code'], 'failed') === 0) {
            $mk = isset($data['market_prices']) ? $data['market_prices'] : [];
            $order->add_order_note(sprintf(
                'Ракета: выкуп НЕ прошёл — %s (%s). Сейчас на рынке: FAST %s ¥, GLOBAL %s ¥. Наша цена была %s ¥.',
                $data['status'] ?? '?', $data['failure_reason_message'] ?? '',
                $mk['fast']['price_cny']   ?? '—',
                $mk['global']['price_cny'] ?? '—',
                $line['price_cny']));
            $failed++;
        } else {
            $order->add_order_note(sprintf(
                'Ракета: выкуп заведён — %s. Poizon %s, платформа %s, факт %s ¥, агентские %s ₽, итого %s ₽.',
                $data['status'] ?? '', $data['poizon_order_no'] ?? '—', $data['platform'] ?? '—',
                $data['price_cny'] ?? '—', $data['agent_fee'] ?? '—', $data['total_amount'] ?? '—'));
            $sent++;
        }
    }

    if ($built['problems']) {
        leybo_rcn_log($order, ['mode' => 'build', 'request' => null, 'http' => 0,
                               'response' => $built['problems']]);
    }

    $order->update_meta_data('_leybo_rcn_done', $done);
    $order->save();

    $note = $live
        ? sprintf('Ракета: отправлено %d, ошибок %d, пропущено %d.', $sent, $failed, $skipped)
        : sprintf('Ракета (dry-run): собрано %d заявок, наружу не отправлено.', count($built['lines']));
    if ($built['problems']) { $note .= ' Проблемы: ' . implode('; ', $built['problems']); }
    $order->add_order_note($note);

    return ['live' => $live, 'lines' => $built['lines'], 'problems' => $built['problems'],
            'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
}

/* ----------------------------------------------------- status / return / 2.0 */

/** Their lookups take OUR id, not their uuid - so nothing extra needs storing. */
function leybo_rcn_status($rid) {
    return leybo_rcn_request('GET', '/orders/' . rawurlencode($rid));
}

/** Handover payload for Raketa 2.0, i.e. the logistics leg after the buy-out. */
function leybo_rcn_raketa2($rid) {
    return leybo_rcn_request('GET', '/orders/' . rawurlencode($rid) . '/raketa2-data');
}

function leybo_rcn_return_request($rid) {
    return leybo_rcn_request('POST', '/orders/return-request', ['reseller_order_id' => $rid]);
}

/** Pulls the current state of every line of one shop order. */
function leybo_rcn_refresh($order) {
    if (is_numeric($order)) { $order = wc_get_order($order); }
    if (!$order) { return ['error' => 'нет заказа']; }

    $done = $order->get_meta('_leybo_rcn_done');
    if (!is_array($done)) { $done = []; }
    $seen = [];

    foreach (array_keys($done) as $rid) {
        $res  = leybo_rcn_status($rid);
        $data = (is_array($res['body']) && isset($res['body']['data'])) ? $res['body']['data'] : [];
        if (!$res['ok'] || !$data) { $seen[$rid] = 'не ответил: ' . ($res['error'] ?: ('HTTP ' . $res['http'])); continue; }

        $done[$rid]['status_code'] = $data['status_code'] ?? $done[$rid]['status_code'];
        $done[$rid]['status']      = $data['status']      ?? $done[$rid]['status'];
        $done[$rid]['poizon_no']   = $data['poizon_order_no'] ?? $done[$rid]['poizon_no'];
        $done[$rid]['history']     = $data['status_history'] ?? null;
        $done[$rid]['checked']     = current_time('mysql');
        $seen[$rid] = $done[$rid]['status'];
    }

    $order->update_meta_data('_leybo_rcn_done', $done);
    $order->save();
    return $seen;
}

/* -------------------------------------------------------------------- hooks */

/** Paid -> the request goes to the logisticians. Both hooks, first one wins. */
add_action('woocommerce_payment_complete', function ($order_id) { leybo_rcn_send($order_id); }, 20);
add_action('woocommerce_order_status_processing', function ($order_id) { leybo_rcn_send($order_id); }, 20);

/* -------------------------------------------------------------- admin: order */

add_action('add_meta_boxes', function () {
    $screens = ['shop_order', 'woocommerce_page_wc-orders'];
    foreach ($screens as $s) {
        add_meta_box('leybo_rcn', 'Заявка в Ракету', 'leybo_rcn_metabox', $s, 'normal', 'default');
    }
});

function leybo_rcn_metabox($post_or_order) {
    $order = ($post_or_order instanceof WP_Post) ? wc_get_order($post_or_order->ID) : $post_or_order;
    if (!$order) { echo 'нет заказа'; return; }

    $built = leybo_rcn_build($order);
    $done  = $order->get_meta('_leybo_rcn_done');
    if (!is_array($done)) { $done = []; }

    echo '<p><strong>Режим:</strong> ' . (leybo_rcn_is_live()
        ? '<span style="color:#b32d2e">LIVE — заявки уходят в Ракету</span>'
        : '<span style="color:#2271b1">dry-run — наружу ничего не уходит</span>') . '</p>';

    if ($built['problems']) {
        echo '<div style="background:#fcf0f1;border-left:4px solid #b32d2e;padding:8px 12px;margin:8px 0"><strong>Не собралось:</strong><ul style="margin:4px 0 0 18px;list-style:disc">';
        foreach ($built['problems'] as $p) { echo '<li>' . esc_html($p) . '</li>'; }
        echo '</ul></div>';
    }

    if ($built['lines']) {
        echo '<table class="widefat striped"><thead><tr><th>reseller_order_id</th><th>sku_id</th><th>price_cny</th><th>статус</th></tr></thead><tbody>';
        foreach ($built['lines'] as $l) {
            $rid = $l['reseller_order_id'];
            $st = '—';
            if (isset($done[$rid])) {
                $d  = $done[$rid];
                $bad = strpos((string)($d['status_code'] ?? ''), 'failed') === 0;
                $st = '<span style="color:' . ($bad ? '#b32d2e' : '#008a20') . '">'
                    . esc_html($d['status'] ?: 'отправлено') . '</span>';
                if (!empty($d['poizon_no'])) { $st .= '<br><small>Poizon ' . esc_html($d['poizon_no']) . ' · ' . esc_html($d['platform']) . '</small>'; }
                if (!empty($d['total_rub'])) { $st .= '<br><small>факт ' . esc_html($d['price_cny']) . ' ¥ · итого ' . esc_html($d['total_rub']) . ' ₽</small>'; }
                $st .= '<br><small style="color:#666">' . esc_html($d['at']) . '</small>';
            }
            echo '<tr><td><code>' . esc_html($rid) . '</code></td><td><code>' . esc_html($l['sku_id']) . '</code></td><td>' . esc_html($l['price_cny']) . ' ¥</td><td>' . $st . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>Нечего отправлять.</p>';
    }

    $url = wp_nonce_url(add_query_arg(['leybo_rcn_send' => $order->get_id()]), 'leybo_rcn_send_' . $order->get_id());
    echo '<p style="margin-top:12px"><a href="' . esc_url($url) . '" class="button button-primary">Отправить в Ракету</a> ';
    $furl = wp_nonce_url(add_query_arg(['leybo_rcn_send' => $order->get_id(), 'force' => 1]), 'leybo_rcn_send_' . $order->get_id());
    echo '<a href="' . esc_url($furl) . '" class="button">Отправить заново (включая уже отправленные)</a> ';
    $rurl = wp_nonce_url(add_query_arg(['leybo_rcn_refresh' => $order->get_id()]), 'leybo_rcn_send_' . $order->get_id());
    echo '<a href="' . esc_url($rurl) . '" class="button">Обновить статусы</a> ';
    $l2 = wp_nonce_url(add_query_arg(['leybo_rcn_r2' => $order->get_id()]), 'leybo_rcn_send_' . $order->get_id());
    echo '<a href="' . esc_url($l2) . '" class="button">Данные для Ракета 2.0</a></p>';

    // Выкуп и доставка у них — разные шаги: POST /orders покупает пару на Poizon,
    // а логистика живёт в raketa2-data. Показываем её рядом, чтобы не искать в кабинете.
    $r2 = $order->get_meta('_leybo_rcn_raketa2');
    if ($r2) {
        echo '<details open style="margin-top:10px"><summary><strong>Данные для Ракета 2.0 (логистика)</strong></summary>'
           . '<pre style="max-height:300px;overflow:auto;background:#f6f7f7;padding:10px;font-size:11px">'
           . esc_html(wp_json_encode($r2, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
           . '</pre></details>';
    }

    $log = $order->get_meta('_leybo_rcn_log');
    if (is_array($log) && $log) {
        echo '<details style="margin-top:10px"><summary>Лог обмена (' . count($log) . ')</summary><pre style="max-height:340px;overflow:auto;background:#f6f7f7;padding:10px;font-size:11px">'
           . esc_html(wp_json_encode(array_reverse($log), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
           . '</pre></details>';
    }
}

add_action('admin_init', function () {
    $send    = (int)($_GET['leybo_rcn_send']    ?? 0);
    $refresh = (int)($_GET['leybo_rcn_refresh'] ?? 0);
    $r2      = (int)($_GET['leybo_rcn_r2']      ?? 0);
    if (!$send && !$refresh && !$r2) { return; }
    if (!current_user_can('manage_woocommerce')) { return; }

    $id = $send ?: ($refresh ?: $r2);
    check_admin_referer('leybo_rcn_send_' . $id);

    if ($send)          { leybo_rcn_send($id, !empty($_GET['force'])); }
    elseif ($refresh)   { leybo_rcn_refresh($id); }
    else {
        $order = wc_get_order($id);
        $done  = $order ? $order->get_meta('_leybo_rcn_done') : [];
        $bag   = [];
        foreach (array_keys(is_array($done) ? $done : []) as $rid) {
            $res = leybo_rcn_raketa2($rid);
            $bag[$rid] = $res['ok'] ? $res['body'] : ($res['error'] ?: ('HTTP ' . $res['http']));
        }
        if (!$bag) { $bag = ['—' => 'Заявки ещё не уходили в Ракету, запрашивать нечего.']; }
        if ($order) { $order->update_meta_data('_leybo_rcn_raketa2', $bag); $order->save(); }
    }

    wp_safe_redirect(remove_query_arg(['leybo_rcn_send', 'leybo_rcn_refresh', 'leybo_rcn_r2', 'force', '_wpnonce']));
    exit;
});

/* ----------------------------------------------------------- admin: settings */

// Своё место в меню больше не занимаем: настройки Ракеты живут вкладкой
// в панели ЛЕЙБО (leybo-admin.php). Два разных пункта меню с одним и тем же
// содержимым — верный способ запутать заказчика.
// Фоллбэк: если панель не загружена, старая страница возвращается.
add_action('admin_menu', function () {
    if (function_exists('leybo_admin_page')) { return; }
    add_submenu_page('woocommerce', 'Ракета (reseller)', 'Ракета', 'manage_woocommerce',
        'leybo-rcn', 'leybo_rcn_settings_page');
}, 11);

function leybo_rcn_settings_page() {
    if (!current_user_can('manage_woocommerce')) { return; }

    if (!empty($_POST['leybo_rcn_save'])) {
        check_admin_referer('leybo_rcn_settings');
        update_option('leybo_rcn_token', sanitize_text_field(wp_unslash($_POST['token'])));
        update_option('leybo_rcn_mode', $_POST['mode'] === 'live' ? 'live' : 'dry');
        echo '<div class="notice notice-success"><p>Сохранено.</p></div>';
    }

    $token = (string)leybo_rcn_opt('token');
    $mode  = leybo_rcn_opt('mode', 'dry');
    $mask  = $token === '' ? '' : substr($token, 0, 6) . str_repeat('•', 12) . substr($token, -4);

    echo '<div class="wrap"><h1>Ракета — reseller.raketacn.ru</h1>';

    $bal = leybo_rcn_balance();
    echo '<p><strong>Связь:</strong> ' . ($bal['ok']
        ? 'ок, баланс ' . esc_html(isset($bal['body']['data']['balance']) ? $bal['body']['data']['balance'] : '?') . ' ' . esc_html(isset($bal['body']['data']['currency']) ? $bal['body']['data']['currency'] : '')
        : '<span style="color:#b32d2e">ошибка: ' . esc_html($bal['error'] ?: ('HTTP ' . $bal['http'])) . '</span>') . '</p>';

    echo '<form method="post">';
    wp_nonce_field('leybo_rcn_settings');
    echo '<table class="form-table"><tr><th>API-токен</th><td>';
    echo '<input type="text" name="token" value="' . esc_attr($token) . '" class="regular-text" autocomplete="off">';
    if ($mask) { echo '<p class="description">Сейчас: <code>' . esc_html($mask) . '</code></p>'; }
    echo '</td></tr><tr><th>Режим</th><td>';
    echo '<label><input type="radio" name="mode" value="dry"' . checked($mode, 'dry', false) . '> dry-run — заявка собирается и логируется, наружу не уходит</label><br>';
    echo '<label><input type="radio" name="mode" value="live"' . checked($mode, 'live', false) . '> live — заявки реально уходят в Ракету</label>';
    echo '</td></tr></table>';
    echo '<p><button class="button button-primary" name="leybo_rcn_save" value="1">Сохранить</button></p></form>';

    if (function_exists('leybo_testpay_key')) {
        $link = home_url('/?leybo_test=' . leybo_testpay_key());
        echo '<h2>Тестовая покупка</h2>';
        echo '<p>Открой эту ссылку — на оформлении появится метод «Тестовая оплата». Деньги не списываются, заказ помечается оплаченным и уходит в Ракету по правилам режима выше. Ссылку можно дать Севе. Посторонние без неё платёжных методов не увидят.</p>';
        echo '<p><input type="text" readonly class="large-text code" value="' . esc_attr($link) . '" onclick="this.select()"></p>';
        echo '<p class="description">Выйти из тестового режима: <code>' . esc_html(home_url('/?leybo_test=off')) . '</code></p>';
    }

    echo '<h2>Что умеет их API (снято с живого контура ' . esc_html(date_i18n('d.m.Y')) . ')</h2>';
    echo '<pre style="background:#f6f7f7;padding:10px">POST /orders                 создать ВЫКУП  (reseller_order_id, sku_id, price_cny)
GET  /orders                 список   (?status=paid&per_page=20)
GET  /orders/{наш_id}        детали + status_history
GET  /orders/{наш_id}/raketa2-data   данные для Ракета 2.0 (логистика)
POST /orders/return-request  инициировать возврат
GET  /balance                баланс

Это API ВЫКУПА на Poizon, не доставки — поэтому в нём нет адреса
и ФИО. Сначала пробуется Poizon FAST, при нехватке средств — Global.
sku_id = skuId dewu (проверено на живом запросе).
Запросы ищутся по НАШЕМУ reseller_order_id, чужой uuid хранить не надо.

При нехватке денег выкуп НЕ создаётся — заявку можно повторить после пополнения.
Если цена на Poizon ушла вверх — status_code failed_higher_dmc, в ответе
приходят текущие рыночные цены FAST/GLOBAL, решение за человеком.</pre>';
    echo '</div>';
}
