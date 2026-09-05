<?php
/**
 * Сборка карточек каталога из артикула.
 *
 * Чинить старые карточки по артикулу нельзя: мы доказали на 13 цепочках, что
 * фото и заголовок шли вместе и были правы, а съезжал именно артикул. Переписать
 * карточку по её артикулу значило бы молча подменить товар. А вот собрать пару,
 * которой на сайте НЕТ, — безопасно: противоречить нечему, и привязка получается
 * верной by design.
 *
 * Чего не умела `leybo_admin_create_product()`: категорий и бренда. Без них товар
 * не попадает ни в «Мужчинам», ни в фильтры — то есть на витрине его нет.
 *
 * 🔑 Раскладку категорий НЕ выдумываем. Она снимается с уже стоящих на сайте
 * карточек той же модели: у «Samba OG» на сайте уже проставлено, кеды это или
 * кроссовки, низкие или высокие, мужские или женские. Модель берём из каталога,
 * поэтому новая пара ложится ровно туда же, где лежат её сёстры.
 */

if (!defined('ABSPATH')) { exit; }

const LEYBO_BUILD_QUEUE = 'leybo_build_queue';
const LEYBO_BUILD_DONE  = 'leybo_build_done';

/** Каталог заказчика: артикул -> строка (бренд, модель, коэффициент). */
function leybo_build_catalog() {
    static $c = null;
    if ($c !== null) { return $c; }
    $c = [];
    $f = WP_CONTENT_DIR . '/novamira-sandbox/leybo-catalog.json';
    foreach ((array)json_decode(@file_get_contents($f), true) as $r) {
        $a = strtoupper(preg_replace('/[\s\-_.]/', '', (string)($r['article'] ?? '')));
        if ($a !== '') { $c[$a] = $r; }
    }
    return $c;
}

/**
 * Модель -> набор категорий, снятый с живых карточек сайта.
 *
 * Берём только те товары, чей артикул есть в каталоге: у них модель известна
 * точно, а не угадана из заголовка. Побеждает самый частый набор — одиночная
 * карточка с криво проставленными категориями не должна задавать правило.
 */
function leybo_build_cat_map() {
    static $map = null;
    if ($map !== null) { return $map; }
    global $wpdb;
    $cat = leybo_build_catalog();
    $votes = [];
    $rows = $wpdb->get_results(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key='_leybo_article' AND meta_value<>''");
    foreach ($rows as $r) {
        $a = strtoupper(preg_replace('/[\s\-_.]/', '', $r->meta_value));
        if (!isset($cat[$a])) { continue; }
        $model = trim((string)$cat[$a]['model']);
        $ids = wp_get_post_terms((int)$r->post_id, 'product_cat', ['fields' => 'ids']);
        if (is_wp_error($ids) || !$ids) { continue; }
        sort($ids);
        $key = implode(',', $ids);
        $votes[$model][$key] = ($votes[$model][$key] ?? 0) + 1;
    }
    $map = [];
    foreach ($votes as $model => $sets) {
        arsort($sets);
        $map[$model] = array_map('intval', explode(',', array_key_first($sets)));
    }
    return $map;
}

/** Бренд каталога -> термин pa_brend, как он уже записан на сайте. */
function leybo_build_brand_term($brand_raw, $name) {
    $terms = get_terms(['taxonomy' => 'pa_brend', 'hide_empty' => false]);
    if (is_wp_error($terms)) { return null; }
    $needle = mb_strtolower(trim((string)$brand_raw));
    $best = null;
    foreach ($terms as $t) {
        $n = mb_strtolower($t->name);
        // «ADIDAS» из каталога должен найти «Adidas Originals» на сайте
        if ($n === $needle || mb_strpos($n, $needle) === 0 || mb_strpos($needle, $n) === 0) {
            // при нескольких кандидатах выбираем тот, что встречается в названии товара
            if ($best === null || mb_stripos($name, $t->name) !== false) { $best = $t; }
        }
    }
    return $best;
}

/**
 * Заголовок, пригодный для русского магазина.
 *
 * Площадка не всем товарам даёт английское имя: примерно у каждого восьмого его
 * нет, и тогда карточка забирает китайский заголовок целиком — вместе с ним в
 * адрес страницы уезжает вереница процентов. Такие случаи собираем из каталога:
 * бренд и модель там уже написаны по-человечески, а расцветка — по-русски.
 */
function leybo_build_clean_title($pid, $row, $brand_label) {
    $name = (string)get_the_title($pid);
    if (!preg_match('/\p{Han}/u', $name)) { return $name; }   // латиница — оставляем как есть

    $brand = $brand_label ?: mb_convert_case(trim((string)($row['brand'] ?? '')), MB_CASE_TITLE, 'UTF-8');
    $model = trim((string)($row['model'] ?? ''));
    // ⚠️ Цвет берём из китайского заголовка, а НЕ из колонки каталога. Колонка
    // врёт: у JH5632 там «Чёрный / Розовый», а площадка пишет 白蓝 — бело-синий.
    // Цена и будущий выкуп идут от площадки, значит и расцветка обязана быть её,
    // иначе карточка снова разъедется с товаром — ровно та поломка, которую чиним.
    $color = implode(' ', leybo_build_cn_colours($name));

    $clean = trim($brand . ' ' . $model);
    if ($clean === '') { return $name; }
    if ($color !== '') { $clean .= ' «' . $color . '»'; }

    wp_update_post(['ID' => $pid, 'post_title' => $clean, 'post_name' => sanitize_title($clean)]);
    return $clean;
}

/**
 * Расцветка по-русски из китайского заголовка площадки.
 *
 * Словарь крошечный: площадка называет цвет одним-двумя иероглифами и делает это
 *单значно, в отличие от английской каши, где Cloud/Off/Crystal/Wonder White —
 * это всё «белый». Порядок важен: составные (米白 «молочный») проверяются раньше
 * одиночных, иначе 白 съест первую половину и выйдет просто «белый».
 */
function leybo_build_cn_colours($text) {
    // ⚠️ Справа — ТОЛЬКО те 15 цветов, что заведены в фильтре витрины. Сначала
    // словарь возвращал «Тёмно-серый», «Кремовый», «Бордовый» — таких терминов
    // нет, запись молча не проходила, и товар оставался без цвета вовсе.
    // Оттенки сводим к базовому: покупатель фильтрует по «серый», а не по
    // «графитовый».
    static $map = [
        '米白' => 'Белый', '藏青' => 'Синий', '深灰' => 'Серый', '浅灰' => 'Серый',
        '石墨' => 'Серый', '烟灰' => 'Серый', '燕麦' => 'Бежевый',
        '浅蓝' => 'Синий', '天蓝' => 'Синий', '冰蓝' => 'Синий', '青' => 'Синий',
        '军绿' => 'Зелёный', '墨绿' => 'Зелёный', '酒红' => 'Красный',
        '多色' => 'Разноцветный', '彩色' => 'Разноцветный',
        '白' => 'Белый', '黑' => 'Чёрный', '灰' => 'Серый', '蓝' => 'Синий',
        '红' => 'Красный', '绿' => 'Зелёный', '黄' => 'Жёлтый', '粉' => 'Розовый',
        '紫' => 'Фиолетовый', '橙' => 'Оранжевый', '橘' => 'Оранжевый',
        '棕' => 'Коричневый', '褐' => 'Коричневый', '咖' => 'Коричневый',
        '卡其' => 'Бежевый', '沙' => 'Бежевый', '杏' => 'Бежевый', '米' => 'Бежевый',
        '银' => 'Серебристый', '金' => 'Золотой',
    ];
    // цвет площадка ставит в самый хвост названия — там и ищем, чтобы не поймать
    // иероглиф из описания материала
    $tail = mb_substr((string)$text, max(0, mb_strlen((string)$text) - 10));
    $out = [];
    foreach ($map as $cn => $ru) {
        if (mb_strpos($tail, $cn) !== false && !in_array($ru, $out, true)) {
            $out[] = $ru;
            $tail = str_replace($cn, '', $tail);
        }
    }
    return array_slice($out, 0, 3);
}

/** Те же цвета — в фильтр по цвету. */
function leybo_build_ru_colours($pid, $title) {
    $ids = [];
    foreach (leybo_build_cn_colours($title) as $ru) {
        $t = get_term_by('name', $ru, LEYBO_COLOR_TAX);
        if ($t) { $ids[] = (int)$t->term_id; }
    }
    if ($ids) { wp_set_object_terms($pid, array_unique($ids), LEYBO_COLOR_TAX, false); }
    return count($ids);
}

/** Достроить карточку до пригодной к показу: категории, бренд, вердикт, публикация. */
function leybo_build_finish($pid, $row) {
    $model = trim((string)($row['model'] ?? ''));
    $map = leybo_build_cat_map();
    $cats = $map[$model] ?? [];
    if ($cats) { wp_set_object_terms($pid, $cats, 'product_cat', false); }

    $name = get_the_title($pid);
    $bt = leybo_build_brand_term($row['brand'] ?? '', $name);
    if ($bt) { wp_set_object_terms($pid, [(int)$bt->term_id], 'pa_brend', false); }

    $was = $name;
    $name = leybo_build_clean_title($pid, $row, $bt ? $bt->name : '');
    // цвет проставляется по английскому названию; если его не было — по китайскому
    if (!wp_get_post_terms($pid, LEYBO_COLOR_TAX, ['fields' => 'ids'])) {
        leybo_build_ru_colours($pid, $was);
    }

    // собрана из артикула — расхождение невозможно
    update_post_meta($pid, '_leybo_photo_check', 'ok | ' . current_time('mysql'));
    update_post_meta($pid, '_leybo_built', current_time('mysql'));

    wp_update_post(['ID' => $pid, 'post_status' => 'publish']);
    return ['cats' => count($cats), 'brand' => $bt ? $bt->name : null];
}

/**
 * Одна позиция -> готовый товар.
 *
 * @param string $article артикул производителя
 * @param float  $k_force коэффициент, заданный руками; 0 — взять из каталога
 *
 * Артикула может не быть в таблице заказчика: владелец имеет право завести пару,
 * которой в каталоге нет. Тогда бренд и модель неизвестны — категории проставить
 * неоткуда, но всё остальное (цена, размеры, фото, публикация) отработает.
 */
function leybo_build_one($article, $k_force = 0) {
    $cat = leybo_build_catalog();
    $key = strtoupper(preg_replace('/[\s\-_.]/', '', $article));
    $row = $cat[$key] ?? ['article' => $article, 'brand' => '', 'model' => '', 'k' => 0];

    $k = (float)$k_force;
    if ($k <= 0) { $k = (float)($row['k'] ?? 0); }
    if ($k <= 0) { $k = 1.65; }

    $pid = leybo_admin_create_product($article, $k);
    if (is_wp_error($pid)) { return $pid; }

    $extra = leybo_build_finish($pid, $row);
    return ['id' => $pid, 'article' => $article, 'k' => $k] + $extra;
}

/**
 * Забрать позицию себе так, чтобы её не взял параллельный прогон.
 *
 * 🐞 На этом уже обожглись: пачка на 40 позиций идёт минут девять, следующий
 * запрос уходил раньше — и два прогона разобрали часть очереди дважды, получилось
 * 18 карточек-дублей. Записи «сдвинули очередь» мало: чтение, сдвиг и запись не
 * атомарны, между ними влезает второй процесс.
 *
 * `add_option` же ложится в INSERT по уникальному имени опции — второй вызов с
 * тем же именем просто вернёт false. Это и есть замок, причём на стороне БД.
 */
function leybo_build_claim($article) {
    $key = 'leybo_bclaim_' . md5(strtoupper($article));
    return add_option($key, time(), '', false);
}

/** Очередь: гоняем порциями, каждая укладывается в один запрос. */
function leybo_build_run($limit = 5) {
    @ignore_user_abort(true);
    @set_time_limit(600);
    $done = (array)get_option(LEYBO_BUILD_DONE, []);
    $out = [];
    for ($i = 0; $i < $limit; $i++) {
        $queue = (array)get_option(LEYBO_BUILD_QUEUE, []);
        if (!$queue) { break; }
        $article = array_shift($queue);
        update_option(LEYBO_BUILD_QUEUE, $queue, false);
        if (!leybo_build_claim($article)) { continue; }   // уже забрал кто-то другой
        $r = leybo_build_one($article);
        $done[$article] = is_wp_error($r) ? ('ошибка: ' . $r->get_error_message()) : $r['id'];
        update_option(LEYBO_BUILD_DONE, $done, false);
        $out[$article] = $done[$article];
    }
    return ['сделано' => $out, 'осталось' => count((array)get_option(LEYBO_BUILD_QUEUE, []))];
}
