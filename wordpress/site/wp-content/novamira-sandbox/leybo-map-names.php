<?php
/**
 * Second pass at the catalogue mapping.
 *
 * The first pass matched article codes and got 213 of 473. The rest fail for one
 * reason: the shop product carries no article at all, so the only thing left to
 * compare is the name - and the catalogue names colours in Russian ("Бежевый /
 * Белый") while the shop names them in English ("'Beige White'"). Comparing
 * those directly is how the earlier attempt scored 0 correct out of 123.
 *
 * So ask dewu instead. For a given article it returns its own English name,
 * colour included, which is the same language the shop titles are written in.
 * That turns an impossible cross-language guess into an ordinary string match.
 *
 * A match still has to earn it: the model has to be fully present, the colour
 * has to overlap, and the winner has to be clearly ahead of the runner-up. Eight
 * Samba OG colourways sit on this site under nearly identical titles, and a
 * confident-looking near-tie there is exactly how the wrong shoe gets bought.
 */

if (!defined('ABSPATH')) { exit; }

/* ------------------------------------------------------- dewu names, cached */

function leybo_map_names() {
    $n = get_option('leybo_map_names', []);
    return is_array($n) ? $n : [];
}

/** Catalogue keys that still have no product. */
function leybo_map_unmapped() {
    $cat  = leybo_map_catalog();
    $have = [];
    foreach (leybo_mapped_products() as $a => $pid) {
        $have[preg_replace('/[^A-Z0-9]/', '', strtoupper($a))] = true;
    }
    $out = [];
    foreach ($cat as $key => $it) {
        if (!isset($have[$key])) { $out[$key] = $it; }
    }
    return $out;
}

/**
 * Asks dewu for the English name of the next few unmapped articles.
 * One article per call to leyboapi, ~8 s each, so this is cranked in slices.
 */
function leybo_map_fetch_names($limit = 6) {
    $names = leybo_map_names();
    $done  = 0;
    foreach (leybo_map_unmapped() as $key => $it) {
        if (isset($names[$key])) { continue; }
        if ($done >= $limit) { break; }

        $d = function_exists('leybo_rcn_lookup') ? leybo_rcn_lookup($it['article']) : [];
        $names[$key] = [
            'article' => $it['article'],
            'brand'   => isset($it['brand']) ? $it['brand'] : '',
            'model'   => isset($it['model']) ? $it['model'] : '',
            'color_ru'=> isset($it['color']) ? $it['color'] : '',
            'found'   => !empty($d['found']),
            'name'    => isset($d['name'])  ? $d['name']  : '',
            'title'   => isset($d['title']) ? $d['title'] : '',
            'photo'   => isset($d['photo']) ? $d['photo'] : '',
            'spu'     => isset($d['spu'])   ? $d['spu']   : null,
        ];
        $done++;
    }
    update_option('leybo_map_names', $names, false);

    $left = 0;
    foreach (leybo_map_unmapped() as $key => $it) { if (!isset($names[$key])) { $left++; } }
    return ['спрошено' => $done, 'всего_имён' => count($names), 'осталось' => $left];
}

/* -------------------------------------------------------------- the matching */

/** Words that carry no identity and would inflate every score. */
function leybo_map_stopwords() {
    return ['originals' => 1, 'the' => 1, 'and' => 1, 'shoes' => 1, 'shoe' => 1,
            'unisex' => 1, 'men' => 1, 'mens' => 1, 'women' => 1, 'womens' => 1,
            's' => 1, 'casual' => 1, 'sneakers' => 1, 'retro' => 1, 'low' => 1,
            'high' => 1, 'hi' => 1, 'top' => 1, 'wear' => 1, 'resistant' => 1,
            'comfortable' => 1, 'fashion' => 1, 'lifestyle' => 1, 'versatile' => 1,
            'thin' => 1, 'sole' => 1, 'anti' => 1, 'slip' => 1, 'series' => 1,
            'collection' => 1, 'outdoor' => 1, 'warm' => 1, 'abrasion' => 1];
}

function leybo_map_tokens($s, $drop_stop = true) {
    $s = mb_strtolower(html_entity_decode((string)$s), 'UTF-8');
    $s = preg_replace('/[^a-z0-9\s]/u', ' ', $s);
    $stop = leybo_map_stopwords();
    $out = [];
    foreach (preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY) as $w) {
        if ($drop_stop && isset($stop[$w])) { continue; }
        $out[$w] = 1;
    }
    return $out;
}

/** Unmapped shop products, tokenised once. */
function leybo_map_free_products() {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT p.ID, p.post_title FROM {$wpdb->posts} p
        WHERE p.post_type='product' AND p.post_status='publish'
        AND p.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta}
                         WHERE meta_key='_leybo_article' AND meta_value<>'')", ARRAY_A);
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int)$r['ID'], 'title' => html_entity_decode($r['post_title']),
                  'tok' => leybo_map_tokens($r['post_title'])];
    }
    return $out;
}

/**
 * Scores one catalogue article against every free product.
 * @return array ranked candidates
 */
/**
 * Every word that names a brand or a model anywhere in the catalogue.
 *
 * Subtracting one entry's own model from its dewu name was not enough: "NB" is
 * absent from the model "9060", so it counted as a colour and matched the "NB"
 * in the shop title, scoring a perfect colour match that meant nothing. A word
 * that names a model ANYWHERE cannot be a colour ANYWHERE.
 */
function leybo_map_model_vocab() {
    static $v = null;
    if ($v !== null) { return $v; }
    $v = [];
    foreach (leybo_map_catalog() as $it) {
        foreach (leybo_map_tokens($it['brand'] . ' ' . $it['model']) as $w => $_) { $v[$w] = 1; }
    }
    return $v;
}

/** Words that actually name a colour in these titles. */
function leybo_map_colour_words() {
    static $c = null;
    if ($c !== null) { return $c; }
    $list = 'white black grey gray red blue green yellow brown pink purple orange beige cream '
          . 'navy olive silver gold tan ivory burgundy wine khaki mint lilac turquoise denim '
          . 'sail bone gum smoke charcoal mustard coral teal violet rose sand stone bordeaux '
          . 'magenta aqua lime peach apricot chocolate coffee mocha sage pollen concord panda '
          . 'oat wheat plum berry eclipse olivine birch cargo taxi stealth crimson scarlet '
          . 'emerald sapphire amber bronze copper platinum pearl smoky dark light deep pale '
          . 'cherry lemon lavender indigo maroon rust ecru linen chalk ash slate graphite '
          . 'anthracite fuchsia salmon nude camel walnut espresso ink jade ruby amethyst';
    $c = array_fill_keys(preg_split('/\s+/', $list), 1);
    return $c;
}

function leybo_map_rank($entry, $products) {
    $model_tok = leybo_map_tokens($entry['brand'] . ' ' . $entry['model']);
    $name_tok  = leybo_map_tokens($entry['name']);
    if (!$model_tok) { return []; }
    $vocab = leybo_map_model_vocab();

    // Colour is whatever dewu says BEYOND the model name. Scoring the whole
    // string together was a trap: for an article dewu names plainly ("Nike Dunk
    // LOW RETRO") every token is a model token, so any Dunk on the site scored a
    // perfect 1.0 while saying nothing at all about colour. JH5632 - black and
    // pink in the catalogue - was about to be linked to a Black Brown Samba on
    // exactly such a score.
    // Only actual colour words count. Subtracting the model vocabulary still let
    // "NB" through as a colour, and a partial overlap scored "Black White" against
    // "Black Blue" as a perfect match. Comparing colour SETS makes both impossible.
    $col_need = array_intersect_key($name_tok, leybo_map_colour_words());

    $ranked = [];
    foreach ($products as $p) {
        $mhit = 0;
        foreach ($model_tok as $w => $_) { if (isset($p['tok'][$w])) { $mhit++; } }
        if ($mhit / count($model_tok) < 0.99) { continue; }   // wrong model entirely

        $col_have = array_intersect_key($p['tok'], leybo_map_colour_words());

        // no colour named on dewu's side is no evidence, not perfect evidence
        if (!$col_need || !$col_have) {
            $ranked[] = ['id' => $p['id'], 'title' => $p['title'], 'score' => 0.0,
                         'dewu_colours' => implode(' ', array_keys($col_need)),
                         'shop_colours' => implode(' ', array_keys($col_have))];
            continue;
        }
        $both  = count(array_intersect_key($col_need, $col_have));
        $union = count($col_need + $col_have);
        $ranked[] = ['id' => $p['id'], 'title' => $p['title'],
                     'score' => round($both / $union, 3),   // 1.0 only when the sets are identical
                     'dewu_colours' => implode(' ', array_keys($col_need)),
                     'shop_colours' => implode(' ', array_keys($col_have))];
    }
    usort($ranked, function ($a, $b) { return $b['score'] <=> $a['score']; });
    return $ranked;
}

/**
 * Full report: what would be linked, what is too close to call, what has no
 * candidate at all. Writes nothing.
 */
function leybo_map_names_report($accept = 0.6, $margin = 0.15) {
    $names    = leybo_map_names();
    $products = leybo_map_free_products();

    $sure = $tie = $nothing = [];
    $taken = [];

    foreach ($names as $key => $e) {
        $ranked = leybo_map_rank($e, $products);
        if (!$ranked) { $nothing[] = $e['article'] . ' ' . $e['brand'] . ' ' . $e['model']; continue; }

        $best = $ranked[0];
        $second = isset($ranked[1]) ? $ranked[1]['score'] : 0;

        if ($best['score'] >= $accept && ($best['score'] - $second) >= $margin) {
            $sure[$key] = ['article' => $e['article'], 'dewu' => $e['name'],
                           'pid' => $best['id'], 'title' => $best['title'],
                           'score' => $best['score'], 'second' => $second];
            $taken[$best['id']][] = $e['article'];
        } else {
            $tie[] = ['article' => $e['article'], 'dewu' => $e['name'],
                      'top' => array_slice($ranked, 0, 3)];
        }
    }

    // two articles claiming one product is a tie in disguise
    $clash = [];
    foreach ($taken as $pid => $arts) {
        if (count($arts) > 1) { $clash[$pid] = $arts; }
    }
    foreach ($clash as $pid => $arts) {
        foreach ($arts as $a) {
            foreach ($sure as $k => $s) { if ($s['article'] === $a) { unset($sure[$k]); } }
        }
    }

    return ['имён_собрано' => count($names), 'свободных_товаров' => count($products),
            'уверенно' => count($sure), 'спорно' => count($tie),
            'без_кандидатов' => count($nothing), 'конфликтов_снято' => count($clash),
            'sure' => $sure, 'tie' => $tie, 'nothing' => $nothing, 'clash' => $clash];
}

/**
 * Global assignment instead of per-article winners.
 *
 * Scoring each article on its own makes eight Samba OG colourways all look like
 * ties, because they compete for the same handful of shop products. Handing the
 * product to the strongest pair and pushing the rest to their next choice is the
 * same problem a seating chart solves - and it turns most of those ties into
 * clean answers.
 *
 * Greedy by score, one product per article and one article per product.
 */
function leybo_map_assign($min_score = 0.45) {
    $names    = leybo_map_names();
    $products = leybo_map_free_products();

    $pairs = [];
    foreach ($names as $key => $e) {
        foreach (leybo_map_rank($e, $products) as $c) {
            if ($c['score'] < $min_score) { continue; }
            $pairs[] = ['key' => $key, 'article' => $e['article'], 'dewu' => $e['name'],
                        'brand' => $e['brand'], 'model' => $e['model'], 'color_ru' => $e['color_ru'],
                        'pid' => $c['id'], 'title' => $c['title'], 'score' => $c['score']];
        }
    }
    usort($pairs, function ($a, $b) { return $b['score'] <=> $a['score']; });

    $usedA = $usedP = $assign = [];
    foreach ($pairs as $p) {
        if (isset($usedA[$p['key']]) || isset($usedP[$p['pid']])) { continue; }
        $usedA[$p['key']] = true;
        $usedP[$p['pid']] = true;
        $assign[$p['key']] = $p;
    }

    $strong = $medium = $weak = [];
    foreach ($assign as $k => $a) {
        if ($a['score'] >= 0.8)      { $strong[$k] = $a; }
        elseif ($a['score'] >= 0.6)  { $medium[$k] = $a; }
        else                         { $weak[$k]   = $a; }
    }
    $left = [];
    foreach ($names as $k => $e) {
        if (!isset($assign[$k])) { $left[] = $e['article'] . ' ' . $e['brand'] . ' ' . $e['model'] . ' (' . $e['color_ru'] . ')'; }
    }

    return ['strong' => $strong, 'medium' => $medium, 'weak' => $weak, 'left' => $left];
}

/** Writes an assignment bucket. */
function leybo_map_assign_apply($bucket, $dry = true) {
    $a = leybo_map_assign();
    if (!isset($a[$bucket])) { return ['error' => 'нет такой корзины']; }
    $n = 0; $list = [];
    $cat = leybo_map_catalog();
    foreach ($a[$bucket] as $key => $row) {
        $k = isset($cat[$key]['k']) ? $cat[$key]['k'] : 0;
        if (!$dry) {
            update_post_meta($row['pid'], '_leybo_article', $row['article']);
            if ($k) { update_post_meta($row['pid'], '_leybo_k', $k); }
            delete_post_meta($row['pid'], '_leybo_sync_attempt');
        }
        $n++;
        if (count($list) < 10) { $list[] = $row['article'] . ' -> ' . $row['title']; }
    }
    return ['dry' => $dry, 'корзина' => $bucket, 'записано' => $n, 'примеры' => $list];
}

/* ------------------------------------------------------------------- crank */

add_action('rest_api_init', function () {
    register_rest_route('leybo/v1', '/names', [
        'methods'  => 'GET',
        'callback' => function (WP_REST_Request $req) {
            @set_time_limit(300);
            $limit = max(1, min(12, (int)$req->get_param('limit') ?: 6));
            return leybo_map_fetch_names($limit);
        },
        'permission_callback' => function (WP_REST_Request $req) {
            $g = (string)$req->get_param('key');
            return $g !== '' && function_exists('leybo_sync_key') && hash_equals(leybo_sync_key(), $g);
        },
    ]);
});
