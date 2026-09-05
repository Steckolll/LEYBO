<?php
/**
 * ЛЕЙБО — согласия и чекбоксы по юрпакету от 02.05.2026.
 *
 * Источник текстов: «06_Согласие_и_чекбоксы_ПДн», разделы 1–3.
 * Тексты правятся ТОЛЬКО здесь: в настройках WooCommerce их дублировать нельзя,
 * иначе покупатель увидит две разные формулировки согласия.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Почта для отказа от рассылки (раздел 4.7 юрпакета).
 * Пока пусто — в тексте вместо неё ссылка на страницу с полным текстом согласия.
 */
const LEYBO_UNSUB_EMAIL = '';

function leybo_legal_link( $slug, $label ) {
	return '<a href="' . esc_url( home_url( '/' . $slug . '/' ) ) . '" target="_blank" rel="noopener">' . $label . '</a>';
}

/* ------------------------------------------------------------------ *
 * Раздел 1. Акцепт оферты при оформлении заказа.
 * Обязательный, не предустановленный — это и есть акцепт по п. 3.3 оферты.
 * ------------------------------------------------------------------ */

add_filter( 'woocommerce_get_terms_and_conditions_checkbox_text', function () {
	return 'Нажимая кнопку «Оформить заказ» / «Подтвердить и оплатить», я подтверждаю, что ознакомлен(-а) и согласен(-на) с условиями '
		. leybo_legal_link( 'publichnaya-oferta', 'Публичной оферты' ) . ', '
		. leybo_legal_link( 'delivery', 'Условиями организации доставки' ) . ', '
		. leybo_legal_link( 'usloviya-vozvrata', 'Условиями отказа от поручения, содействия возврату и обмену товара' )
		. ', а также подтверждаю ознакомление с '
		. leybo_legal_link( 'privacy-policy', 'Политикой обработки персональных данных' )
		. '. Мне понятно, что персональные данные, необходимые для оформления и исполнения заказа, обрабатываются Оператором на основании договора и требований закона, а не на основании отдельного согласия.';
} );

/* ------------------------------------------------------------------ *
 * Раздел 2. Регистрация личного кабинета.
 * WooCommerce умеет только текст без чекбокса, поэтому чекбокс свой.
 * ------------------------------------------------------------------ */

function leybo_register_consent_text() {
	return 'Регистрируясь на сайте, я принимаю условия '
		. leybo_legal_link( 'polzovatelskoe-soglashenie', 'Пользовательского соглашения' )
		. ' и подтверждаю ознакомление с '
		. leybo_legal_link( 'privacy-policy', 'Политикой обработки персональных данных' )
		. '. Мне понятно, что персональные данные, необходимые для регистрации и использования личного кабинета, обрабатываются Оператором на основании Пользовательского соглашения.';
}

add_action( 'woocommerce_register_form', function () {
	$checked = isset( $_POST['leybo_register_consent'] ); // phpcs:ignore WordPress.Security.NonceVerification
	?>
	<p class="form-row form-row-wide leybo-consent">
		<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
			<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="leybo_register_consent" id="leybo_register_consent" value="1" required <?php checked( $checked ); ?> />
			<span><?php echo wp_kses_post( leybo_register_consent_text() ); ?></span>&nbsp;<abbr class="required" title="обязательно">*</abbr>
		</label>
	</p>
	<?php
}, 25 );

add_filter( 'woocommerce_registration_errors', function ( $errors ) {
	if ( empty( $_POST['leybo_register_consent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$errors->add( 'leybo_register_consent', 'Чтобы зарегистрироваться, примите условия Пользовательского соглашения.' );
	}
	return $errors;
} );

/* ------------------------------------------------------------------ *
 * Раздел 3. Рекламная рассылка.
 * Отдельный, НЕобязательный, не предустановленный — объединять его
 * с акцептом оферты юрпакет прямо запрещает.
 * ------------------------------------------------------------------ */

function leybo_marketing_consent_text() {
	$optout = LEYBO_UNSUB_EMAIL
		? 'направив отказ на <a href="mailto:' . esc_attr( LEYBO_UNSUB_EMAIL ) . '">' . esc_html( LEYBO_UNSUB_EMAIL ) . '</a>'
		: 'направив отказ способом, указанным в ' . leybo_legal_link( 'soglasie-na-reklamnuyu-rassylku', 'тексте согласия' );

	return 'Я добровольно даю согласие на получение от Оператора рекламных, информационных и маркетинговых сообщений о товарах, услугах, акциях и специальных предложениях leybo.store по электронной почте, SMS, в мессенджерах и/или по телефону. Я могу отказаться от рассылки в любой момент по ссылке в сообщении, '
		. $optout . ' либо иным способом, указанным в сообщении.';
}

add_action( 'woocommerce_checkout_after_terms_and_conditions', function () {
	?>
	<p class="form-row leybo-consent leybo-consent--marketing">
		<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
			<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="leybo_marketing_consent" id="leybo_marketing_consent" value="1" />
			<span><?php echo wp_kses_post( leybo_marketing_consent_text() ); ?></span>
		</label>
	</p>
	<?php
} );

/* ------------------------------------------------------------------ *
 * Шаблон темы woocommerce/checkout/payment.php собран студией без вызова
 * wc_get_template('checkout/terms.php') — из-за этого блок согласий на оформлении
 * не рисуется вообще, даже когда страница условий назначена.
 * Возвращаем его на место через единственный уцелевший рядом фильтр.
 * Если тему когда-нибудь починят — инъекция отключится сама.
 * ------------------------------------------------------------------ */

function leybo_theme_renders_terms() {
	static $renders = null;
	if ( null === $renders ) {
		$tpl     = get_stylesheet_directory() . '/woocommerce/checkout/payment.php';
		$renders = file_exists( $tpl ) && false !== strpos( (string) file_get_contents( $tpl ), 'checkout/terms.php' );
	}
	return $renders;
}

add_filter( 'woocommerce_order_button_html', function ( $button ) {
	if ( leybo_theme_renders_terms() || ! function_exists( 'wc_get_template' ) ) {
		return $button;
	}
	ob_start();
	wc_get_template( 'checkout/terms.php' );
	return ob_get_clean() . $button;
} );

/**
 * Раздел 4.1 требует хранить сам факт согласия, дату, время и источник —
 * иначе законность рассылки нечем подтвердить.
 */
add_action( 'woocommerce_checkout_update_order_meta', function ( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	$given = ! empty( $_POST['leybo_marketing_consent'] ); // phpcs:ignore WordPress.Security.NonceVerification
	$order->update_meta_data( '_leybo_marketing_consent', $given ? 'yes' : 'no' );
	if ( $given ) {
		$order->update_meta_data( '_leybo_marketing_consent_at', current_time( 'mysql' ) );
		$order->update_meta_data( '_leybo_marketing_consent_source', 'checkout' );
	}
	$order->save();

	if ( $given && $order->get_user_id() ) {
		update_user_meta( $order->get_user_id(), 'leybo_marketing_consent', 'yes' );
		update_user_meta( $order->get_user_id(), 'leybo_marketing_consent_at', current_time( 'mysql' ) );
	}
} );

/** Чтобы согласие было видно в карточке заказа, а не только в базе. */
add_action( 'woocommerce_admin_order_data_after_billing_address', function ( $order ) {
	$v = $order->get_meta( '_leybo_marketing_consent' );
	if ( ! $v ) {
		return;
	}
	$at = $order->get_meta( '_leybo_marketing_consent_at' );
	echo '<p><strong>Согласие на рассылку:</strong> '
		. ( 'yes' === $v ? 'да' . ( $at ? ' (' . esc_html( $at ) . ')' : '' ) : 'нет' )
		. '</p>';
} );

/** Чекбоксы согласий отдельной строкой, а не в одну строку с полем. */
add_action( 'wp_head', function () {
	if ( ! function_exists( 'is_checkout' ) || ( ! is_checkout() && ! is_account_page() ) ) {
		return;
	}
	// !important здесь по делу: тема жёстко задаёт display:inline для label в формах WooCommerce.
	echo '<style>.leybo-consent{display:block;margin:14px 0}'
		. '.leybo-consent label.checkbox{display:flex!important;align-items:flex-start;gap:10px;font-size:13px;line-height:1.5;cursor:pointer}'
		. '.leybo-consent label.checkbox input{flex:0 0 auto;margin:3px 0 0;width:auto}'
		. '.leybo-consent a{text-decoration:underline}</style>';
} );
