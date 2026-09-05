<?php
/**
 * Единая формула цены ЛЕЙБО.
 *
 * Была зашита внутрь синка (`leybo_price_from`) и знала только курс и
 * коэффициент. Двух вещей ей не хватало:
 *
 * 1. **Агентских Ракеты.** Замер на живом API: пара за 215 ¥ обходится в
 *    2801.39 ₽, то есть 13.03 ₽ за юань, а формула считала по 11.3916 —
 *    себестоимость занижена примерно на 14%, и коэффициент 1.65 на деле давал
 *    наценку ~1.44. Теперь надбавка вынесена отдельной настройкой, чтобы было
 *    видно, сколько стоит выкуп, а сколько зарабатываем мы.
 * 2. **Скидки на товар.** Заказчику нужно уметь опускать цену точечно, не
 *    трогая коэффициент.
 *
 * По умолчанию надбавка 0% и скидок нет — поведение сайта не меняется, пока
 * заказчик сам не выставит значения в панели.
 */

if (!defined('ABSPATH')) { exit; }

const LEYBO_META_DISCOUNT = '_leybo_discount';   // процент скидки на товар
const LEYBO_OPT_AGENT_FEE = 'leybo_agent_fee';   // надбавка Ракеты, %

function leybo_pricing_settings() {
    return [
        'rate'      => (float)get_option('leybo_rate', 11.3916),
        'agent_fee' => (float)get_option(LEYBO_OPT_AGENT_FEE, 0),
        'round_to'  => max(1, (int)get_option('leybo_round_to', 100)),
    ];
}

/** Во что пара обходится нам: юани по курсу плюс работа Ракеты. */
function leybo_cost_rub($cny) {
    $s = leybo_pricing_settings();
    return (float)$cny * $s['rate'] * (1 + $s['agent_fee'] / 100);
}

/** Скидка на товар в процентах (0, если не задана). */
function leybo_product_discount($product_id) {
    $d = get_post_meta($product_id, LEYBO_META_DISCOUNT, true);
    $d = (float)$d;
    return ($d > 0 && $d < 100) ? $d : 0.0;
}

/**
 * Итоговая цена на витрине.
 *
 * @param float $cny  цена пары на площадке
 * @param float $k    коэффициент наценки товара
 * @param int   $pid  товар — нужен только чтобы подтянуть скидку
 */
function leybo_retail_price($cny, $k, $product_id = 0) {
    $s = leybo_pricing_settings();
    if ($cny <= 0 || $k <= 0) { return 0; }

    $price = leybo_cost_rub($cny) * $k;
    if ($product_id) {
        $d = leybo_product_discount($product_id);
        if ($d) { $price *= (1 - $d / 100); }
    }
    return (int)(round($price / $s['round_to']) * $s['round_to']);
}

/**
 * Разбор цены для панели: во что обошлось, за сколько продаём, сколько на этом
 * зарабатываем. Показываем заказчику именно эти три числа — по одной итоговой
 * цене не видно, сколько из неё съедает выкуп.
 */
function leybo_price_breakdown($cny, $k, $product_id = 0) {
    $cost   = leybo_cost_rub($cny);
    $retail = leybo_retail_price($cny, $k, $product_id);
    $margin = $retail - $cost;
    return [
        'cny'      => (float)$cny,
        'cost'     => round($cost),
        'retail'   => $retail,
        'discount' => $product_id ? leybo_product_discount($product_id) : 0,
        'margin'   => round($margin),
        'margin_pct' => $cost > 0 ? round($margin / $cost * 100) : 0,
    ];
}
