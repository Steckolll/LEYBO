<?php
/**
 * Фото-проверка привязки: тот ли кроссовок стоит на карточке, что говорит артикул.
 *
 * Фид импорта раскладывал по карточке артикул, название и фото независимо друг
 * от друга, поэтому артикул мог оказаться от соседнего товара. Цена при этом
 * считается по артикулу — то есть на карточке стоит цена ЧУЖОЙ пары, а заказ в
 * Ракету выкупает не то, что человек видел.
 *
 * Ловится это тем, что фид скачивал картинки прямо с CDN площадки и сохранял
 * под тем же именем файла: имя файла работает отпечатком товара. Вердикт
 * считается снаружи (сервис резолвера читает галереи площадки) и кладётся сюда
 * в мету `_leybo_photo_check`:
 *
 *   ok       снимок карточки найден в галерее того товара, на который указывает
 *            её артикул — привязка доказана;
 *   alien    снимок принадлежит другому товару — привязка опровергнута;
 *   unknown  ни один снимок не опознан.
 *
 * 🔑 `unknown` — НЕ брак. Площадка со временем переснимает карточки, и наших
 * старых снимков в её сегодняшней галерее может не быть вовсе. Выборочная
 * проверка глазами дала примерно 5 из 6 верных среди `unknown`, поэтому карать
 * их нельзя — иначе с витрины уедет большая часть живого каталога. Наказывается
 * только `alien`, то есть положительное опознание чужого товара.
 */

if (!defined('ABSPATH')) { exit; }

const LEYBO_META_PHOTO_CHECK = '_leybo_photo_check';

/** 'ok' | 'alien' | 'unknown' | 'noindex' | '' — без даты, которую хранит мета. */
function leybo_verify_status($product_id) {
    $raw = (string)get_post_meta($product_id, LEYBO_META_PHOTO_CHECK, true);
    if ($raw === '') { return ''; }
    $parts = explode('|', $raw);
    return trim($parts[0]);
}

function leybo_verify_is_alien($product_id) {
    return leybo_verify_status($product_id) === 'alien';
}

/**
 * Опровергнутую привязку нельзя ни показывать, ни продавать.
 *
 * Скрытие таксономией обратимо руками, и этого мало: вернуть товар может кто
 * угодно из админки, не зная, почему он был спрятан. Поэтому запрет висит ещё и
 * на самом товаре — пока вердикт не сменится на ok, положить его в корзину
 * нельзя, даже открыв прямую ссылку.
 */
add_filter('woocommerce_product_is_visible', function ($visible, $product_id) {
    return leybo_verify_is_alien($product_id) ? false : $visible;
}, 10, 2);

add_filter('woocommerce_is_purchasable', function ($purchasable, $product) {
    return leybo_verify_is_alien($product->get_id()) ? false : $purchasable;
}, 10, 2);

add_filter('woocommerce_variation_is_purchasable', function ($purchasable, $variation) {
    return leybo_verify_is_alien($variation->get_parent_id()) ? false : $purchasable;
}, 10, 2);

/** Раскладка каталога по вердикту — для панели. */
function leybo_verify_counts() {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s", LEYBO_META_PHOTO_CHECK));
    $out = ['ok' => 0, 'alien' => 0, 'unknown' => 0, 'noindex' => 0];
    foreach ($rows as $r) {
        $k = trim(explode('|', $r->meta_value)[0]);
        if (isset($out[$k])) { $out[$k]++; }
    }
    return $out;
}
