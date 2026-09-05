<?php
/**
 * LEYBO test checkout.
 *
 * YooKassa arrives when Gleb registers the company, but the whole point of the
 * current pass is to prove the chain end to end: cart -> checkout -> paid ->
 * request lands in Raketa. Without any gateway enabled WooCommerce refuses to
 * take an order at all, so the chain cannot be walked.
 *
 * Leaving "cash on delivery" switched on would let a real visitor place a real
 * order on a live shop that cannot yet fulfil one. Instead this gateway is
 * invisible unless you are a shop manager, or you arrived through
 *
 *     https://leybo.store/?leybo_test=<key>
 *
 * which drops a cookie for 30 days. Kirill and Seva walk the shop exactly as a
 * customer would; everyone else sees a shop with no payment methods, which is
 * the truthful state of it today.
 *
 * Paying marks the order paid through the normal WooCommerce path, so every
 * downstream hook - including the Raketa handoff - fires for real.
 */

if (!defined('ABSPATH')) { exit; }

const LEYBO_TESTPAY_COOKIE = 'leybo_test_access';

function leybo_testpay_key() {
    $k = get_option('leybo_testpay_key', '');
    if ($k === '') {
        $k = wp_generate_password(20, false, false);
        update_option('leybo_testpay_key', $k, false);
    }
    return $k;
}

/** ?leybo_test=<key> opens the door, ?leybo_test=off closes it. */
add_action('init', function () {
    if (!isset($_GET['leybo_test'])) { return; }
    $v = sanitize_text_field(wp_unslash($_GET['leybo_test']));
    if ($v === 'off') {
        setcookie(LEYBO_TESTPAY_COOKIE, '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN);
        unset($_COOKIE[LEYBO_TESTPAY_COOKIE]);
        return;
    }
    if (hash_equals(leybo_testpay_key(), $v)) {
        setcookie(LEYBO_TESTPAY_COOKIE, $v, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        $_COOKIE[LEYBO_TESTPAY_COOKIE] = $v;
    }
});

function leybo_testpay_allowed() {
    if (current_user_can('manage_woocommerce')) { return true; }
    if (empty($_COOKIE[LEYBO_TESTPAY_COOKIE])) { return false; }
    return hash_equals(leybo_testpay_key(), sanitize_text_field(wp_unslash($_COOKIE[LEYBO_TESTPAY_COOKIE])));
}

/* ------------------------------------------------------------- the gateway */

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) { return; }

    class LEYBO_Gateway_Test extends WC_Payment_Gateway {

        public function __construct() {
            $this->id                 = 'leybo_test';
            $this->method_title       = 'LEYBO: тестовая оплата';
            $this->method_description = 'Заглушка вместо ЮKassa на время отладки. Видна только менеджерам магазина и тем, кто зашёл по тестовой ссылке.';
            $this->has_fields         = false;
            $this->title              = 'Тестовая оплата (без списания денег)';
            $this->description        = 'Тестовый режим: деньги не списываются, заказ сразу помечается оплаченным и уходит логистам.';
            $this->enabled            = 'yes';
            $this->supports           = ['products'];
            $this->init_form_fields();
            $this->init_settings();
        }

        public function init_form_fields() {
            $this->form_fields = [
                'note' => [
                    'title'       => 'Как включить для себя',
                    'type'        => 'title',
                    'description' => 'Открой <code>' . esc_html(home_url('/?leybo_test=' . leybo_testpay_key())) . '</code> — метод появится на оформлении. Выключить: <code>?leybo_test=off</code>.',
                ],
            ];
        }

        /** Hidden from everyone who has not been let in. */
        public function is_available() {
            return leybo_testpay_allowed();
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $order->add_order_note('Оплачено тестовым методом (реального списания не было).');
            // The normal paid path: fires payment_complete, empties the cart,
            // and lets the Raketa handoff run exactly as it will in production.
            $order->payment_complete('TEST-' . $order_id);
            WC()->cart->empty_cart();
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }
    }

    add_filter('woocommerce_payment_gateways', function ($gws) {
        $gws[] = 'LEYBO_Gateway_Test';
        return $gws;
    });
});

/* --------------------------------------------------- a visible test badge */

/**
 * Nobody should be able to forget the shop is in test mode.
 * The theme's header partial has no wp_body_open and closes </body> itself, so
 * this rides wp_footer as a floating badge instead of a top strip - which also
 * keeps it clear of the absolutely positioned header and the mobile nav bar.
 */
add_action('wp_footer', function () {
    if (!leybo_testpay_allowed()) { return; }
    echo '<div style="position:fixed;left:12px;bottom:84px;z-index:99999;background:#b32d2e;color:#fff;'
       . 'font:600 12px/1.3 sans-serif;padding:8px 12px;border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,.28);max-width:230px">'
       . 'ТЕСТОВЫЙ РЕЖИМ<br><span style="font-weight:400">оплата не списывает деньги</span><br>'
       . '<a href="' . esc_url(add_query_arg('leybo_test', 'off', home_url('/'))) . '" style="color:#fff">выйти из теста</a>'
       . '</div>';
});
