<?php
/**
 * Review order table
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/review-order.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 5.2.0
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
<div class="cart_totals">
	<h2 class="cart-subtotal-title m-0">Ваш заказ</h2>
	<div class="cart-subtotal d-flex flex-column">
		<div class="cart-subtotal-row d-flex">
			<div class="cart-subtotal-label"><?php echo num_word($count_prod, array('товар', 'товара', 'товаров'));?></div>
			<div data-title="<?php esc_attr_e( 'Subtotal', 'woocommerce' ); ?>" class="cart-subtotal-value"><?php wc_cart_totals_subtotal_html(); ?></div>
		</div>
		<div class="cart-subtotal-row d-flex">
			<div class="cart-total-label">Итого</div>
			<div data-title="<?php esc_attr_e( 'Total', 'woocommerce' ); ?>" class="cart-total-value"><?php wc_cart_totals_order_total_html(); ?></div>
		</div>
	</div>
	<?php do_action( 'woocommerce_review_order_after_cart_contents' );?>
	

