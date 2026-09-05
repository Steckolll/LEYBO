<?php
/**
 * Курс юаня — ежедневно с сайта ЦБ РФ.
 *
 * Зачем: `leybo_rate` был константой 11.3916 и протух. На 07.08.2026 ЦБ даёт
 * 12.0637 — то есть себестоимость занижалась ещё до всяких агентских.
 *
 * Как считается себестоимость (см. leybo-pricing.php):
 *     cost = cny * leybo_rate * (1 + leybo_agent_fee/100)
 * Здесь `leybo_rate` — чистый курс ЦБ, а `leybo_agent_fee` — работа Ракеты.
 * Держать их порознь важно: курс меняется каждый день сам, надбавка — редко и
 * руками. Если смешать, при каждом движении курса надбавка будет уезжать вместе
 * с ним и никто не поймёт, сколько мы реально зарабатываем.
 *
 * Замер надбавки (живой ответ API Ракеты 31.07.2026): 215 ¥ обошлись в 2801.39 ₽,
 * это 13.0297 ₽/¥ при курсе ЦБ того дня 11.8194 → +10.24%.
 *
 * Осторожность: если ЦБ недоступен или прислал бессмыслицу, старое значение
 * остаётся. Молча обнулить курс на боевом магазине — худшее, что может сделать
 * этот файл.
 */

if (!defined('ABSPATH')) { exit; }

const LEYBO_RATE_HOOK   = 'leybo_daily_rate';
const LEYBO_RATE_OPT    = 'leybo_rate';
const LEYBO_RATE_LOG    = 'leybo_rate_log';
const LEYBO_RATE_MIN    = 5.0;    // границы вменяемости: юань вне 5..30 ₽ —
const LEYBO_RATE_MAX    = 30.0;   // это ошибка разбора, а не курс

/** Курс CNY с ЦБ РФ. -> float | WP_Error */
function leybo_fetch_cbr_rate() {
    $r = wp_remote_get('https://www.cbr.ru/scripts/XML_daily.asp', ['timeout' => 20]);
    if (is_wp_error($r)) { return $r; }
    if (wp_remote_retrieve_response_code($r) !== 200) {
        return new WP_Error('http', 'ЦБ ответил ' . wp_remote_retrieve_response_code($r));
    }
    $xml = wp_remote_retrieve_body($r);

    // ЦБ отдаёт windows-1251; нам нужны только цифры, но без перекодировки
    // SimpleXML споткнётся о кириллицу в названиях валют.
    if (stripos($xml, 'encoding="windows-1251"') !== false) {
        $xml = str_ireplace('encoding="windows-1251"', 'encoding="UTF-8"', $xml);
        $xml = mb_convert_encoding($xml, 'UTF-8', 'Windows-1251');
    }
    if (!preg_match('~<Valute ID="R01375">.*?<VunitRate>([\d,\.]+)</VunitRate>~s', $xml, $m)) {
        return new WP_Error('parse', 'CNY (R01375) не найден в ответе ЦБ');
    }
    $rate = (float) str_replace(',', '.', $m[1]);
    if ($rate < LEYBO_RATE_MIN || $rate > LEYBO_RATE_MAX) {
        return new WP_Error('sanity', 'Курс вне разумных границ: ' . $rate);
    }
    $date = preg_match('~Date="([^"]+)"~', $xml, $d) ? $d[1] : '';
    return ['rate' => $rate, 'date' => $date];
}

/** Ежедневное обновление. -> массив с результатом (его же пишем в лог). */
function leybo_update_rate($manual = false) {
    $old = (float) get_option(LEYBO_RATE_OPT, 0);
    $got = leybo_fetch_cbr_rate();

    if (is_wp_error($got)) {
        $res = ['ok' => false, 'error' => $got->get_error_message(),
                'kept' => $old, 'at' => current_time('mysql'), 'manual' => $manual];
    } else {
        update_option(LEYBO_RATE_OPT, $got['rate']);
        $res = ['ok' => true, 'old' => $old, 'new' => $got['rate'],
                'cbr_date' => $got['date'], 'at' => current_time('mysql'),
                'manual' => $manual];
    }

    $log = (array) get_option(LEYBO_RATE_LOG, []);
    array_unshift($log, $res);
    update_option(LEYBO_RATE_LOG, array_slice($log, 0, 30));
    return $res;
}
add_action(LEYBO_RATE_HOOK, 'leybo_update_rate');

// Расписание. ЦБ публикует курс на следующий день около 15:00 МСК, поэтому
// раннее утро — заведомо после публикации и до рабочего дня магазина.
add_action('init', function () {
    if (!wp_next_scheduled(LEYBO_RATE_HOOK)) {
        $msk = new DateTimeZone('Europe/Moscow');
        $next = new DateTime('tomorrow 06:30', $msk);
        wp_schedule_event($next->getTimestamp(), 'daily', LEYBO_RATE_HOOK);
    }
});

/** Показываем курс и лог в WooCommerce → Ракета, рядом с остальными настройками. */
add_action('admin_notices', function () {
    if (!current_user_can('manage_woocommerce')) { return; }
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'leybo') === false) { return; }
    $log = (array) get_option(LEYBO_RATE_LOG, []);
    $last = $log[0] ?? null;
    $rate = (float) get_option(LEYBO_RATE_OPT, 0);
    $fee  = (float) get_option('leybo_agent_fee', 0);
    echo '<div class="notice notice-info"><p><b>Курс юаня:</b> ' . esc_html($rate) .
         ' ₽ &nbsp;|&nbsp; <b>надбавка Ракеты:</b> ' . esc_html($fee) . '%' .
         ' &nbsp;|&nbsp; себестоимость 1 ¥ = <b>' .
         esc_html(round($rate * (1 + $fee / 100), 4)) . ' ₽</b>';
    if ($last) {
        echo '<br><small>последнее обновление: ' . esc_html($last['at']) .
             ($last['ok'] ? ' — ок' : ' — ошибка: ' . esc_html($last['error'])) . '</small>';
    }
    echo '</p></div>';
});

// Разовый ручной запуск по секретной ссылке — чтобы не ждать сутки первого крона.
add_action('admin_init', function () {
    if (empty($_GET['leybo_rate_now']) || !current_user_can('manage_woocommerce')) { return; }
    $r = leybo_update_rate(true);
    wp_die('<pre>' . esc_html(print_r($r, true)) . '</pre>', 'Курс', ['response' => 200]);
});
