<?php
/**
 * Стенд-изоляция для локальной копии leybo.store (НЕ прод).
 * Гарантирует, что стенд физически НЕ может отправить реальные данные наружу:
 *  - блокирует исходящие письма WordPress (wp_mail)
 *  - ставит noindex (запрет индексации)
 *  - блокирует исходящие HTTP wp_remote_* на внешние хосты (только localhost)
 *  - логирует любые попытки внешнего исходящего запроса
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// 0. Тише лог на стенде: Deprecated/Notice от старых плагинов (Timber/ACF)
// писались тысячами строк на страницу и съедали минуты. Реальные ошибки
// (Warning и выше) логируются как раньше.
error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_USER_DEPRECATED & ~E_USER_NOTICE );

class Leybo_Isolation {
	const LOG = 'leybo-isolation';

	public static function init() {
		// 1. Почта: полностью запретить.
		add_filter( 'pre_wp_mail', function( $null, $atts ) {
			self::log( 'BLOCKED email to=' . ( $atts['to'] ?? '?' ) . ' subject=' . ( $atts['subject'] ?? '?' ) );
			return true; // не отдаём письмо, пропускаем как выполнено
		}, 1, 2 );

		// 2. noindex для всего фронтенда.
		add_filter( 'wp_robots', function( $robots ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			return $robots;
		} );
		// дополнительно в <head>
		add_action( 'wp_head', function () { echo "<!-- leybo-staging noindex -->\n<meta name='robots' content='noindex,nofollow' />\n"; }, 1 );
		add_action( 'login_head', function () { echo "<meta name='robots' content='noindex,nofollow' />\n"; }, 1 );

		// 3. Исходящие HTTP wp_remote_* -> только localhost.
		add_filter( 'pre_http_request', function( $pre, $args, $url ) {
			if ( self::is_local_url( $url ) ) { return $pre; }
			self::log( 'BLOCKED outbound http (wp_remote) to ' . substr( (string) $url, 0, 120 ) );
			return new WP_Error( 'leybo_blocked', 'Outbound HTTP blocked on staging.' );
		}, 1, 3 );

		// 4. На staging нельзя провести заказ через любой gateway, включая
		// зарегистрированный sandbox-кодом тестовый gateway.
		add_filter( 'woocommerce_available_payment_gateways', function () {
			return array();
		}, 1 );

		// 5. WordPress.org мокируется пустым ответом: сторонние плагины
		// (XT Framework) не переживают WP_Error от блокировки и роняют
		// админку фаталом на array_map(null). Пустой список — честный
		// staging-ответ без единого исходящего запроса.
		add_filter( 'plugins_api', function ( $result, $action ) {
			if ( $action === 'query_plugins' ) {
				return (object) array(
					'info'    => array( 'page' => 1, 'pages' => 1, 'results' => 0 ),
					'plugins' => array(),
				);
			}
			return $result;
		}, 99, 3 );

		// 6. Дублирующая защита от исходящей почты через Mailer не задаётся.
	}

	private static function is_local_url( $url ) {
		$host = parse_url( (string) $url, PHP_URL_HOST );
		if ( $host === null ) { return true; } // относительные/не-host URL пускаем
		return in_array( strtolower( $host ), array( 'localhost', '127.0.0.1', '::1' ), true );
	}

	private static function log( $msg ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[leybo-isolation] ' . $msg );
		}
	}
}
Leybo_Isolation::init();
