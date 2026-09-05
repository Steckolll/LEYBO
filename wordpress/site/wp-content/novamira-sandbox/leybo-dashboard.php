<?php
/**
 * Рабочее место владельца: «Сводка» и «Каталог».
 *
 * Панель писалась под старый магазин, где половина карточек приехала из фида и
 * цену у них проверить было нечем. Сейчас витрина собрана из артикулов, и
 * заказчику нужны другие два ответа:
 *
 *   1. «Сейчас всё в порядке?» — одна страница, на которой видно и товар, и цены,
 *      и свежесть синка, и готовность Ракеты. Без неё владелец узнаёт о поломке
 *      от покупателя.
 *   2. «Чего ещё нет в продаже?» — каталог против сайта, с кнопкой досборки.
 *      Раньше добавить позицию можно было только по одному артикулу руками.
 *
 * Всё, что делают эти вкладки, обратимо: скрытие помечается, сборка идёт
 * очередью, ничего не удаляется.
 */

if (!defined('ABSPATH')) { exit; }

/* ==========================================================  СВОДКА  ===== */

/** Одна плитка сводки. */
function leybo_dash_tile($label, $value, $hint = '', $tone = '') {
    $colors = ['good' => '#008a20', 'bad' => '#b32d2e', 'warn' => '#996800'];
    $c = $colors[$tone] ?? '#1d2327';
    printf(
        '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;'
        . 'padding:14px 16px;min-width:180px;flex:1">'
        . '<div style="font-size:12px;color:#646970;text-transform:uppercase;'
        . 'letter-spacing:.03em">%s</div>'
        . '<div style="font-size:26px;font-weight:600;color:%s;line-height:1.25;'
        . 'margin:2px 0 1px">%s</div>'
        . '<div style="font-size:12px;color:#646970">%s</div></div>',
        esc_html($label), esc_attr($c), wp_kses_post($value), wp_kses_post($hint));
}

/**
 * Цифры по витрине: что продаётся, что распродано, разброс цен.
 *
 * Обход четырёх сотен товаров с подъёмом каждого через `wc_get_product()` стоит
 * секунды три, а сводку открывают часто и подряд. Держим ответ пять минут: для
 * «всё ли в порядке» этой свежести хватает, а страница открывается мгновенно.
 */
function leybo_dash_shop_stats($fresh = false) {
    $key = 'leybo_dash_stats';
    if (!$fresh) {
        $c = get_transient($key);
        if (is_array($c)) { return $c; }
    }
    $s = leybo_dash_shop_stats_raw();
    set_transient($key, $s, 5 * MINUTE_IN_SECONDS);
    return $s;
}

function leybo_dash_shop_stats_raw() {
    $q = new WP_Query([
        'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1,
        'fields' => 'ids',
        'tax_query' => [[
            'taxonomy' => 'product_visibility', 'field' => 'name',
            'terms' => ['exclude-from-catalog'], 'operator' => 'NOT IN',
        ]],
    ]);
    $s = ['visible' => 0, 'sold_out' => 0, 'min' => 0, 'max' => 0, 'no_price' => 0, 'built' => 0,
          'ok' => 0, 'alien' => 0, 'unchecked' => 0];
    $prices = [];
    foreach ($q->posts as $pid) {
        $p = wc_get_product($pid);
        if (!$p) { continue; }
        $s['visible']++;
        if (get_post_meta($pid, '_leybo_built', true)) { $s['built']++; }
        // считаем ПО ВИТРИНЕ. Общий счётчик по базе показывал 519 при 444 товарах
        // в продаже — число больше магазина читается как ошибка, а не как успех
        $v = trim(explode('|', (string)get_post_meta($pid, '_leybo_photo_check', true))[0]);
        if ($v === 'ok') { $s['ok']++; }
        elseif ($v === 'alien') { $s['alien']++; }
        else { $s['unchecked']++; }
        $out = $p->get_stock_status() !== 'instock';
        if ($out) { $s['sold_out']++; }
        $pr = (float)$p->get_price();
        if ($pr > 0) {
            $prices[] = $pr;
        } elseif (!$out) {
            // у распроданной пары цены нет законно: все размеры разобраны.
            // Тревога — только когда цены нет у той, что продаётся; иначе
            // индикатор горел бы красным всегда и перестал что-либо значить
            $s['no_price']++;
        }
    }
    if ($prices) { $s['min'] = min($prices); $s['max'] = max($prices); }
    return $s;
}

/** Когда синк последний раз доходил до товаров. */
function leybo_dash_sync_age() {
    global $wpdb;
    $last = $wpdb->get_var(
        "SELECT MAX(meta_value) FROM {$wpdb->postmeta} WHERE meta_key='_leybo_sync_attempt'");
    if (!$last) { return null; }
    return max(0, current_time('timestamp') - strtotime($last));
}

function leybo_dash_human_age($sec) {
    if ($sec === null) { return 'никогда'; }
    if ($sec < 3600) { return round($sec / 60) . ' мин назад'; }
    if ($sec < 86400) { return round($sec / 3600) . ' ч назад'; }
    return round($sec / 86400) . ' дн назад';
}

/** Сколько позиций каталога уже стоит на сайте. */
function leybo_dash_catalog_stats() {
    global $wpdb;
    $cat = function_exists('leybo_build_catalog') ? leybo_build_catalog() : [];
    $on = [];
    $rows = $wpdb->get_results(
        "SELECT m.post_id, m.meta_value art FROM {$wpdb->postmeta} m
         JOIN {$wpdb->posts} p ON p.ID=m.post_id
         WHERE m.meta_key='_leybo_article' AND m.meta_value<>'' AND p.post_status='publish'");
    foreach ($rows as $r) {
        if (has_term('exclude-from-catalog', 'product_visibility', (int)$r->post_id)) { continue; }
        $on[strtoupper(preg_replace('/[\s\-_.]/', '', $r->art))] = 1;
    }
    $bad = [];
    foreach ((array)get_option('leybo_build_done', []) as $art => $v) {
        if (!is_numeric($v)) { $bad[strtoupper(preg_replace('/[\s\-_.]/', '', $art))] = $art; }
    }
    $missing = [];
    foreach ($cat as $key => $r) {
        if (!isset($on[$key]) && !isset($bad[$key])) { $missing[] = $r; }
    }
    return ['total' => count($cat), 'on_site' => count(array_intersect_key($cat, $on)),
            'missing' => $missing, 'bad' => $bad];
}

function leybo_dash_tab() {
    $s = leybo_dash_shop_stats();
    $cat = leybo_dash_catalog_stats();
    $age = leybo_dash_sync_age();
    $ver = function_exists('leybo_verify_counts') ? leybo_verify_counts()
        : ['ok' => 0, 'alien' => 0, 'unknown' => 0, 'noindex' => 0];

    // «баланс не получен» ничего не объясняет: не задан токен и не ответил сервер —
    // разные беды с разным лечением. Пишем, какая именно.
    $mode = get_option('leybo_rcn_mode', 'dry');
    $balance = null;
    if (!function_exists('leybo_rcn_balance')) {
        $balance_note = 'модуль Ракеты не загружен';
    } elseif (!get_option('leybo_rcn_token', '')) {
        $balance_note = 'токен не задан — баланс не спросить';
    } else {
        $b = leybo_rcn_balance();
        if (is_wp_error($b)) {
            $balance_note = 'сервер Ракеты не ответил: ' . $b->get_error_message();
        } else {
            // Ракета кладёт баланс в body.data.balance; смотрим и по коротким путям,
            // чтобы правка формата ответа не оставила плитку немой
            $balance = $b['body']['data']['balance']
                ?? ($b['data']['balance'] ?? ($b['balance'] ?? null));
            $balance_note = $balance === null ? 'ответ без поля баланса'
                : ('баланс ' . number_format((float)$balance, 0, '', ' ') . ' ₽');
        }
    }

    echo '<p style="max-width:780px">Одна страница на вопрос «сейчас всё в порядке?». '
       . 'Красное и жёлтое требуют внимания, зелёное — нет.</p>';

    echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0">';
    leybo_dash_tile('В продаже', (int)$s['visible'],
        $s['built'] . ' собрано из артикула', $s['visible'] > 0 ? 'good' : 'bad');
    leybo_dash_tile('Распродано', (int)$s['sold_out'],
        'нет ни одного размера', $s['sold_out'] ? 'warn' : 'good');
    leybo_dash_tile('Цены', $s['min'] ? number_format($s['min'], 0, '', ' ') . ' – '
        . number_format($s['max'], 0, '', ' ') . ' ₽' : '—',
        $s['no_price'] ? $s['no_price'] . ' в продаже без цены!' : 'у всех, что в наличии, цена есть',
        $s['no_price'] ? 'bad' : 'good');
    // Раньше тут стояло «плановое обновление 06:20 и 18:20» — это время сервиса
    // на сервере, а не синка сайта. Показываем настоящее следующее срабатывание,
    // иначе владелец ждёт обновления не тогда, когда оно будет.
    $next = wp_next_scheduled('leybo_sync_event');
    if (!$next) {
        $next_note = 'расписание НЕ НАСТРОЕНО — цены сами не обновятся';
        $next_tone = 'bad';
    } else {
        $late = current_time('timestamp') - $next;
        $next_note = $late > 3600
            ? 'просрочено на ' . leybo_dash_human_age($late) . ' — расписание не срабатывает'
            : 'следующее — ' . date_i18n('j M, H:i', $next);
        $next_tone = $late > 3600 ? 'bad' : null;
    }
    leybo_dash_tile('Цены обновлялись', leybo_dash_human_age($age), $next_note,
        $next_tone ?: (($age === null || $age > 86400) ? 'bad' : (($age > 43200) ? 'warn' : 'good')));
    echo '</div>';

    echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0">';
    leybo_dash_tile('Каталог на сайте', $cat['on_site'] . ' из ' . $cat['total'],
        count($cat['missing']) ? count($cat['missing']) . ' можно добрать' : 'всё, что доступно',
        count($cat['missing']) ? 'warn' : 'good');
    leybo_dash_tile('Привязка проверена',
        $s['ok'] . ' из ' . $s['visible'],
        $s['unchecked'] ? $s['unchecked'] . ' без проверки' : 'весь товар в продаже',
        $s['unchecked'] ? 'warn' : 'good');
    leybo_dash_tile('Чужой артикул на витрине', (int)$s['alien'],
        $s['alien'] ? 'надо разобраться немедленно'
            : ($ver['alien'] ? 'ни одного; всего в базе ' . (int)$ver['alien'] . ', все скрыты'
                             : 'таких нет вообще'),
        $s['alien'] ? 'bad' : 'good');
    // пустой баланс важнее режима: в боевом режиме с нулём заказ всё равно упадёт
    $rocket_tone = ($balance !== null && (float)$balance <= 0) ? 'bad'
        : ($mode === 'live' ? 'good' : 'warn');
    leybo_dash_tile('Ракета', $mode === 'live' ? 'боевой режим' : 'тест (dry)',
        $balance_note, $rocket_tone);
    echo '</div>';

    /* ---- что требует решения ---- */
    $todo = [];
    if ($mode !== 'live') {
        $todo[] = ['Ракета в тестовом режиме — боевой заказ на выкуп не уйдёт.',
                   leybo_admin_url('raketa'), 'Открыть настройки Ракеты'];
    }
    if ($balance !== null && (float)$balance <= 0) {
        $todo[] = ['На балансе Ракеты нет денег — выкуп не пройдёт даже в боевом режиме.', '', ''];
    }
    if ((float)get_option('leybo_agent_fee', 0) <= 0) {
        $todo[] = ['Надбавка Ракеты не задана: себестоимость занижена, маржа в отчётах '
                   . 'выглядит больше настоящей.', leybo_admin_url('ceny'), 'Задать надбавку'];
    }
    if (count($cat['missing'])) {
        $todo[] = [count($cat['missing']) . ' позиций каталога ещё нет в продаже.',
                   leybo_admin_url('katalog'), 'Собрать их'];
    }
    if (count($cat['bad'])) {
        $todo[] = [count($cat['bad']) . ' артикулов площадка не знает — возможно, сняты '
                   . 'с производства или в таблице опечатка.', leybo_admin_url('katalog'),
                   'Посмотреть список'];
    }
    if ($s['no_price']) {
        $todo[] = [$s['no_price'] . ' товаров в наличии, но без цены — их можно положить '
                   . 'в корзину бесплатно. Это надо чинить в первую очередь.',
                   leybo_admin_url('tovary'), 'Открыть товары'];
    }
    if ($s['alien']) {
        $todo[] = [$s['alien'] . ' товаров на витрине с чужим артикулом — на карточке не тот '
                   . 'кроссовок, что говорит её артикул. Продавать их нельзя.',
                   leybo_admin_url('vidimost'), 'Разобраться'];
    }
    if ($s['unchecked']) {
        $todo[] = [$s['unchecked'] . ' товаров в продаже без проверки привязки — скорее всего, '
                   . 'их вернули из скрытых вручную.', leybo_admin_url('vidimost'), 'Посмотреть'];
    }
    $colourless = leybo_dash_colourless_fixable();
    if ($colourless) {
        $todo[] = [count($colourless) . ' товаров без расцветки — они не находятся фильтром '
                   . 'по цвету на витрине.', leybo_admin_url('tovary', ['leybo_fill_colours' => 1]),
                   'Проставить цвета'];
    }
    $dups = leybo_dash_duplicate_titles();
    if ($dups) {
        $n = 0;
        foreach ($dups as $v) { $n += count($v); }
        $todo[] = [$n . ' товаров с одинаковыми названиями — покупатель не поймёт, чем они '
                   . 'отличаются друг от друга.', leybo_admin_url('tovary', ['leybo_fix_titles' => 1]),
                   'Развести названия'];
    }
    if ($s['sold_out']) {
        $todo[] = [$s['sold_out'] . ' товаров распроданы полностью: у них не осталось '
                   . 'ни одного размера. Карточки видны, но кнопка покупки неактивна — '
                   . 'размеры вернутся сами, когда появятся на площадке.', '', ''];
    }

    echo '<h2 style="margin-top:26px">Требует решения</h2>';
    if (!$todo) {
        echo '<p style="color:#008a20;font-weight:600">Ничего. Магазин в порядке.</p>';
    } else {
        echo '<ul style="max-width:820px">';
        foreach ($todo as $t) {
            printf('<li style="margin-bottom:7px">%s%s</li>', esc_html($t[0]),
                $t[1] ? ' — <a href="' . esc_url($t[1]) . '">' . esc_html($t[2]) . '</a>' : '');
        }
        echo '</ul>';
    }
}

/* ==================================================  ПУСТОЙ ЦВЕТ  ======= */

/**
 * Товары витрины без проставленной расцветки.
 *
 * Цвет вычитывается из названия. Когда площадка даёт английское имя без
 * цветового слова («adidas originals SAMBA OG», «Crocs Baya Clog»), вычитывать
 * нечего — и товар молча выпадает из фильтра по цвету: покупатель выбирает
 * «чёрные», а половина чёрных не показывается.
 */
/**
 * Те, у кого цвета нет И кого мы ещё не пробовали заполнить.
 *
 * У части товаров площадка не пишет цвет вообще — в заголовке одна модель и
 * «男女同款». Брать неоткуда, и это не поломка. Если считать их вечно, в сводке
 * навсегда повиснет красная строка, на которую нельзя повлиять, — а к такой
 * строке через неделю перестают присматриваться и пропускают настоящую.
 */
function leybo_dash_colourless_fixable() {
    $out = [];
    foreach (leybo_dash_colourless() as $pid) {
        if (!get_post_meta($pid, '_leybo_color_tried', true)) { $out[] = $pid; }
    }
    return $out;
}

function leybo_dash_colourless() {
    $q = new WP_Query([
        'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1,
        'fields' => 'ids',
        'tax_query' => [[
            'taxonomy' => 'product_visibility', 'field' => 'name',
            'terms' => ['exclude-from-catalog'], 'operator' => 'NOT IN',
        ]],
    ]);
    $out = [];
    foreach ($q->posts as $pid) {
        $c = wp_get_post_terms($pid, LEYBO_COLOR_TAX, ['fields' => 'ids']);
        if (is_wp_error($c) || !$c) { $out[] = $pid; }
    }
    return $out;
}

/**
 * Дозаполнить расцветку из китайского заголовка площадки.
 *
 * Берём оттуда же, откуда цену: площадка называет цвет одним-двумя иероглифами
 * и делает это однозначно. Английское имя для этого не годится — оно и стало
 * причиной пропуска.
 */
function leybo_dash_fill_colours($limit = 20) {
    @ignore_user_abort(true);
    @set_time_limit(600);
    $done = [];
    $tried = 0;
    foreach (leybo_dash_colourless_fixable() as $pid) {
        if ($tried >= $limit) { break; }
        $tried++;
        update_post_meta($pid, '_leybo_color_tried', current_time('mysql'));
        $art = trim((string)get_post_meta($pid, '_leybo_article', true));
        if ($art === '' || !function_exists('leybo_rcn_lookup')) { continue; }
        $d = leybo_rcn_lookup($art);
        $cn = (string)($d['title'] ?? '');
        if ($cn === '') { continue; }
        $n = leybo_build_ru_colours($pid, $cn);
        if ($n) { $done[$pid] = implode(' ', leybo_build_cn_colours($cn)); }
    }
    return $done;
}

/* ============================================  ОДИНАКОВЫЕ НАЗВАНИЯ  ====== */

/**
 * Товары витрины, у которых совпадают названия.
 *
 * Название приходит от площадки, и она не всегда дописывает расцветку: две пары
 * Samba приезжают обе как «adidas originals SAMBA OG». На витрине это два
 * одинаковых товара по разной цене — покупатель не понимает, чем они отличаются,
 * и уходит.
 */
function leybo_dash_duplicate_titles() {
    $q = new WP_Query([
        'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1,
        'fields' => 'ids',
        'tax_query' => [[
            'taxonomy' => 'product_visibility', 'field' => 'name',
            'terms' => ['exclude-from-catalog'], 'operator' => 'NOT IN',
        ]],
    ]);
    $by = [];
    foreach ($q->posts as $pid) {
        $t = mb_strtolower(trim(html_entity_decode(get_the_title($pid), ENT_QUOTES, 'UTF-8')));
        $by[$t][] = $pid;
    }
    return array_filter($by, function ($v) { return count($v) > 1; });
}

/**
 * Развести одинаковые названия.
 *
 * Сначала пробуем расцветку — она уже проставлена у товара как атрибут, значит
 * никуда ходить не надо. Если у пары в группе и цвет один и тот же (бывает: три
 * белых FloatZig — мужской и два женских), различаем артикулом: некрасиво, зато
 * покупатель видит, что товары разные, а не глюк.
 */
function leybo_dash_fix_duplicate_titles() {
    $fixed = [];
    foreach (leybo_dash_duplicate_titles() as $ids) {
        $colours = [];
        foreach ($ids as $pid) {
            $c = wp_get_post_terms($pid, LEYBO_COLOR_TAX, ['fields' => 'names']);
            $colours[$pid] = is_wp_error($c) ? [] : $c;
        }
        $sigs = array_map(function ($c) { return implode(' ', $c); }, $colours);
        $unique_by_colour = count(array_unique($sigs)) === count($sigs);

        foreach ($ids as $pid) {
            $base = trim(html_entity_decode(get_the_title($pid), ENT_QUOTES, 'UTF-8'));
            $suffix = '';
            if ($unique_by_colour && $colours[$pid]) {
                $suffix = ' «' . implode(' ', $colours[$pid]) . '»';
            } else {
                $art = trim((string)get_post_meta($pid, '_leybo_article', true));
                if ($art !== '') { $suffix = ' (' . $art . ')'; }
            }
            if ($suffix === '' || mb_strpos($base, trim($suffix, ' ')) !== false) { continue; }
            $new = $base . $suffix;
            wp_update_post(['ID' => $pid, 'post_title' => $new, 'post_name' => sanitize_title($new)]);
            $fixed[$pid] = $new;
        }
    }
    return $fixed;
}

/* ==================================================  МАССОВЫЙ ВВОД  ====== */

/**
 * Добавить пачку артикулов списком.
 *
 * По одному — годится, когда пришла одна пара. Когда поставщик присылает два
 * десятка, поштучный ввод превращается в двадцать заходов, и на середине
 * забываешь, что уже завёл. Здесь список вставляется целиком, а сборка идёт
 * очередью: каждое нажатие берёт порцию, обрыв связи её не теряет.
 *
 * Разделитель любой — перевод строки, запятая, точка с запятой, пробел: список
 * прилетает из письма или таблицы, и заставлять человека приводить его к одному
 * виду — лишняя работа на ровном месте.
 */
function leybo_dash_mass_add_ui() {
    $done_msg = '';

    if (!empty($_POST['leybo_mass_queue'])) {
        check_admin_referer('leybo_mass_add');
        $raw = (string)wp_unslash($_POST['articles'] ?? '');
        $list = preg_split('~[\s,;]+~u', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        $seen = [];
        $queue = [];
        $skip_exists = 0;
        foreach ($list as $a) {
            $a = strtoupper(trim($a));
            $key = preg_replace('/[\s\-_.]/', '', $a);
            if ($key === '' || isset($seen[$key])) { continue; }
            $seen[$key] = 1;
            if (function_exists('leybo_admin_find_by_article') && leybo_admin_find_by_article($a)) {
                $skip_exists++;
                continue;
            }
            $queue[] = $a;
        }
        update_option(LEYBO_BUILD_QUEUE, $queue, false);
        $done_msg = sprintf(
            'Принято артикулов: <strong>%d</strong>. Уже есть на сайте, пропущено: <strong>%d</strong>.',
            count($queue), $skip_exists);
    }

    if (!empty($_POST['leybo_mass_run'])) {
        check_admin_referer('leybo_mass_add');
        $r = leybo_build_run(6);
        $ok = $err = 0;
        $lines = [];
        foreach ($r['сделано'] as $art => $v) {
            if (is_numeric($v)) {
                $ok++;
                $lines[] = sprintf('<li><code>%s</code> — <a href="%s">%s</a></li>',
                    esc_html($art), esc_url(get_edit_post_link($v)), esc_html(get_the_title($v)));
            } else {
                $err++;
                $lines[] = sprintf('<li><code>%s</code> — <span style="color:#b32d2e">%s</span></li>',
                    esc_html($art), esc_html($v));
            }
        }
        printf('<div class="notice notice-success"><p>Собрано: <strong>%d</strong>, '
             . 'не удалось: <strong>%d</strong>. Осталось в очереди: <strong>%d</strong>.</p>%s</div>',
            $ok, $err, (int)$r['осталось'],
            $lines ? '<ul style="margin:6px 0 0 18px;list-style:disc">' . implode('', $lines) . '</ul>' : '');
    }

    $left = count((array)get_option(LEYBO_BUILD_QUEUE, []));

    echo '<h2>Добавить списком</h2>';
    echo '<p style="max-width:820px">Вставьте артикулы — по одному в строке или через запятую. '
       . 'Те, что уже есть на сайте, будут пропущены.</p>';

    echo '<form method="post" style="margin-bottom:10px">';
    wp_nonce_field('leybo_mass_add');
    echo '<textarea name="articles" rows="6" style="width:520px;font-family:ui-monospace,Consolas,monospace" '
       . 'placeholder="IG1024&#10;JQ5976&#10;HF7545-100"></textarea>';
    echo '<p><button class="button button-primary" name="leybo_mass_queue" value="1">Принять список</button></p>';
    echo '</form>';

    if ($done_msg) { echo '<div class="notice notice-info"><p>' . $done_msg . '</p></div>'; }

    if ($left > 0) {
        echo '<form method="post" style="margin:12px 0;padding:12px 14px;background:#fff;'
           . 'border:1px solid #dcdcde;border-radius:8px;max-width:520px">';
        wp_nonce_field('leybo_mass_add');
        printf('<p style="margin-top:0">В очереди на сборку: <strong>%d</strong>.</p>', $left);
        echo '<p><button class="button button-primary" name="leybo_mass_run" value="1">'
           . 'Собрать порцию (6 штук)</button></p>';
        echo '<p class="description" style="margin-bottom:0">Одна пара собирается около 15 секунд — '
           . 'больше шести за раз страница не выдержит. Нажимайте, пока очередь не опустеет: '
           . 'она запоминает место и не начнёт заново.</p>';
        echo '</form>';
    }
}

/* =========================================================  КАТАЛОГ  ===== */

function leybo_dash_catalog_tab() {
    if (!function_exists('leybo_build_run')) {
        echo '<div class="notice notice-error"><p>Модуль сборки не загружен.</p></div>';
        return;
    }

    if (!empty($_POST['leybo_build_go'])) {
        check_admin_referer('leybo_catalog');
        $cat = leybo_dash_catalog_stats();
        $queue = [];
        foreach ($cat['missing'] as $r) { $queue[] = $r['article']; }
        update_option('leybo_build_queue', $queue, false);
        $r = leybo_build_run(6);
        printf('<div class="notice notice-success"><p>Собрано за этот заход: <strong>%d</strong>. '
             . 'Осталось: <strong>%d</strong>. Жмите ещё раз — очередь продолжится с того же места.</p></div>',
            count($r['сделано']), (int)$r['осталось']);
    }

    $cat = leybo_dash_catalog_stats();

    echo '<p style="max-width:820px">Карточка, собранная из артикула, не может разъехаться '
       . 'с товаром: название, цена, размеры, фото и цвет берутся у площадки. Поэтому пополнять '
       . 'ассортимент лучше отсюда, а не заводить товар руками.</p>';

    printf('<p style="font-size:15px">В каталоге <strong>%d</strong> позиций, '
         . 'в продаже <strong>%d</strong>, можно добрать <strong>%d</strong>.</p>',
        $cat['total'], $cat['on_site'], count($cat['missing']));

    if ($cat['missing']) {
        echo '<form method="post" style="margin:14px 0">';
        wp_nonce_field('leybo_catalog');
        echo '<p><button class="button button-primary" name="leybo_build_go" value="1">'
           . 'Собрать очередную порцию (6 штук)</button>'
           . '<span class="description" style="margin-left:10px">Одна позиция — около 15 секунд, '
           . 'поэтому за раз берём шесть, чтобы страница не отваливалась.</span></p></form>';

        echo '<table class="wp-list-table widefat striped" style="max-width:820px"><thead><tr>'
           . '<th>Артикул</th><th>Бренд</th><th>Модель</th><th>Цвет</th><th>Ориентир ₽</th>'
           . '</tr></thead><tbody>';
        foreach (array_slice($cat['missing'], 0, 60) as $r) {
            printf('<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html($r['article']), esc_html($r['brand'] ?? ''), esc_html($r['model'] ?? ''),
                esc_html($r['color'] ?? ''), esc_html($r['price'] ?? ''));
        }
        echo '</tbody></table>';
        if (count($cat['missing']) > 60) {
            printf('<p class="description">Показаны первые 60 из %d.</p>', count($cat['missing']));
        }
    } else {
        echo '<p style="color:#008a20;font-weight:600">Всё, что площадка умеет отдать, '
           . 'уже в продаже.</p>';
    }

    if ($cat['bad']) {
        echo '<h2 style="margin-top:28px">Площадка не знает артикул</h2>';
        echo '<p style="max-width:820px">Эти позиции есть в таблице, но товара с таким артикулом '
           . 'на площадке нет. Собрать их нельзя: неизвестна ни цена, ни размеры. '
           . '<strong>Вопрос поставщику:</strong> сняты с производства или в таблице опечатка?</p>';
        $chips = array_map(function ($a) { return '<code>' . esc_html($a) . '</code>'; },
                           array_values($cat['bad']));
        echo '<p>' . implode(', ', $chips) . '</p>';
    }
}
