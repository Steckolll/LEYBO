<?php
/**
 * Cart totals
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/cart/cart-totals.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 2.3.6
 */

defined( 'ABSPATH' ) || exit;

function num_word($value, $words, $show = true) 
{
	$num = $value % 100;
	if ($num > 19) { 
		$num = $num % 10; 
	}
	
	$out = ($show) ?  $value . ' ' : '';
	switch ($num) {
		case 1:  $out .= $words[0]; break;
		case 2: 
		case 3: 
		case 4:  $out .= $words[1]; break;
		default: $out .= $words[2]; break;
	}
	
	return $out;
}
$count_prod = count(WC()->cart->get_cart_contents());
?>
<div class="cart_totals <?php echo ( WC()->customer->has_calculated_shipping() ) ? 'calculated_shipping' : ''; ?>">

	<?php do_action( 'woocommerce_before_cart_totals' ); ?>

	<h2 class="cart-subtotal-title m-0">Ваш заказ</h2>
	<div class="cart-subtotal d-flex flex-column">
		<div class="cart-subtotal-row d-flex">
			<div class="cart-subtotal-label"><?php echo num_word($count_prod, array('товар', 'товара', 'товаров'));?></div>
			<div data-title="<?php esc_attr_e( 'Subtotal', 'woocommerce' ); ?>" class="cart-subtotal-value"><?php wc_cart_totals_subtotal_html(); ?></div>
		</div>
		<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
			<div class="cupon_box cart-subtotal-row d-flex cart-discount coupon-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
				<div class="cart-subtotal-label"><?php wc_cart_totals_coupon_label( $coupon ); ?></div>
				<div data-title="<?php echo esc_attr( wc_cart_totals_coupon_label( $coupon, false ) ); ?>"><?php wc_cart_totals_coupon_html( $coupon ); ?></div>
			</div>
		<?php endforeach; ?>
		<div class="cart-subtotal-row d-flex">
			<div class="cart-total-label">Итого</div>
			<div data-title="<?php esc_attr_e( 'Total', 'woocommerce' ); ?>" class="cart-total-value"><?php wc_cart_totals_order_total_html(); ?></div>
		</div>
	</div>

	<div class="wc-proceed-to-checkout">
		<?php do_action( 'woocommerce_proceed_to_checkout' ); ?>
	</div>
	<?php do_action( 'woocommerce_after_cart_totals' ); ?>

</div>

