<?php
/**
 * Landing page data for the LEYBO front page.
 *
 * The shop had no home page to speak of - two category tiles and nothing else.
 * This feeds the new one.
 *
 * "Hits" are not invented and not an ACF flag (nobody ever set those - the
 * counters are zero). They come from the ХИТ status in Kirill's own catalogue
 * spreadsheet, joined to the products that are actually mapped, in stock and
 * priced. If a pair cannot be bought today it has no business being the first
 * thing a visitor sees.
 */

if (!defined('ABSPATH')) { exit; }

const LEYBO_HOME_HITS = 8;

/** Products carrying a given catalogue status, buyable right now. */
function leybo_home_by_status($status, $limit) {
    if (!function_exists('leybo_map_catalog') || !function_exists('leybo_mapped_products')) {
        return [];
    }
    $cat  = leybo_map_catalog();

    // One pair per model, or the row fills up with eight Sambas: articles sort
    // alphabetically and colourways of the same shoe sit next to each other.
    $seen = [];
    $pool = [];
    foreach (leybo_mapped_products() as $article => $pid) {
        $key = preg_replace('/[^A-Z0-9]/', '', strtoupper($article));
        if (!isset($cat[$key]) || $cat[$key]['status'] !== $status) { continue; }

        $p = wc_get_product($pid);
        if (!$p || $p->get_stock_status() !== 'instock') { continue; }
        if ((int)$p->get_price() <= 0) { continue; }
        if (!$p->get_image_id()) { continue; }   // a card without a photo looks broken

        $model = mb_strtolower(trim($cat[$key]['brand'] . ' ' . $cat[$key]['model']));
        if (isset($seen[$model])) { continue; }
        $seen[$model] = true;
        $pool[] = get_post($pid);
    }

    // Spread the brands out instead of showing all the adidas first.
    $byBrand = [];
    foreach ($pool as $post) {
        $a = strtoupper(trim((string)get_post_meta($post->ID, '_leybo_article', true)));
        $k = preg_replace('/[^A-Z0-9]/', '', $a);
        $byBrand[$cat[$k]['brand'] ?? '?'][] = $post;
    }
    $out = [];
    while (count($out) < $limit && $byBrand) {
        foreach ($byBrand as $brand => $list) {
            if (!$list) { unset($byBrand[$brand]); continue; }
            $out[] = array_shift($byBrand[$brand]);
            if (count($out) >= $limit) { break; }
        }
    }
    return $out;
}

/** Live numbers for the landing - never hard-coded, so they cannot go stale. */
function leybo_home_stats() {
    $cache = get_transient('leybo_home_stats');
    if (is_array($cache)) { return $cache; }

    $models = 0; $pairs = 0;
    if (function_exists('leybo_mapped_products')) {
        foreach (leybo_mapped_products() as $pid) {
            $p = wc_get_product($pid);
            if (!$p || $p->get_stock_status() !== 'instock') { continue; }
            $models++;
            foreach ($p->get_children() as $vid) {
                $v = wc_get_product($vid);
                if ($v && $v->get_stock_status() === 'instock') { $pairs++; }
            }
        }
    }
    $stats = ['models' => $models, 'sizes' => $pairs];
    set_transient('leybo_home_stats', $stats, HOUR_IN_SECONDS);
    return $stats;
}

/**
 * The landing lives on its own page, not on the front page.
 *
 * Bolting it under the two category tiles made one page do two jobs: the front
 * page is the way INTO the shop, the landing explains the service to someone who
 * has never heard of it. Kirill called that out and he is right - they get
 * different visitors and different first screens.
 */
const LEYBO_LANDING_SLUG = 'kak-eto-rabotaet';

function leybo_is_landing() {
    return is_page(LEYBO_LANDING_SLUG);
}

add_filter('timber/context', function ($context) {
    if (!leybo_is_landing()) { return $context; }
    $context['leybo_hits']  = leybo_home_by_status('ХИТ', LEYBO_HOME_HITS);
    $context['leybo_stats'] = leybo_home_stats();
    return $context;
});

add_action('wp_enqueue_scripts', function () {
    if (!leybo_is_landing()) { return; }
    $rel = 'novamira-sandbox/leybo-home.css';
    $abs = WP_CONTENT_DIR . '/' . $rel;
    wp_enqueue_style('leybo-home', content_url($rel), [],
        file_exists($abs) ? filemtime($abs) : null);
}, 99);
