<?php
/**
 * Цена по размерам.
 *
 * Раньше все размеры товара стоили одинаково: `leybo_admin_reprice()` ставил
 * одну цену всем вариантам. А на площадке разброс между размерами внутри одного
 * товара — медианно 108%, то есть самый дорогой размер вдвое дороже дешёвого.
 * Мы считали цену от floor-цены (самой дешёвой) и на всех остальных размерах
 * недобирали — медианно 37%.
 *
 * Профиль «размер -> множитель к минимальной цене» снят с цен площадки по
 * каждому SKU. Он относительный, поэтому чужая наценка в нём сокращается;
 * перенос на юани проверен: систематическое смещение -3.0% на 117 товарах.
 * Точность по отдельному товару ±20%, поэтому есть настраиваемый запас
 * `leybo_size_margin` — он защищает правило «никогда не в минус».
 *
 * Источник множителя, по убыванию доверия:
 *   1. `_leybo_size_profile` — профиль самого товара (снят по его же ценам);
 *   2. `leybo_size_profile_common` — медиана по каталогу на этот размер;
 *   3. 1.0 — размер неизвестен, цену не трогаем.
 */

if (!defined('ABSPATH')) { exit; }

const LEYBO_SIZE_PROFILE_META = '_leybo_size_profile';
const LEYBO_SIZE_COMMON_OPT   = 'leybo_size_profile_common';
const LEYBO_SIZE_MARGIN_OPT   = 'leybo_size_margin';   // запас на неточность, %

/**
 * Размер в число.
 *
 * Работает по НАЗВАНИЮ термина, а не по слагу: слаги неоднозначны — `36-5`
 * это 36.5, а `36-37` это диапазон 36-37, и различить их подстановкой точки
 * нельзя. Диапазоны и детские размеры (`3Y`, `11C`) сознательно возвращают
 * null: у них своя шкала, и множитель к ним неприменим.
 */
function leybo_size_number($label) {
    $s = trim((string) $label);
    if ($s === '') { return null; }
    if (preg_match('~^\d+\s*-\s*\d+$~u', $s)) { return null; }   // диапазон 36-37
    if (preg_match('~[YyCc]\s*$~u', $s)) { return null; }        // детская шкала

    $add = 0.0;
    $frac = ['⅓' => 1/3, '⅔' => 2/3, '½' => 0.5, '¼' => 0.25, '¾' => 0.75,
             '1/3' => 1/3, '2/3' => 2/3, '1/2' => 0.5];
    foreach ($frac as $glyph => $val) {
        if (mb_strpos($s, $glyph) !== false) {
            $add = $val;
            $s = str_replace($glyph, '', $s);
            break;
        }
    }
    $s = str_replace(',', '.', $s);
    if (!preg_match('~\d+(\.\d+)?~', $s, $m)) { return null; }
    return (float) $m[0] + $add;
}

/** Множитель для размера: сопоставление числовое, а не строковое. */
function leybo_size_multiplier($size_label, array $own, array $common) {
    $n = leybo_size_number($size_label);
    if ($n === null) { return 1.0; }
    foreach ([$own, $common] as $table) {
        foreach ($table as $key => $mult) {
            $kn = leybo_size_number($key);
            if ($kn !== null && abs($kn - $n) < 0.02) { return (float) $mult; }
        }
    }
    return 1.0;
}

/**
 * Название размера у варианта (не цвет — берём именно размерный атрибут).
 *
 * Префикс `sp_` обязателен: имя `leybo_variation_size` уже занято в
 * leybo-sync.php, и повторное объявление роняет ВЕСЬ sandbox разом —
 * вместе с боевыми плагинами (админка, Ракета, юр. чекбокс).
 */
function leybo_sp_variation_size(WC_Product_Variation $v) {
    foreach ($v->get_attributes() as $tax => $slug) {
        if ($slug === '' || (stripos($tax, 'razmer') === false && stripos($tax, 'size') === false)) {
            continue;
        }
        $term = get_term_by('slug', $slug, $tax);
        return $term ? $term->name : urldecode($slug);
    }
    return '';
}

/**
 * Проставляет каждому варианту свою цену.
 *
 * @return array|false отчёт или false, если у товара нет данных для расчёта
 */
function leybo_apply_size_prices($pid, $dry = false) {
    $cny = (float) get_post_meta($pid, '_leybo_cny', true);
    $k   = (float) get_post_meta($pid, '_leybo_k', true);
    if ($cny <= 0 || $k <= 0) { return false; }

    $product = wc_get_product($pid);
    if (!$product || !$product->get_children()) { return false; }

    $own = json_decode((string) get_post_meta($pid, LEYBO_SIZE_PROFILE_META, true), true);
    if (!is_array($own)) { $own = []; }
    $common = (array) get_option(LEYBO_SIZE_COMMON_OPT, []);
    $margin = 1 + ((float) get_option(LEYBO_SIZE_MARGIN_OPT, 0)) / 100;

    $rows = []; $changed = 0;
    foreach ($product->get_children() as $vid) {
        $v = wc_get_product($vid);
        if (!$v) { continue; }
        $label = leybo_sp_variation_size($v);
        $mult  = leybo_size_multiplier($label, $own, $common);
        $price = leybo_retail_price($cny * $mult * $margin, $k, $pid);
        if ($price <= 0) { continue; }

        $old = (float) $v->get_regular_price();
        $rows[] = ['размер' => $label, 'множитель' => round($mult, 3),
                   'было' => $old, 'стало' => $price];
        if (!$dry && (int) $old !== (int) $price) {
            $v->set_regular_price($price);
            $v->set_sale_price('');
            $v->set_price($price);
            $v->save();
            $changed++;
        }
    }
    if (!$dry && $changed) {
        // без sync() родительская цена остаётся пустой, и товар кладётся
        // в корзину бесплатно — эта грабля уже стоила проекту боевого бага
        $product = wc_get_product($pid);
        if ($product instanceof WC_Product_Variable) { WC_Product_Variable::sync($pid); }
        wc_delete_product_transients($pid);
    }
    return ['варианты' => $rows, 'изменено' => $changed,
            'свой_профиль' => (bool) $own];
}
