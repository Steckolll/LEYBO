<?php
/**
 * LEYBO price & stock sync.
 *
 * Pulls the live dewu purchase price and per-size availability from
 * leyboapi.vastubase.store twice a day and writes them onto the products that
 * carry a _leybo_article meta.
 *
 * Price = CNY x rate x K, rounded to the nearest 100 RUB, where the rate is the
 * CBR rate plus 4% (currency risk + agent fee) and K is the per-product markup
 * from Kirill's catalogue spreadsheet.
 *
 * Sizes are the delicate part: dewu writes EU thirds (36 2/3, 37 1/3) while the
 * shop mostly uses halves (36.5, 37.5). They are the same physical size, so a
 * lookup table maps between them - and any size that cannot be mapped with
 * confidence is left untouched rather than guessed into "out of stock".
 */

if (!defined('ABSPATH')) { exit; }

// The legacy /products endpoint went dark: it still answers 200 but every row
// comes back confirmed:false, price_cny:null, sizes:[] - so a "successful" pass
// updated exactly nothing and the shop kept serving month-old prices with every
// size marked in stock. /v1 answers properly for the same articles.
const LEYBO_API = 'https://leyboapi.vastubase.store/v1/products';
const LEYBO_BATCH = 10;   // ~8s per uncached article; keep a batch under the timeout
const LEYBO_BUDGET = 10;  // dewu pages this API call may read
const LEYBO_FRESH_FOR = 39600;  // 11 h - must exceed the duration of a full pass

function leybo_api_key() {
    return get_option('leybo_api_key', '');
}

/** dewu's EU thirds -> the half-size labels the shop uses. */
function leybo_size_table() {
    return [
        '35 2/3' => '35.5', '36 2/3' => '36.5', '38 2/3' => '38.5', '40 2/3' => '40.5',
        '42 2/3' => '42.5', '44 2/3' => '44.5', '46 2/3' => '46.5', '48 2/3' => '48.5',
        '37 1/3' => '37.5', '39 1/3' => '39',   '41 1/3' => '41',   '43 1/3' => '43',
        '45 1/3' => '45',   '47 1/3' => '47.5', '49 1/3' => '49',   '51 1/3' => '51',
    ];
}

/** Normalise any size label to a comparable string. */
function leybo_norm_size($s) {
    $s = trim((string)$s);
    $s = str_replace(['⅔', '⅓'], [' 2/3', ' 1/3'], $s);
    $s = preg_replace('/\\s+/', ' ', $s);
    $s = str_replace(',', '.', $s);
    return $s;
}

/**
 * Every label under which a dewu size may appear in the shop.
 * Returns a set keyed by normalised label.
 */
function leybo_size_keys($dewu_sizes) {
    $tbl = leybo_size_table();
    $keys = [];
    foreach ((array)$dewu_sizes as $s) {
        $n = leybo_norm_size($s);
        $keys[$n] = true;
        if (isset($tbl[$n])) { $keys[$tbl[$n]] = true; }
        // "36.0" and "36" are the same shelf
        if (preg_match('/^(\\d+)\\.0$/', $n, $m)) { $keys[$m[1]] = true; }
    }
    return $keys;
}

/** Variation size as a normalised label, or null when it has no size attribute. */
function leybo_variation_size($variation) {
    $attrs = $variation->get_attributes();
    if (empty($attrs['pa_razmer'])) { return null; }
    $term = get_term_by('slug', $attrs['pa_razmer'], 'pa_razmer');
    $name = $term ? $term->name : str_replace('-', '.', $attrs['pa_razmer']);
    return leybo_norm_size($name);
}

/**
 * Считает leybo-pricing.php — там же живут надбавка Ракеты и скидки.
 * Формула обязана быть ОДНА на весь проект: если синк считает по-своему,
 * он на ближайшем же проходе затрёт всё, что заказчик настроил в панели.
 */
function leybo_price_from($cny, $k, $product_id = 0) {
    if (function_exists('leybo_retail_price')) {
        return leybo_retail_price($cny, $k, $product_id);
    }
    $rate = (float)get_option('leybo_rate', 11.3916);
    $step = (int)get_option('leybo_round_to', 100);
    if ($step < 1) { $step = 1; }
    return (int)(round($cny * $rate * $k / $step) * $step);
}

/** All products we are allowed to touch: article -> product id. */
function leybo_mapped_products() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key='_leybo_article'",
        ARRAY_A);
    $map = [];
    foreach ($rows as $r) {
        $a = strtoupper(trim($r['meta_value']));
        if ($a !== '') { $map[$a] = (int)$r['post_id']; }
    }
    return $map;
}

/**
 * Reads /v1 and hands back the shape the sync loop below already understands:
 * a flat `sizes` list of the sizes that are actually buyable, plus a scalar
 * price_cny. Adapting here rather than rewriting the loop keeps the delicate
 * size-matching and variation-saving code untouched.
 *
 * `confirmed` is synthesised as true on purpose: /v1 only ever returns an
 * article -> spu pair once the article printed on the dewu page matched the one
 * we asked for, which is a stronger guarantee than the old flag ever was.
 */
function leybo_fetch($articles) {
    $url = LEYBO_API . '?articles=' . rawurlencode(implode(',', $articles))
         . '&budget=' . LEYBO_BUDGET;
    $res = wp_remote_get($url, [
        'timeout' => 120,
        'headers' => ['x-api-key' => leybo_api_key()],
    ]);
    if (is_wp_error($res)) { return ['error' => $res->get_error_message()]; }
    $code = wp_remote_retrieve_response_code($res);
    if ($code !== 200) { return ['error' => 'HTTP ' . $code]; }
    $body = json_decode(wp_remote_retrieve_body($res), true);
    if (!is_array($body)) { return ['error' => 'bad json']; }

    $found = [];
    foreach ((array)(isset($body['found']) ? $body['found'] : []) as $r) {
        if (empty($r['found'])) { continue; }
        $cny = isset($r['price']['cny']) ? (float)$r['price']['cny'] : 0.0;
        if ($cny <= 0) { continue; }

        $sizes = [];
        $size_prices = [];
        foreach ((array)(isset($r['sizes']) ? $r['sizes'] : []) as $s) {
            if (!empty($s['available'])) { $sizes[] = (string)$s['size']; }
            // Цена размера у перепродавца: в рублях и с его наценкой, поэтому
            // годится только на ОТНОШЕНИЕ между размерами — то есть ровно на
            // профиль. Приходит в том же ответе, лишних запросов не стоит.
            $ref = isset($s['price_rub_ref']) ? (float)$s['price_rub_ref'] : 0.0;
            if ($ref > 0 && isset($s['size'])) { $size_prices[(string)$s['size']] = $ref; }
        }

        $found[] = [
            'article'     => isset($r['article']) ? $r['article'] : '',
            'confirmed'   => true,
            'price_cny'   => $cny,
            'sizes'       => $sizes,
            'size_prices' => $size_prices,
            'stale'       => !empty($r['price']['stale']),
        ];
    }
    return ['found' => $found];
}

/**
 * @param bool $dry when true, compute and report but write nothing.
 */
function leybo_sync_run($dry = false, $offset = 0, $limit = 0) {
    $map = leybo_mapped_products();
    if (!$map) { return ['error' => 'no mapped products']; }
    // A full pass over ~150 products x ~20 variations outruns the 120s request
    // ceiling, and WP-CLI is unusable here (RusToLat fatals under PHP 8.2 in CLI),
    // so the work is driven in slices instead.
    ksort($map);
    // $offset < 0 means "only what has not been synced in the last hour" - lets an
    // interrupted pass be resumed without redoing the expensive variation saves.
    // A full pass over 200+ articles takes well over an hour, so a one-hour
    // freshness window made the queue chase its own tail: rows synced at the
    // start went stale again before the end and came back round. The window has
    // to be wider than a pass, and 11 h also matches the twice-daily schedule.
    if ($offset < 0) {
        $cut = strtotime(current_time('mysql')) - LEYBO_FRESH_FOR;
        // Keyed on the ATTEMPT, not on success. An article dewu no longer knows
        // can never succeed, and keying on success left those rows unmarked at
        // the head of the queue forever - a pass would pick the same four dead
        // articles every time and never reach the rest.
        foreach ($map as $a => $pid) {
            $t = get_post_meta($pid, '_leybo_sync_attempt', true);
            if (!$t) { $t = get_post_meta($pid, '_leybo_synced', true); }
            if ($t && strtotime($t) > $cut) { unset($map[$a]); }
        }
        if ($limit) { $map = array_slice($map, 0, (int)$limit, true); }
    } elseif ($offset || $limit) {
        $map = array_slice($map, (int)$offset, $limit ? (int)$limit : null, true);
    }

    $log = [
        'started' => current_time('mysql'), 'dry' => $dry,
        'products' => 0, 'price_changed' => 0, 'sizes_off' => 0, 'sizes_on' => 0,
        'not_found' => [], 'errors' => [], 'changes' => [],
    ];

    foreach (array_chunk(array_keys($map), LEYBO_BATCH, true) as $chunk) {
        $data = leybo_fetch($chunk);
        if (!empty($data['error'])) { $log['errors'][] = $data['error']; continue; }
        $found = isset($data['found']) ? $data['found'] : [];
        $seen = [];

        foreach ($found as $row) {
            $art = strtoupper(trim(isset($row['article']) ? $row['article'] : ''));
            $seen[$art] = true;
            if (empty($map[$art])) { continue; }
            $pid = $map[$art];
            $product = wc_get_product($pid);
            if (!$product) { continue; }

            // Only confirmed matches may drive a price. An unconfirmed row means we
            // are not certain the dewu product is this product.
            if (empty($row['confirmed'])) { continue; }
            $cny = isset($row['price_cny']) ? (float)$row['price_cny'] : 0;
            if ($cny <= 0) { continue; }

            $k = (float)get_post_meta($pid, '_leybo_k', true);
            if ($k <= 0) { continue; }
            $price = leybo_price_from($cny, $k, $pid);
            $keys = leybo_size_keys(isset($row['sizes']) ? $row['sizes'] : []);
            $have_sizes = !empty($keys);

            $log['products']++;
            $old = (int)$product->get_price();
            if ($old !== $price) {
                $log['price_changed']++;
                if (count($log['changes']) < 40) {
                    $log['changes'][] = sprintf('%s %s: %d -> %d', $art,
                        mb_substr($product->get_name(), 0, 30), $old, $price);
                }
            }

            // Цена принадлежит РАЗМЕРУ, а не товару. Профиль «размер -> множитель
            // к минимальной цене» снят с цен площадки (leybo-size-price.php).
            // Без него плановый синк ставил одну цену на все размеры и затирал
            // профиль: недобор медианно 37%, ловилось 13.08.2026.
            // Сначала обновляем сам профиль из цен размеров этого же ответа:
            // снимок 07.08 устаревал молча, а источник обновляется каждые полчаса.
            if (!$dry && function_exists('leybo_refresh_size_profile')) {
                $st = leybo_refresh_size_profile($pid, isset($row['size_prices']) ? $row['size_prices'] : []);
                if ($st === 'set') {
                    $log['profile_refreshed'] = (isset($log['profile_refreshed']) ? $log['profile_refreshed'] : 0) + 1;
                }
            }

            $sp_own = json_decode((string) get_post_meta($pid, '_leybo_size_profile', true), true);
            if (!is_array($sp_own)) { $sp_own = []; }
            $sp_common = (array) get_option('leybo_size_profile_common', []);
            $sp_margin = 1 + ((float) get_option('leybo_size_margin', 0)) / 100;
            $sp_ok = function_exists('leybo_size_multiplier') && function_exists('leybo_sp_variation_size');

            foreach ($product->get_children() as $vid) {
                $v = wc_get_product($vid);
                if (!$v) { continue; }
                // модуль профиля не загрузился -> плоская цена как раньше, но не ноль
                $vprice = $price;
                if ($sp_ok) {
                    $mult = leybo_size_multiplier(leybo_sp_variation_size($v), $sp_own, $sp_common);
                    $p2 = leybo_price_from($cny * $mult * $sp_margin, $k, $pid);
                    if ($p2 > 0) {
                        $vprice = $p2;
                        if ($mult != 1.0) {
                            $log['size_priced'] = (isset($log['size_priced']) ? $log['size_priced'] : 0) + 1;
                        }
                    }
                }
                if (!$dry) {
                    $v->set_regular_price($vprice);
                    $v->set_sale_price('');
                    $v->set_price($vprice);
                }
                if ($have_sizes) {
                    $sz = leybo_variation_size($v);
                    // no readable size -> do not guess at its availability
                    if ($sz !== null) {
                        $in = isset($keys[$sz]);
                        $was = $v->get_stock_status();
                        if ($in && $was !== 'instock') { $log['sizes_on']++; }
                        if (!$in && $was !== 'outofstock') { $log['sizes_off']++; }
                        if (!$dry) {
                            $v->set_manage_stock(false);
                            $v->set_stock_status($in ? 'instock' : 'outofstock');
                        }
                    }
                }
                if (!$dry) { $v->save(); }
            }

            if (!$dry) {
                update_post_meta($pid, '_leybo_cny', $cny);
                update_post_meta($pid, '_leybo_synced', current_time('mysql'));
                $product->set_manage_stock(false);
                $product->set_stock_status($have_sizes ? 'instock' : $product->get_stock_status());
                $product->save();
                // Цена вариаций и цена САМОГО товара — разные вещи: WooCommerce
                // держит для родителя отдельную сводку и без пересчёта отдаёт
                // пустую строку. Товар при этом остаётся в наличии, то есть его
                // можно положить в корзину бесплатно. Ловилось на пяти парах
                // после планового синка.
                WC_Product_Variable::sync($pid, true);
                wc_delete_product_transients($pid);
            }
        }
        foreach ($chunk as $a) {
            if (empty($seen[$a])) { $log['not_found'][] = $a; }
            // every article we actually asked about counts as attempted
            if (!$dry && isset($map[$a])) {
                update_post_meta($map[$a], '_leybo_sync_attempt', current_time('mysql'));
            }
        }
    }

    $log['finished'] = current_time('mysql');
    if (!$dry) {
        update_option('leybo_last_sync', $log, false);
        if (function_exists('wc_delete_shop_order_transients')) { wc_delete_product_transients(); }
    }
    return $log;
}

/* ---- schedule: twice a day ---- */

add_filter('cron_schedules', function ($s) {
    $s['leybo_twice_daily'] = ['interval' => 12 * HOUR_IN_SECONDS, 'display' => 'LEYBO: 2 раза в день'];
    return $s;
});

add_action('leybo_sync_event', function () { leybo_sync_run(false); });

if (!wp_next_scheduled('leybo_sync_event')) {
    wp_schedule_event(time() + 300, 'leybo_twice_daily', 'leybo_sync_event');
}
