<?php
/**
 * Панель ЛЕЙБО — рабочее место заказчика.
 *
 * До неё всё управление жило в разных местах: цены считал синк по зашитой
 * формуле, видимость правилась через стандартные экраны WooCommerce, настройки
 * Ракеты — отдельной страницей. Человеку без нас собрать из этого картину
 * «сколько мы зарабатываем на паре» было нельзя.
 *
 * Панель показывает по каждому товару три числа вместе — во что обошлось,
 * за сколько продаём, сколько остаётся — и даёт крутить ровно те ручки,
 * которые на это влияют.
 */

if (!defined('ABSPATH')) { exit; }

const LEYBO_ADMIN_SLUG    = 'leybo';
const LEYBO_ADMIN_PERPAGE = 25;

add_action('admin_menu', function () {
    add_menu_page('ЛЕЙБО', 'ЛЕЙБО', 'manage_woocommerce', LEYBO_ADMIN_SLUG,
        'leybo_admin_page', 'dashicons-store', 56);
}, 9);

function leybo_admin_tabs() {
    $t = [];
    // «Сводка» первой и по умолчанию: владелец должен видеть состояние магазина
    // сразу, а не искать его по вкладкам
    if (function_exists('leybo_dash_tab')) { $t['svodka'] = 'Сводка'; }
    $t['tovary'] = 'Товары';
    if (function_exists('leybo_dash_catalog_tab')) { $t['katalog'] = 'Каталог'; }
    $t['vidimost'] = 'Видимость';
    $t['ceny']     = 'Цены и маржа';
    $t['raketa']   = 'Ракета (API)';
    $t['dobavit']  = 'Добавить товар';
    return $t;
}

function leybo_admin_url($tab, $args = []) {
    return add_query_arg(array_merge(['page' => LEYBO_ADMIN_SLUG, 'tab' => $tab], $args),
        admin_url('admin.php'));
}

function leybo_admin_page() {
    if (!current_user_can('manage_woocommerce')) { return; }
    $tabs = leybo_admin_tabs();
    $first = array_key_first($tabs);
    $tab  = isset($_GET['tab']) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : $first;

    echo '<div class="wrap"><h1>ЛЕЙБО</h1><h2 class="nav-tab-wrapper">';
    foreach ($tabs as $k => $label) {
        printf('<a href="%s" class="nav-tab %s">%s</a>',
            esc_url(leybo_admin_url($k)), $tab === $k ? 'nav-tab-active' : '', esc_html($label));
    }
    echo '</h2>';

    switch ($tab) {
        case 'svodka':   leybo_dash_tab();             break;
        case 'katalog':  leybo_dash_catalog_tab();     break;
        case 'vidimost': leybo_admin_tab_visibility(); break;
        case 'ceny':     leybo_admin_tab_prices();     break;
        case 'raketa':   leybo_admin_tab_raketa();     break;
        case 'dobavit':  leybo_admin_tab_add();        break;
        default:         leybo_admin_tab_products();
    }
    echo '</div>';
}

/* ======================================================  ВИДИМОСТЬ  ====== */

const LEYBO_META_AUTOHIDE = '_leybo_autohidden';

/**
 * Раскладывает каталог по тому, можем ли мы проверить цену пары.
 *
 * Товар без артикула API не видит вообще: его цена приехала из старого фида и
 * с тех пор никем не проверялась. Продавать по такой цене — гадание, поэтому
 * заказчику нужен способ убрать их с витрины одним движением и так же вернуть.
 */
function leybo_admin_visibility_groups() {
    global $wpdb;
    $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'");

    $g = ['live' => [], 'no_article' => [], 'not_synced' => [], 'hidden' => []];
    foreach ($ids as $pid) {
        $pid = (int)$pid;
        $slugs = (array)wp_get_object_terms($pid, 'product_visibility', ['fields' => 'slugs']);
        if (in_array('exclude-from-catalog', $slugs, true)) { $g['hidden'][] = $pid; continue; }

        $art  = trim((string)get_post_meta($pid, '_leybo_article', true));
        $cny  = (float)get_post_meta($pid, '_leybo_cny', true);
        $sync = get_post_meta($pid, '_leybo_synced', true);

        // Метка синка — не признак живой цены. Карточку, собранную из артикула,
        // синк ещё не трогал: цену ей проставил сборщик напрямую. Судим по тому,
        // что важно на самом деле — есть ли артикул и есть ли цена в юанях.
        if ($art === '')                 { $g['no_article'][] = $pid; }
        elseif ($cny <= 0 && !$sync)     { $g['not_synced'][] = $pid; }
        else                             { $g['live'][] = $pid; }
    }
    return $g;
}

/** Скрывает товар и запоминает, что это сделали МЫ — иначе возврат поднимет и то, что прятали руками. */
function leybo_admin_autohide($pid, $reason) {
    leybo_admin_set_hidden($pid, true);
    update_post_meta($pid, LEYBO_META_AUTOHIDE, $reason . ' | ' . current_time('mysql'));
}

/**
 * Скрытые карточки, разложенные по ПРИЧИНЕ скрытия.
 *
 * Причина пишется в мету при скрытии и выглядит как «повод: подробности | дата».
 * Для списка нужен повод, а не подробности: «чужой артикул» встречается в десятке
 * формулировок, и без свёртки таблица превращается в простыню.
 */
function leybo_admin_hidden_groups() {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s", LEYBO_META_AUTOHIDE));
    $out = [];
    foreach ($rows as $r) {
        $reason = trim(explode('|', (string)$r->meta_value)[0]);
        $family = trim(explode(':', $reason)[0]);
        // уточнения в скобках («чужой артикул (сверка фото)») дробили одну причину
        // на три строки — для владельца это один и тот же повод
        $family = trim(preg_replace('~\s*\([^)]*\)~u', '', $family));
        if ($family === '') { $family = 'без причины'; }
        $out[$family][] = (int)$r->post_id;
    }
    uasort($out, function ($a, $b) { return count($b) - count($a); });
    return $out;
}

function leybo_admin_tab_visibility() {
    if (!empty($_POST['leybo_unhide_group'])) {
        check_admin_referer('leybo_visibility');
        $want = wp_unslash($_POST['leybo_unhide_group']);
        $groups = leybo_admin_hidden_groups();
        $n = 0;
        foreach ($groups[$want] ?? [] as $pid) {
            leybo_admin_set_hidden((int)$pid, false);
            delete_post_meta((int)$pid, LEYBO_META_AUTOHIDE);
            $n++;
        }
        wc_delete_product_transients();
        if (function_exists('wpfc_clear_all_cache')) { wpfc_clear_all_cache(true); }
        printf('<div class="notice notice-success"><p>Возвращено на витрину: <strong>%d</strong> '
             . '(группа «%s»).</p></div>', $n, esc_html($want));
    }

    $groups = leybo_admin_hidden_groups();
    $stats = function_exists('leybo_dash_shop_stats') ? leybo_dash_shop_stats(true) : null;

    echo '<p style="max-width:820px">Витрина собрана из артикулов: у каждой карточки название, '
       . 'цена, размеры и фото взяты у площадки, поэтому разъехаться с товаром она не может. '
       . 'Всё остальное убрано с витрины — <strong>не удалено</strong>: товары лежат в базе, '
       . 'открываются по прямой ссылке и видны в админке.</p>';

    if ($stats) {
        printf('<p style="font-size:15px">В продаже <strong>%d</strong>, скрыто <strong>%d</strong>.</p>',
            (int)$stats['visible'], array_sum(array_map('count', $groups)));
    }

    echo '<h2 style="margin-top:22px">Почему скрыто</h2>';
    echo '<p style="max-width:820px">Возврат идёт <strong>по группам</strong>, а не всё разом: '
       . 'причины разные, и поднимать снятый импорт вместе с починенными карточками нельзя — '
       . 'получатся два товара на один артикул.</p>';

    echo '<form method="post"><table class="wp-list-table widefat striped" style="max-width:900px">'
       . '<thead><tr><th>Причина</th><th style="width:90px">Товаров</th><th>Что это значит</th>'
       . '<th style="width:150px"></th></tr></thead><tbody>';
    wp_nonce_field('leybo_visibility');

    $explain = [
        'заменена карточкой из артикула' =>
            ['Старый импорт. На каждый такой артикул уже стоит новая карточка, собранная '
             . 'из площадки. Возвращать не нужно — получите дубль.', true],
        'нет артикула' =>
            ['Приехали из первого импорта без артикула. Цену проверить нечем, она с тех пор '
             . 'ни разу не обновлялась.', false],
        'чужой артикул' =>
            ['Доказано, что на карточке не тот кроссовок, что говорит её артикул. Такие '
             . 'не продаются, даже если вернуть их вручную.', false],
        'площадка не знает артикул' =>
            ['Артикул есть, но товара с ним на площадке нет — ни цены, ни размеров.', false],
        'артикул оказался от другой карточки' =>
            ['Артикул забрала карточка, которой он принадлежит по фото. Свой артикул '
             . 'у этой пары неизвестен.', false],
        'дубль артикула' =>
            ['Тот же артикул уже стоит на другой карточке, доказанной по фото.', false],
    ];

    foreach ($groups as $family => $ids) {
        $meta = null;
        foreach ($explain as $needle => $e) {
            if (mb_stripos($family, $needle) === 0) { $meta = $e; break; }
        }
        $text = $meta[0] ?? '—';
        $risky = !empty($meta[1]);
        printf('<tr><td><strong>%s</strong></td><td><strong>%d</strong></td><td>%s</td>'
             . '<td><button class="button" name="leybo_unhide_group" value="%s" '
             . 'onclick="return confirm(\'Вернуть на витрину %d товаров?%s\')">Вернуть группу</button></td></tr>',
            esc_html($family), count($ids), esc_html($text), esc_attr($family), count($ids),
            $risky ? '\n\nВНИМАНИЕ: на эти артикулы уже есть новые карточки. Вернёте — будет по два товара на один артикул.' : '');
    }
    if (!$groups) {
        echo '<tr><td colspan="4"><em>Ничего не скрыто.</em></td></tr>';
    }
    echo '</tbody></table></form>';

    // Живая цена ещё не значит ПРАВИЛЬНАЯ цена: артикул мог приехать от соседнего
    // товара, и тогда на карточке стоит цена чужой пары. Ловится это тем, что фид
    // скачивал фото прямо с площадки под её же именами файлов.
    if (function_exists('leybo_verify_counts')) {
        $v = leybo_verify_counts();
        echo '<h3 style="margin-top:26px">Проверка по фото: тот ли это кроссовок</h3>';
        echo '<p>Сверяем снимки карточки с галереей того товара, на который указывает её артикул.</p>';
        echo '<table class="wp-list-table widefat striped" style="max-width:760px"><thead><tr>'
           . '<th>Вердикт</th><th style="width:110px">Товаров</th><th>Что это значит</th></tr></thead><tbody>';
        printf('<tr><td><strong style="color:#008a20">Совпало</strong></td><td><strong>%d</strong></td>'
             . '<td>Снимок карточки найден в галерее товара — привязка доказана.</td></tr>', $v['ok']);
        printf('<tr><td><strong style="color:#b32d2e">Чужой товар</strong></td><td><strong>%d</strong></td>'
             . '<td>Снимок принадлежит другой паре. Такие не продаются и не показываются, '
             . 'даже если вернуть их вручную.</td></tr>', $v['alien']);
        printf('<tr><td>Не проверено</td><td>%d</td>'
             . '<td>Карточки старого импорта, чью привязку доказать не удалось: площадка '
             . 'со временем переснимает товары, и наших прежних снимков в её галерее уже '
             . 'нет. <strong>Все они убраны с витрины</strong> — вместо них стоят карточки, '
             . 'собранные из артикула.</td></tr>', $v['unknown']);
        echo '</tbody></table>';
    }

    // Проверять на витрине больше нечего: каждая карточка там собрана из артикула.
    // Но если однажды вернут группу руками, счётчик «чужой артикул» сразу это покажет.
    $g = leybo_admin_visibility_groups();
    $risk = count($g['no_article']) + count($g['not_synced']);
    if ($risk > 0) {
        echo '<div class="notice notice-warning inline" style="margin-top:22px;max-width:900px"><p>'
           . '<strong>На витрине ' . $risk . ' товаров, чью цену проверить нечем</strong> — '
           . 'у них нет артикула либо площадка его не знает. Скорее всего, кто-то вернул '
           . 'группу вручную. Их стоит убрать обратно.</p></div>';
    }
}

/* ==========================================================  ТОВАРЫ  ===== */

/** Видим ли товар в каталоге. */
function leybo_admin_is_hidden($pid) {
    $t = wp_get_object_terms($pid, 'product_visibility', ['fields' => 'slugs']);
    return in_array('exclude-from-catalog', (array)$t, true);
}

function leybo_admin_set_hidden($pid, $hide) {
    $t = wp_get_object_terms($pid, 'product_visibility', ['fields' => 'slugs']);
    $t = array_values(array_diff((array)$t, ['exclude-from-catalog']));
    if ($hide) { $t[] = 'exclude-from-catalog'; }
    wp_set_object_terms($pid, $t, 'product_visibility', false);
    wc_delete_product_transients($pid);
}

/** Пересчитывает цену товара по текущим настройкам и его мете. */
function leybo_admin_reprice($pid) {
    $cny = (float)get_post_meta($pid, '_leybo_cny', true);
    $k   = (float)get_post_meta($pid, '_leybo_k', true);
    if ($cny <= 0 || $k <= 0) { return false; }

    $price = leybo_retail_price($cny, $k, $pid);
    if ($price <= 0) { return false; }

    $p = wc_get_product($pid);
    if (!$p) { return false; }

    // У вариативного товара цену ставит профиль размеров: одна цена на все
    // размеры — это недобор медианно 37%. Плоский расчёт ниже остаётся
    // запасным путём — на случай, если модуль профиля не загружен.
    if ($p->get_children() && function_exists('leybo_apply_size_prices')
        && leybo_apply_size_prices($pid) !== false) {
        wc_delete_product_transients($pid);
        return $price;
    }

    foreach ($p->get_children() as $vid) {
        $v = wc_get_product($vid);
        if (!$v) { continue; }
        $v->set_regular_price($price);
        $v->set_sale_price('');
        $v->set_price($price);
        $v->save();
    }
    if (!$p->get_children()) {
        $p->set_regular_price($price);
        $p->set_price($price);
        $p->save();
    }
    wc_delete_product_transients($pid);
    return $price;
}

function leybo_admin_tab_products() {
    // ---- сохранение
    if (!empty($_POST['leybo_save_products'])) {
        check_admin_referer('leybo_products');
        $changed = 0;
        foreach ((array)($_POST['row'] ?? []) as $pid => $row) {
            $pid = (int)$pid;
            $k   = str_replace(',', '.', trim((string)($row['k'] ?? '')));
            $d   = str_replace(',', '.', trim((string)($row['discount'] ?? '')));

            if ($k !== '' && (float)$k > 0) { update_post_meta($pid, '_leybo_k', (float)$k); }
            if ($d === '' || (float)$d <= 0) { delete_post_meta($pid, LEYBO_META_DISCOUNT); }
            else { update_post_meta($pid, LEYBO_META_DISCOUNT, min(99, (float)$d)); }

            leybo_admin_set_hidden($pid, !empty($row['hidden']));
            if (leybo_admin_reprice($pid) !== false) { $changed++; }
        }
        if (function_exists('wpfc_clear_all_cache')) { wpfc_clear_all_cache(true); }
        echo '<div class="notice notice-success"><p>Сохранено. Пересчитано товаров: ' . (int)$changed . '.</p></div>';
    }

    if (!empty($_GET['leybo_fill_colours']) && function_exists('leybo_dash_fill_colours')) {
        $filled = leybo_dash_fill_colours(20);
        $left = count(leybo_dash_colourless());
        wc_delete_product_transients();
        printf('<div class="notice notice-success"><p>Проставлено цветов: <strong>%d</strong>. '
             . 'Осталось без цвета: <strong>%d</strong>.%s</p></div>',
            count($filled), $left,
            $left ? ' <a href="' . esc_url(leybo_admin_url('tovary', ['leybo_fill_colours' => 1]))
                    . '">Продолжить</a>' : '');
    }

    if (!empty($_GET['leybo_fix_titles']) && function_exists('leybo_dash_fix_duplicate_titles')) {
        $fixed = leybo_dash_fix_duplicate_titles();
        wc_delete_product_transients();
        if ($fixed) {
            echo '<div class="notice notice-success"><p>Переименовано: <strong>' . count($fixed)
               . '</strong>.</p><ul style="margin:6px 0 0 18px;list-style:disc">';
            foreach ($fixed as $pid => $t) {
                printf('<li><a href="%s">%s</a></li>', esc_url(get_edit_post_link($pid)), esc_html($t));
            }
            echo '</ul></div>';
        } else {
            echo '<div class="notice notice-info"><p>Одинаковых названий не нашлось.</p></div>';
        }
    }

    // ---- выборка
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    // по умолчанию показываем витрину, а не всю базу: с ней работают каждый день
    $show   = isset($_GET['show']) ? sanitize_key($_GET['show']) : 'onsale';
    $paged  = max(1, (int)($_GET['paged'] ?? 1));

    global $wpdb;
    $where = "p.post_type='product' AND p.post_status='publish'";
    $args  = [];
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where .= " AND (p.post_title LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} m
                    WHERE m.post_id=p.ID AND m.meta_key='_leybo_article' AND m.meta_value LIKE %s))";
        $args[] = $like; $args[] = $like;
    }
    if ($show === 'linked') {
        $where .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m2 WHERE m2.post_id=p.ID
                    AND m2.meta_key='_leybo_article' AND m2.meta_value<>'')";
    } elseif ($show === 'unlinked') {
        $where .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m2 WHERE m2.post_id=p.ID
                    AND m2.meta_key='_leybo_article' AND m2.meta_value<>'')";
    } elseif ($show === 'onsale' || $show === 'hidden') {
        // Каталог хранит и снятые карточки, поэтому список «всех товаров» втрое
        // длиннее витрины. Для ежедневной работы нужен ровно тот срез, который
        // видит покупатель, — иначе правки уходят в карточки, которых нет в продаже.
        $term = get_term_by('name', 'exclude-from-catalog', 'product_visibility');
        if ($term) {
            $in = $show === 'hidden' ? 'EXISTS' : 'NOT EXISTS';
            $where .= " AND $in (SELECT 1 FROM {$wpdb->term_relationships} tr
                        WHERE tr.object_id=p.ID AND tr.term_taxonomy_id=%d)";
            $args[] = (int)$term->term_taxonomy_id;
        }
    }

    $sql   = "SELECT SQL_CALC_FOUND_ROWS p.ID FROM {$wpdb->posts} p WHERE $where
              ORDER BY p.post_title LIMIT %d OFFSET %d";
    $args[] = LEYBO_ADMIN_PERPAGE;
    $args[] = ($paged - 1) * LEYBO_ADMIN_PERPAGE;
    $ids   = $wpdb->get_col($wpdb->prepare($sql, $args));
    $total = (int)$wpdb->get_var('SELECT FOUND_ROWS()');

    // ---- поиск и фильтр
    echo '<form method="get" style="margin:16px 0">';
    echo '<input type="hidden" name="page" value="' . LEYBO_ADMIN_SLUG . '"><input type="hidden" name="tab" value="tovary">';
    echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Название или артикул" style="width:280px">';
    echo ' <select name="show">';
    foreach (['onsale'=>'В продаже (витрина)','hidden'=>'Скрытые','all'=>'Все товары',
              'linked'=>'С артикулом (живая цена)','unlinked'=>'Без артикула'] as $v=>$l) {
        printf('<option value="%s"%s>%s</option>', $v, selected($show, $v, false), $l);
    }
    echo '</select> <button class="button">Показать</button></form>';

    echo '<p><strong>Найдено:</strong> ' . $total . '</p>';

    // ---- таблица
    echo '<form method="post">';
    wp_nonce_field('leybo_products');
    echo '<table class="wp-list-table widefat striped"><thead><tr>'
       . '<th style="width:52px"></th><th>Товар</th><th>Цена ¥</th><th>Себестоимость</th>'
       . '<th style="width:80px">Коэф.</th><th style="width:90px">Скидка&nbsp;%</th>'
       . '<th>Цена на сайте</th><th>Маржа</th><th style="width:90px">Скрыт</th></tr></thead><tbody>';

    foreach ($ids as $pid) {
        $pid = (int)$pid;
        $p   = wc_get_product($pid);
        if (!$p) { continue; }
        $art = get_post_meta($pid, '_leybo_article', true);
        $cny = (float)get_post_meta($pid, '_leybo_cny', true);
        $k   = (float)get_post_meta($pid, '_leybo_k', true);
        $b   = leybo_price_breakdown($cny, $k, $pid);
        $img = $p->get_image_id() ? wp_get_attachment_image_url($p->get_image_id(), 'thumbnail') : '';

        echo '<tr>';
        echo '<td>' . ($img ? '<img src="' . esc_url($img) . '" style="width:44px;height:44px;object-fit:cover">' : '') . '</td>';
        echo '<td><a href="' . esc_url(get_edit_post_link($pid)) . '"><strong>' . esc_html($p->get_name()) . '</strong></a>'
           . '<br><small style="color:#666">' . ($art ? 'артикул ' . esc_html($art) : '<span style="color:#b32d2e">нет артикула — цена не обновляется</span>') . '</small></td>';
        echo '<td>' . ($cny > 0 ? esc_html($cny) . ' ¥' : '—') . '</td>';
        echo '<td>' . ($b['cost'] > 0 ? number_format($b['cost'], 0, '.', ' ') . ' ₽' : '—') . '</td>';
        echo '<td><input type="text" name="row[' . $pid . '][k]" value="' . esc_attr($k ?: '') . '" size="4"></td>';
        echo '<td><input type="text" name="row[' . $pid . '][discount]" value="' . esc_attr($b['discount'] ?: '') . '" size="4" placeholder="—"></td>';
        echo '<td><strong>' . ($b['retail'] > 0 ? number_format($b['retail'], 0, '.', ' ') . ' ₽' : esc_html($p->get_price() ?: '—')) . '</strong></td>';
        echo '<td>' . ($b['cost'] > 0
              ? '<span style="color:' . ($b['margin'] > 0 ? '#008a20' : '#b32d2e') . '">'
                . number_format($b['margin'], 0, '.', ' ') . ' ₽ · ' . $b['margin_pct'] . '%</span>'
              : '—') . '</td>';
        echo '<td style="text-align:center"><input type="checkbox" name="row[' . $pid . '][hidden]" value="1" '
           . checked(leybo_admin_is_hidden($pid), true, false) . '></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p><button class="button button-primary" name="leybo_save_products" value="1">Сохранить страницу</button>'
       . ' <span class="description">Цена пересчитается сразу у всех изменённых товаров.</span></p></form>';

    // ---- страницы
    $pages = (int)ceil($total / LEYBO_ADMIN_PERPAGE);
    if ($pages > 1) {
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo paginate_links([
            'base' => leybo_admin_url('tovary', ['s'=>$search, 'show'=>$show, 'paged'=>'%#%']),
            'format' => '', 'current' => $paged, 'total' => $pages, 'mid_size' => 2,
        ]);
        echo '</div></div>';
    }
}

/* ===========================================================  ЦЕНЫ  ====== */

function leybo_admin_tab_prices() {
    if (!empty($_POST['leybo_save_prices'])) {
        check_admin_referer('leybo_prices');
        update_option('leybo_rate', (float)str_replace(',', '.', $_POST['rate']));
        update_option(LEYBO_OPT_AGENT_FEE, max(0, (float)str_replace(',', '.', $_POST['agent_fee'])));
        update_option('leybo_round_to', max(1, (int)$_POST['round_to']));
        echo '<div class="notice notice-success"><p>Настройки сохранены. Цены применятся при следующем обновлении каталога — или нажми «Пересчитать всё».</p></div>';
    }

    if (!empty($_POST['leybo_reprice_all'])) {
        check_admin_referer('leybo_prices');
        $n = 0;
        foreach (leybo_mapped_products() as $pid) {
            if (leybo_admin_reprice($pid) !== false) { $n++; }
        }
        if (function_exists('wpfc_clear_all_cache')) { wpfc_clear_all_cache(true); }
        echo '<div class="notice notice-success"><p>Пересчитано товаров: ' . $n . '.</p></div>';
    }

    $s = leybo_pricing_settings();

    echo '<form method="post">';
    wp_nonce_field('leybo_prices');
    echo '<table class="form-table">';
    echo '<tr><th>Курс юаня, ₽</th><td><input type="text" name="rate" value="' . esc_attr($s['rate']) . '" class="regular-text">'
       . '<p class="description">Курс ЦБ с вашей поправкой. В таблице каталога это «Курс LEYBO» = курс ЦБ × 1.04.</p></td></tr>';
    // подпись обязана говорить про ТЕКУЩЕЕ значение: «пока стоит 0» намертво в
    // тексте врало бы сразу после того, как заказчик выставит своё
    $fee_hint = $s['agent_fee'] > 0
        ? 'Сейчас <strong>' . esc_html($s['agent_fee']) . '%</strong> — себестоимость и маржа '
          . 'считаются с учётом работы Ракеты.'
        : '<strong style="color:#b32d2e">Сейчас 0</strong> — себестоимость занижена, '
          . 'и настоящая маржа меньше той, что показана в таблице товаров.';
    echo '<tr><th>Надбавка Ракеты, %</th><td><input type="text" name="agent_fee" value="' . esc_attr($s['agent_fee']) . '" class="regular-text">'
       . '<p class="description"><strong>Это то, что Ракета берёт сверх цены пары.</strong> Замер на живом API: пара за 215 ¥ обошлась в 2801.39 ₽, то есть 13.03 ₽ за юань против ' . esc_html($s['rate']) . ' в курсе выше — надбавка примерно <strong>14%</strong>. ' . $fee_hint . '</p></td></tr>';
    echo '<tr><th>Округлять до, ₽</th><td><input type="text" name="round_to" value="' . esc_attr($s['round_to']) . '" class="regular-text"></td></tr>';
    echo '</table>';
    echo '<p><button class="button button-primary" name="leybo_save_prices" value="1">Сохранить</button> '
       . '<button class="button" name="leybo_reprice_all" value="1" onclick="return confirm(\'Пересчитать цены у всех товаров с артикулом?\')">Пересчитать всё</button></p>';
    echo '</form>';

    // ---- живой пример, чтобы цифры не были абстракцией
    echo '<h2>Как это считается</h2>';
    echo '<table class="wp-list-table widefat striped" style="max-width:900px"><thead><tr>'
       . '<th>Цена пары</th><th>Себестоимость</th><th>Коэф.</th><th>Цена на сайте</th><th>Маржа</th></tr></thead><tbody>';
    foreach ([215, 400, 650] as $cny) {
        foreach ([1.5, 1.65] as $k) {
            $b = leybo_price_breakdown($cny, $k);
            printf('<tr><td>%s ¥</td><td>%s ₽</td><td>%s</td><td><strong>%s ₽</strong></td><td>%s ₽ · %s%%</td></tr>',
                $cny, number_format($b['cost'], 0, '.', ' '), $k,
                number_format($b['retail'], 0, '.', ' '),
                number_format($b['margin'], 0, '.', ' '), $b['margin_pct']);
        }
    }
    echo '</tbody></table>';
}

/* =========================================================  РАКЕТА  ====== */

function leybo_admin_tab_raketa() {
    if (function_exists('leybo_rcn_settings_page')) { leybo_rcn_settings_page(); }
    else { echo '<p>Модуль Ракеты не загружен.</p>'; }
}

/* ========================================================  ДОБАВИТЬ  ===== */

function leybo_admin_tab_add() {
    $article = isset($_REQUEST['article']) ? strtoupper(sanitize_text_field(wp_unslash($_REQUEST['article']))) : '';

    if (function_exists('leybo_dash_mass_add_ui')) {
        leybo_dash_mass_add_ui();
        echo '<hr style="margin:26px 0"><h2>Добавить один, с проверкой</h2>';
        echo '<p style="max-width:820px">Когда пара одна и хочется сперва посмотреть, '
           . 'что о ней знает площадка.</p>';
    }

    echo '<form method="get" style="margin:16px 0">';
    echo '<input type="hidden" name="page" value="' . LEYBO_ADMIN_SLUG . '"><input type="hidden" name="tab" value="dobavit">';
    echo '<input type="text" name="article" value="' . esc_attr($article) . '" placeholder="Артикул, например IG1024" style="width:260px">';
    echo ' <button class="button button-primary">Найти на площадке</button></form>';

    if ($article === '') {
        echo '<p class="description">Введите артикул производителя. Мы найдём пару на площадке и покажем, что о ней известно, — прежде чем заводить товар.</p>';
        return;
    }

    if (!function_exists('leybo_rcn_lookup')) { echo '<div class="notice notice-error"><p>Модуль поиска не загружен.</p></div>'; return; }
    $d = leybo_rcn_lookup($article);

    if (!empty($d['error']) || empty($d['found'])) {
        echo '<div class="notice notice-error"><p>Не нашли артикул <code>' . esc_html($article) . '</code>: '
           . esc_html($d['error'] ?? 'на площадке его нет') . '</p></div>';
        return;
    }

    $exists = leybo_admin_find_by_article($article);
    if ($exists) {
        echo '<div class="notice notice-warning"><p>Такой артикул уже привязан к товару '
           . '<a href="' . esc_url(get_edit_post_link($exists)) . '">' . esc_html(get_the_title($exists)) . '</a>.</p></div>';
    }

    $sizes = (array)($d['sizes'] ?? []);
    $avail = array_filter($sizes, function ($s) { return !empty($s['available']); });

    echo '<table class="form-table"><tbody>';
    echo '<tr><th>Фото</th><td>' . (!empty($d['photo']) ? '<img src="' . esc_url($d['photo']) . '" style="max-width:180px">' : '—') . '</td></tr>';
    echo '<tr><th>Название</th><td>' . esc_html($d['name'] ?: $d['title']) . '</td></tr>';
    echo '<tr><th>Бренд</th><td>' . esc_html($d['brand'] ?: '—') . '</td></tr>';
    echo '<tr><th>Цена на площадке</th><td>' . esc_html($d['price']['cny'] ?? '—') . ' ¥'
       . (!empty($d['price']['stale']) ? ' <span style="color:#b32d2e">(данные устарели)</span>' : '') . '</td></tr>';
    echo '<tr><th>Размеры</th><td>' . count($avail) . ' в наличии из ' . count($sizes) . '</td></tr>';
    echo '</tbody></table>';

    if (empty($d['price']['cny'])) {
        echo '<div class="notice notice-error"><p>У пары нет цены — товар заводить нельзя.</p></div>';
        return;
    }

    echo '<form method="post">';
    wp_nonce_field('leybo_add');
    echo '<input type="hidden" name="article" value="' . esc_attr($article) . '">';
    // коэффициент из каталога заказчика, если пара там есть — он и есть намерение
    $k_default = '1.65';
    if (function_exists('leybo_build_catalog')) {
        $row = leybo_build_catalog()[strtoupper(preg_replace('/[\s\-_.]/', '', $article))] ?? null;
        if ($row && (float)($row['k'] ?? 0) > 0) { $k_default = (string)$row['k']; }
    }
    echo '<p>Коэффициент наценки: <input type="text" name="k" value="' . esc_attr($k_default) . '" size="5">'
       . ($k_default !== '1.65' ? ' <span class="description">— взят из каталога</span>' : '') . '</p>';
    echo '<p><button class="button button-primary" name="leybo_do_add" value="1">Завести и опубликовать</button></p>';
    echo '<p class="description">Товар собирается из артикула: название, цена, размеры с наличием, '
       . 'фото и цвет берутся у площадки, категории — по такой же модели, уже стоящей на сайте. '
       . 'Поэтому карточка не может разъехаться с товаром и публикуется сразу.</p>';
    echo '</form>';
}

function leybo_admin_find_by_article($article) {
    global $wpdb;
    $key = strtoupper(trim($article));
    return (int)$wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_leybo_article' AND UPPER(meta_value)=%s LIMIT 1", $key));
}

/**
 * Заводит товар по артикулу из данных площадки.
 *
 * Черновиком — намеренно. Название с площадки бывает китайским или пустым,
 * фото одно, описания нет; человек должен это увидеть до публикации.
 * Зато артикул, размеры и skuId проставляются точно, а именно от них зависит,
 * ту ли пару выкупит Ракета.
 */
function leybo_admin_create_product($article, $k) {
    if (!function_exists('leybo_rcn_lookup')) { return new WP_Error('no_api', 'модуль поиска не загружен'); }
    $d = leybo_rcn_lookup($article);
    if (empty($d['found'])) { return new WP_Error('not_found', 'артикул не найден на площадке'); }

    $cny = (float)($d['price']['cny'] ?? 0);
    if ($cny <= 0) { return new WP_Error('no_price', 'у пары нет цены'); }

    $name = trim((string)($d['name'] ?: $d['title']));
    if ($name === '') { $name = $article; }

    $product = new WC_Product_Variable();
    $product->set_name($name);
    $product->set_status('draft');
    $product->set_catalog_visibility('visible');
    $product->set_description('');
    $product->save();
    $pid = $product->get_id();

    update_post_meta($pid, '_leybo_article', strtoupper($article));
    update_post_meta($pid, '_leybo_k', (float)$k);
    update_post_meta($pid, '_leybo_cny', $cny);
    if (!empty($d['spu'])) { update_post_meta($pid, '_leybo_spu', $d['spu']); }

    // размеры: заводим ВСЕ, что знает площадка, наличие проставляем отдельно
    $sizes = [];
    foreach ((array)($d['sizes'] ?? []) as $s) {
        $label = leybo_rcn_norm_size($s['size'] ?? '');
        if ($label !== '') { $sizes[$label] = !empty($s['available']); }
    }
    if ($sizes) {
        $term_ids = [];
        foreach (array_keys($sizes) as $label) {
            $t = get_term_by('name', $label, 'pa_razmer');
            if (!$t) { $t = wp_insert_term($label, 'pa_razmer'); $t = is_wp_error($t) ? null : get_term($t['term_id']); }
            if ($t) { $term_ids[$label] = (int)$t->term_id; }
        }
        wp_set_object_terms($pid, array_values($term_ids), 'pa_razmer', false);

        $attr = new WC_Product_Attribute();
        $attr->set_id(wc_attribute_taxonomy_id_by_name('pa_razmer'));
        $attr->set_name('pa_razmer');
        $attr->set_options(array_values($term_ids));
        $attr->set_visible(true);
        $attr->set_variation(true);
        $product->set_attributes([$attr]);
        $product->save();

        $price = leybo_retail_price($cny, (float)$k, $pid);
        foreach ($sizes as $label => $in_stock) {
            $t = get_term_by('name', $label, 'pa_razmer');
            if (!$t) { continue; }
            $v = new WC_Product_Variation();
            $v->set_parent_id($pid);
            $v->set_attributes(['pa_razmer' => $t->slug]);
            $v->set_regular_price($price);
            $v->set_price($price);
            $v->set_manage_stock(false);
            $v->set_stock_status($in_stock ? 'instock' : 'outofstock');
            $v->save();
        }
    }

    // фото — единственное, что отдаёт площадка
    if (!empty($d['photo'])) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $att = media_sideload_image($d['photo'], $pid, $name, 'id');
        if (!is_wp_error($att)) { set_post_thumbnail($pid, $att); }
    }

    if (function_exists('leybo_color_from_title')) {
        $colors = leybo_color_from_title($name);
        if ($colors) { wp_set_object_terms($pid, $colors, LEYBO_COLOR_TAX, false); }
    }

    wc_delete_product_transients($pid);
    return $pid;
}

add_action('admin_init', function () {
    if (empty($_POST['leybo_do_add'])) { return; }
    if (!current_user_can('manage_woocommerce')) { return; }
    check_admin_referer('leybo_add');

    $article = strtoupper(sanitize_text_field(wp_unslash($_POST['article'] ?? '')));
    $k = (float)str_replace(',', '.', (string)($_POST['k'] ?? '1.65'));
    if ($k <= 0) { $k = 1.65; }

    // Через сборщик, а не напрямую: он доводит карточку до пригодной к показу —
    // категории по модели, бренд, русский заголовок вместо китайского, цвет.
    // Иначе заказчик заводит пару, а она не появляется ни в одном разделе сайта.
    if (function_exists('leybo_build_one')) {
        $built = leybo_build_one($article, $k);   // коэффициент из формы важнее каталожного
        $res = is_wp_error($built) ? $built : (int)$built['id'];
    } else {
        $res = leybo_admin_create_product($article, $k);
    }
    $args = is_wp_error($res)
        ? ['tab' => 'dobavit', 'article' => $article, 'leybo_err' => $res->get_error_message()]
        : ['tab' => 'dobavit', 'leybo_new' => $res];
    wp_safe_redirect(leybo_admin_url('dobavit', $args));
    exit;
});

add_action('admin_notices', function () {
    if (empty($_GET['page']) || $_GET['page'] !== LEYBO_ADMIN_SLUG) { return; }
    if (!empty($_GET['leybo_new'])) {
        $id = (int)$_GET['leybo_new'];
        $cats = wp_get_post_terms($id, 'product_cat', ['fields' => 'names']);
        $cats = is_wp_error($cats) ? [] : array_diff($cats, ['Без категории', 'Uncategorized']);
        echo '<div class="notice notice-success"><p>Товар создан и опубликован: '
           . '<a href="' . esc_url(get_edit_post_link($id)) . '">' . esc_html(get_the_title($id)) . '</a>'
           . ($cats ? ' — разделы: ' . esc_html(implode(', ', $cats))
                    : ' — <strong>без разделов</strong>: такой модели на сайте ещё не было, '
                      . 'категории нужно проставить руками')
           . '. <a href="' . esc_url(get_permalink($id)) . '" target="_blank">Посмотреть на сайте</a></p></div>';
    }
    if (!empty($_GET['leybo_err'])) {
        echo '<div class="notice notice-error"><p>Не получилось: ' . esc_html(wp_unslash($_GET['leybo_err'])) . '</p></div>';
    }
});
