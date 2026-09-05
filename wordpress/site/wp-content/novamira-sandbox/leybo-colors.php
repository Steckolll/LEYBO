<?php
/**
 * Базовый цвет товара для фильтра каталога.
 *
 * У сайта три «цветовых» атрибута, и все три непригодны: в них при импорте
 * съехали колонки, поэтому рядом с «Бежевый» лежат «01/15/2024», «¥1098» и
 * «Весна лето», а `pa_cvet` вдобавок раздут до 291 значения, где
 * «Армейскийзеленый» без пробела дублирует «Армейский зеленый». Фильтром такое
 * быть не может.
 *
 * Чистый источник — название товара: расцветка стоит в кавычках по-английски
 * («Samba OG 'Black White'»). Отсюда и берём, сводя к 14 базовым цветам.
 *
 * Пишем в `pa_color` — он существует, пуст, и на него УЖЕ настроен готовый
 * фильтр BeRocket (пост 81 «Цвет»), который просто некуда было включить.
 * `pa_cvet` не трогаем: он держит вариации товара.
 */

if (!defined('ABSPATH')) { exit; }

const LEYBO_COLOR_TAX = 'pa_color';

/** Английское слово из названия → базовый цвет. */
function leybo_color_map() {
    return [
        // прямые
        'black' => 'Чёрный', 'white' => 'Белый', 'grey' => 'Серый', 'gray' => 'Серый',
        'blue' => 'Синий', 'navy' => 'Синий', 'red' => 'Красный', 'green' => 'Зелёный',
        'brown' => 'Коричневый', 'beige' => 'Бежевый', 'pink' => 'Розовый',
        'purple' => 'Фиолетовый', 'violet' => 'Фиолетовый', 'yellow' => 'Жёлтый',
        'orange' => 'Оранжевый', 'silver' => 'Серебристый', 'gold' => 'Золотой',
        // оттенки
        'cream' => 'Бежевый', 'tan' => 'Бежевый', 'ivory' => 'Белый', 'sail' => 'Белый',
        'bone' => 'Бежевый', 'sand' => 'Бежевый', 'camel' => 'Бежевый', 'oat' => 'Бежевый',
        'olive' => 'Зелёный', 'khaki' => 'Зелёный', 'mint' => 'Зелёный', 'sage' => 'Зелёный',
        'burgundy' => 'Красный', 'wine' => 'Красный', 'maroon' => 'Красный',
        'coral' => 'Красный', 'crimson' => 'Красный', 'cherry' => 'Красный',
        'charcoal' => 'Серый', 'smoke' => 'Серый', 'slate' => 'Серый', 'ash' => 'Серый',
        'turquoise' => 'Синий', 'denim' => 'Синий', 'teal' => 'Синий', 'indigo' => 'Синий',
        'lilac' => 'Фиолетовый', 'lavender' => 'Фиолетовый',
        'mocha' => 'Коричневый', 'coffee' => 'Коричневый', 'chocolate' => 'Коричневый',
        'walnut' => 'Коричневый', 'espresso' => 'Коричневый',
        // именные расцветки, на которых спотыкался разбор
        'panda' => 'Чёрный', 'shadow' => 'Серый', 'graphite' => 'Серый',
        'stratus' => 'Серый', 'stealth' => 'Серый', 'chestnut' => 'Коричневый',
        'cobbler' => 'Коричневый', 'pollen' => 'Жёлтый', 'apricot' => 'Оранжевый',
        'bred' => 'Красный', 'concord' => 'Фиолетовый', 'eclipse' => 'Синий',
        'multicolor' => 'Разноцветный', 'colorful' => 'Разноцветный',
        'mixed' => 'Разноцветный', 'wheat' => 'Бежевый', 'milk' => 'Бежевый',
        'umber' => 'Коричневый', 'platinum' => 'Серебристый', 'cyan' => 'Синий',
        'taupe' => 'Коричневый', 'sandy' => 'Бежевый', 'chambray' => 'Синий',
        // Именные расцветки (Chicago, Cardinal, Taxi, Gratitude) сознательно НЕ добавлены:
        // их цвет пришлось бы утверждать по памяти, а не вычитывать из данных.
        // Такие товары просто останутся вне цветового фильтра — их найдут поиском и брендом.
    ];
}

/** @return string[] базовые цвета, найденные в названии */
function leybo_color_from_title($title) {
    $low = mb_strtolower(html_entity_decode((string)$title), 'UTF-8');
    $out = [];
    foreach (leybo_color_map() as $en => $ru) {
        if (preg_match('/\b' . preg_quote($en, '/') . '\b/u', $low)) { $out[$ru] = true; }
    }
    return array_keys($out);
}

/**
 * Проставляет базовые цвета товарам.
 *
 * @param bool $dry считать, но не писать
 */
function leybo_colors_apply($dry = true, $offset = 0, $limit = 300) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title FROM {$wpdb->posts}
         WHERE post_type='product' AND post_status='publish'
         ORDER BY ID LIMIT %d OFFSET %d", $limit, $offset), ARRAY_A);

    $done = 0; $empty = []; $counts = [];
    foreach ($rows as $r) {
        $colors = leybo_color_from_title($r['post_title']);
        if (!$colors) {
            if (count($empty) < 20) { $empty[] = html_entity_decode($r['post_title']); }
            continue;
        }
        foreach ($colors as $c) { $counts[$c] = ($counts[$c] ?? 0) + 1; }
        if (!$dry) { wp_set_object_terms((int)$r['ID'], $colors, LEYBO_COLOR_TAX, false); }
        $done++;
    }
    arsort($counts);
    return ['dry' => $dry, 'смотрели' => count($rows), 'проставлено' => $done,
            'без_цвета' => count($rows) - $done, 'по_цветам' => $counts,
            'примеры_без_цвета' => $empty];
}

/**
 * Включает готовый фильтр «Цвет» (пост 81) в группу фильтров каталога (83).
 * Он был собран давно, но не показывался: pa_color пустовал.
 */
function leybo_colors_enable_filter($filter_id = 81, $group_id = 83) {
    $g = get_post_meta($group_id, 'br_filters_group', true);
    if (!is_array($g)) { return ['error' => 'группа не найдена']; }
    $list = isset($g['filters']) && is_array($g['filters']) ? $g['filters'] : [];
    if (in_array((string)$filter_id, array_map('strval', $list), true)) {
        return ['уже_включён' => true, 'состав' => $list];
    }
    $before = $list;
    $list[] = (string)$filter_id;
    $g['filters'] = array_values($list);
    update_post_meta($group_id, 'br_filters_group', $g);
    return ['было' => $before, 'стало' => $g['filters']];
}
