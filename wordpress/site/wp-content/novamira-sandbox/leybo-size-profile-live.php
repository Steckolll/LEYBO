<?php
/**
 * Профиль размеров из ЖИВЫХ данных, а не из снимка.
 *
 * До этого множители «размер -> во сколько раз дороже самого дешёвого»
 * брались из разового замера 07.08.2026, записанного в мету. При этом
 * наш же API отдаёт `price_rub_ref` на КАЖДЫЙ размер в том же самом
 * ответе, который синк уже запрашивает — и эти цены обновляются каждые
 * полчаса и достаются бесплатно (источник — перепродавец, без прокси
 * и без чтения страниц dewu). То есть мы жили на старых числах, имея свежие.
 *
 * 🔑 Из `price_rub_ref` берётся ТОЛЬКО ОТНОШЕНИЕ между размерами одного
 * товара. Само число — розница чужого магазина с его наценкой, которая
 * гуляет 10.97–16.39× к нашей юаневой цене, и в юани её не переводят.
 * Отношение же наценку сокращает — проверено на H03472: считанные живьём
 * 1.386 / 1.419 / 1.0 / 1.322 против 1.4362 / 1.4228 / 1.0 / 1.3224 в снимке.
 */

if (!defined('ABSPATH')) { exit; }

const LEYBO_PROFILE_AT_META = '_leybo_size_profile_at';
const LEYBO_PROFILE_MIN_SIZES = 4;       // меньше — выборка ничего не говорит

/**
 * Цены размеров -> профиль множителей.
 *
 * @param array $size_prices [метка размера => цена]
 * @return array|false профиль или false, если данных мало
 */
function leybo_profile_from_sizes($size_prices) {
    $vals = [];
    foreach ((array) $size_prices as $label => $price) {
        $price = (float) $price;
        if ($price > 0 && trim((string) $label) !== '') { $vals[(string) $label] = $price; }
    }
    if (count($vals) < LEYBO_PROFILE_MIN_SIZES) { return false; }

    // Якорь — самый дешёвый размер, поэтому битое маленькое значение
    // раздуло бы ВЕСЬ профиль. Цена ниже четверти медианы — это
    // не редкий размер, а мусор в источнике.
    $sorted = array_values($vals);
    sort($sorted);
    $median = $sorted[intdiv(count($sorted), 2)];
    $floor_guard = $median / 4;
    $clean = [];
    foreach ($vals as $label => $price) {
        if ($price >= $floor_guard) { $clean[$label] = $price; }
    }
    if (count($clean) < LEYBO_PROFILE_MIN_SIZES) { return false; }

    $min = min($clean);
    if ($min <= 0) { return false; }

    $profile = [];
    foreach ($clean as $label => $price) {
        $profile[$label] = round($price / $min, 4);
    }
    return $profile;
}

/**
 * Обновляет профиль товара, если свежие данные говорят другое.
 *
 * Не перезаписывает ради шума: пишем только когда хоть один множитель
 * сдвинулся больше чем на 2%, или появился/ушёл размер.
 *
 * @return string '' не трогали | 'set' записан | 'skip' данных мало
 */
function leybo_refresh_size_profile($pid, $size_prices) {
    $fresh = leybo_profile_from_sizes($size_prices);
    if ($fresh === false) { return 'skip'; }

    $old = json_decode((string) get_post_meta($pid, '_leybo_size_profile', true), true);
    if (!is_array($old)) { $old = []; }

    $changed = count($old) !== count($fresh);
    if (!$changed) {
        foreach ($fresh as $label => $mult) {
            if (!isset($old[$label]) || abs((float) $old[$label] - $mult) > 0.02 * max(1.0, (float) $old[$label])) {
                $changed = true;
                break;
            }
        }
    }
    if (!$changed) { return ''; }

    update_post_meta($pid, '_leybo_size_profile', wp_json_encode($fresh, JSON_UNESCAPED_UNICODE));
    update_post_meta($pid, LEYBO_PROFILE_AT_META, current_time('mysql'));
    return 'set';
}
