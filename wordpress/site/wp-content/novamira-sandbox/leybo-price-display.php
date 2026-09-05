<?php
/**
 * Как показана цена вариативного товара.
 *
 * Цена принадлежит размеру, и разброс внутри товара настоящий: у площадки
 * редкий размер реально стоит в разы дороже ходового — Jordan 11 Cool Grey:
 * floor ¥720, максимум по размерам ¥4509, то есть 6.3× (проверено живьём
 * 13.08.2026). Резать это потолком нельзя — продадим редкий размер ниже
 * закупа. Но диапазон «14 600 – 105 800 ₽» в каталоге читается как поломка.
 *
 * Поэтому: в списке «от N ₽», точная цена — после выбора размера.
 *
 * N считается по размерам В НАЛИЧИИ. У 47 товаров самый дешёвый размер
 * распродан, и «от» по нему было бы приманкой: медианно на 1100 ₽ ниже
 * того, что реально можно купить, в пределе — на 11 800 ₽.
 *
 * Выключается переименованием файла в leybo-price-display.php.disabled —
 * цены в базе он не трогает вообще, только показ.
 */

if (!defined('ABSPATH')) { exit; }

/**
 * Сводка цен товара — только по размерам, которые можно купить.
 *
 * Статус берётся одним запросом по мете, а не через wc_get_product() в
 * цикле: на странице каталога это было бы сотни загрузок объектов.
 */
add_filter('woocommerce_variation_prices', function ($prices, $product, $for_display) {
    if (empty($prices['price'])) { return $prices; }

    global $wpdb;
    $ids = array_map('intval', array_keys($prices['price']));
    $in  = implode(',', $ids);
    $oos = $wpdb->get_col(
        "SELECT post_id FROM {$wpdb->postmeta}
          WHERE post_id IN ($in) AND meta_key = '_stock_status' AND meta_value <> 'instock'"
    );
    if (!$oos) { return $prices; }

    $oos = array_flip(array_map('intval', $oos));
    $keep = array_diff_key($prices['price'], $oos);
    // всё распродано -> оставляем как есть, иначе товар останется без цены
    if (!$keep) { return $prices; }

    foreach (['price', 'regular_price', 'sale_price'] as $key) {
        if (!empty($prices[$key])) { $prices[$key] = array_diff_key($prices[$key], $oos); }
    }
    return $prices;
}, 10, 3);

/** Кэш сводки цен должен знать, что мы её отфильтровали. */
add_filter('woocommerce_get_variation_prices_hash', function ($hash) {
    $hash['leybo_instock_only'] = 1;
    return $hash;
});

/** В списке и на карточке до выбора размера — «от N ₽» вместо диапазона. */
add_filter('woocommerce_get_price_html', function ($html, $product) {
    if (!($product instanceof WC_Product_Variable)) { return $html; }

    $min = $product->get_variation_price('min', true);
    $max = $product->get_variation_price('max', true);
    if ($min === '' || (float) $min <= 0) { return $html; }
    if ((float) $min === (float) $max) { return $html; }   // все размеры по одной цене

    return '<span class="leybo-price-from">от ' . wc_price($min) . '</span>' . $product->get_price_suffix();
}, 20, 2);
