<?php
/**
 * LEYBO catalogue <-> shop mapping.
 *
 * The shop's 853 products were imported from a feed that had been machine
 * translated into Russian - and the translator went through the article codes
 * too, so "IH7653-103" arrives as "ИХ7653-103". Some rows also carry a colour in
 * the same field ("A08136C (Черный)"), some carry an unrelated number, and only
 * 251 products carry the field at all.
 *
 * So the attribute is treated as a *candidate*, never as an answer. A candidate
 * becomes a mapping only when, after undoing the transliteration, it lands on an
 * article that the hand-checked catalogue actually contains. Everything else is
 * reported and left alone: the previous attempt at guessing these links by
 * colour scored 0 correct out of 123, and that mistake is not worth repeating.
 *
 * Defines functions only - nothing runs on load.
 */

if (!defined('ABSPATH')) { exit; }

const LEYBO_MAP_ATTR = 'pa_osnovnoj-nomer-tovara';
const LEYBO_MAP_JSON = __DIR__ . '/leybo-catalog.json';

/** The hand-checked catalogue, keyed by normalised article. */
function leybo_map_catalog() {
    static $c = null;
    if ($c !== null) { return $c; }
    $raw = @file_get_contents(LEYBO_MAP_JSON);
    $arr = $raw ? json_decode($raw, true) : [];
    $c = [];
    foreach ((array)$arr as $it) {
        if (!empty($it['key'])) { $c[$it['key']] = $it; }
    }
    return $c;
}

/**
 * Latin letters a Cyrillic one may have come from.
 *
 * Two mechanisms are folded together on purpose: visual look-alikes (Cyrillic С
 * for Latin C) and honest transliteration (Х for H). Both happened in this feed,
 * and which one applies cannot be told from the character alone - so both are
 * offered and the catalogue decides which was meant.
 */
function leybo_map_cyr_options($ch) {
    static $m = null;
    if ($m === null) {
        $m = [
            'А'=>['A'],      'Б'=>['B'],      'В'=>['B','V'],  'Г'=>['G'],
            'Д'=>['D'],      'Е'=>['E'],      'Ё'=>['E'],      'Ж'=>['J','Z'],
            // К also stands in for Latin C: the translator went by SOUND, not by
            // shape, so CT8013-117 came back as КT8013-117.
            'З'=>['Z','3'],  'И'=>['I','N'],  'Й'=>['I','Y','J'],  'К'=>['K','C'],
            'Л'=>['L'],      'М'=>['M'],      'Н'=>['H','N'],  'О'=>['O','0'],
            'П'=>['P','N'],  'Р'=>['P','R'],  'С'=>['C','S'],  'Т'=>['T'],
            'У'=>['Y','U'],  'Ф'=>['F'],      'Х'=>['X','H'],  'Ц'=>['C'],
            'Ч'=>['C','H'],  'Ш'=>['S','W'],  'Щ'=>['S'],      'Ъ'=>[''],
            'Ы'=>['Y'],      'Ь'=>[''],       'Э'=>['E'],      'Ю'=>['U'],
            'Я'=>['A','R'],
        ];
    }
    return isset($m[$ch]) ? $m[$ch] : null;
}

/**
 * Every plausible Latin reading of one raw field value, normalised.
 * Capped so a value that is mostly Cyrillic cannot explode combinatorially.
 */
function leybo_map_variants($raw, $cap = 64) {
    $s = trim((string)$raw);
    if ($s === '') { return []; }

    // "A08136C (Черный)" and "A08136C Черный" both mean the code, not the colour
    $s = preg_split('/[\(\[]/u', $s)[0];
    $s = mb_strtoupper(trim($s), 'UTF-8');

    $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    $out = [''];
    foreach ($chars as $ch) {
        $opts = leybo_map_cyr_options($ch);
        if ($opts === null) {
            // keep only characters an article code can contain
            $keep = preg_match('/[A-Z0-9]/', $ch) ? $ch : '';
            foreach ($out as $i => $p) { $out[$i] = $p . $keep; }
            continue;
        }
        $next = [];
        foreach ($out as $p) {
            foreach ($opts as $o) {
                $next[] = $p . $o;
                if (count($next) >= $cap) { break 2; }
            }
        }
        $out = $next;
    }
    return array_values(array_unique(array_filter($out, function ($v) { return $v !== ''; })));
}

/** Raw candidate strings a product offers, best source first. */
function leybo_map_candidates($product_id) {
    $out = [];
    foreach (wp_get_object_terms($product_id, LEYBO_MAP_ATTR, ['fields' => 'names']) as $n) {
        $out[] = $n;
    }
    $sku = get_post_meta($product_id, '_sku', true);
    if ($sku !== '') { $out[] = $sku; }
    return $out;
}

/**
 * @return array{article:string,via:string}|null
 */
function leybo_map_match($product_id) {
    $cat = leybo_map_catalog();
    foreach (leybo_map_candidates($product_id) as $raw) {
        foreach (leybo_map_variants($raw) as $v) {
            if (isset($cat[$v])) {
                return ['article' => $cat[$v]['article'], 'k' => $cat[$v]['k'],
                        'key' => $v, 'via' => $raw];
            }
        }
    }
    return null;
}

/**
 * Dry analysis of the whole shop. Writes nothing.
 */
function leybo_map_analyze() {
    global $wpdb;
    $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts}
        WHERE post_type='product' AND post_status='publish' ORDER BY ID");

    $cat      = leybo_map_catalog();
    $hits     = [];   // normalised article -> [product ids]
    $matched  = [];   // product id -> match
    $conflict = [];   // product already mapped to a different article

    foreach ($ids as $pid) {
        $m = leybo_map_match((int)$pid);
        if (!$m) { continue; }
        $matched[$pid]     = $m;
        $hits[$m['key']][] = (int)$pid;

        $cur = strtoupper(trim((string)get_post_meta($pid, '_leybo_article', true)));
        if ($cur !== '' && preg_replace('/[^A-Z0-9]/', '', $cur) !== $m['key']) {
            $conflict[] = $pid . ': ' . $cur . ' -> ' . $m['article'];
        }
    }

    $collisions = [];
    foreach ($hits as $key => $pids) {
        if (count($pids) > 1) { $collisions[$key] = $pids; }
    }

    return [
        'товаров_на_сайте'      => count($ids),
        'позиций_в_каталоге'    => count($cat),
        'сопоставилось_товаров' => count($matched),
        'артикулов_закрыто'     => count($hits),
        'артикулов_не_нашлось'  => count($cat) - count($hits),
        'коллизии'              => $collisions,
        'спорит_с_текущей'      => $conflict,
    ];
}

/**
 * Writes _leybo_article / _leybo_k for unambiguous matches only.
 * Collisions (two products claiming one article) are skipped - a wrong link here
 * means Raketa buys the wrong shoe.
 *
 * @param bool $overwrite replace an existing _leybo_article that disagrees.
 */
function leybo_map_apply($dry = true, $overwrite = false) {
    global $wpdb;
    $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts}
        WHERE post_type='product' AND post_status='publish' ORDER BY ID");

    $hits = [];
    $plan = [];
    foreach ($ids as $pid) {
        $m = leybo_map_match((int)$pid);
        if (!$m) { continue; }
        $hits[$m['key']][] = (int)$pid;
        $plan[(int)$pid]   = $m;
    }

    $written = $skipped_collision = $skipped_existing = 0;
    foreach ($plan as $pid => $m) {
        if (count($hits[$m['key']]) > 1) { $skipped_collision++; continue; }

        $cur = strtoupper(trim((string)get_post_meta($pid, '_leybo_article', true)));
        if ($cur !== '' && !$overwrite
            && preg_replace('/[^A-Z0-9]/', '', $cur) !== $m['key']) {
            $skipped_existing++;
            continue;
        }
        if (!$dry) {
            update_post_meta($pid, '_leybo_article', $m['article']);
            if ($m['k']) { update_post_meta($pid, '_leybo_k', $m['k']); }
        }
        $written++;
    }

    return ['dry' => $dry, 'записано' => $written,
            'пропущено_коллизий' => $skipped_collision,
            'пропущено_спорных'  => $skipped_existing];
}
