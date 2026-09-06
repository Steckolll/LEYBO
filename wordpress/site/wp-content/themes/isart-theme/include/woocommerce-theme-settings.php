<?php
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_title', 5);
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10);
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 10);
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20);
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_sharing', 50);
//Корзина вверху
add_filter('woocommerce_add_to_cart_fragments', 'woocommerce_header_add_to_cart_fragment');

function woocommerce_header_add_to_cart_fragment($fragments)
{
	global $woocommerce;
	ob_start();
	my_wc_cart_count();
	$fragments['.main-header-cart'] = ob_get_clean();
	return $fragments;
}
function my_wc_cart_count()
{
	global $woocommerce; ?>

	<a href="<?= get_site_url(); ?>/cart" class="btn main-header-cart d-flex flex-column justify-content-center align-items-center position-relative">
		<span class="main-header-cart--icon position-relative">
			<img src="<?php echo get_template_directory_uri(); ?>/assets/images/cart.svg" alt="">
			<span class="main-header-cart--count d-flex align-items-center justify-content-center position-absolute"><?php echo count(WC()->cart->get_cart_contents()); ?></span>
		</span>
	</a>
	<a href="<?= get_site_url(); ?>/cart" class="mobile-nav-item d-flex flex-column justify-content-between align-items-center">
		<div class="mobile-nav-item--icon position-relative">
			<img src="<?php echo get_template_directory_uri(); ?>/assets/images/cart.svg" alt="">
			<span class="main-header-cart--count d-flex align-items-center justify-content-center position-absolute"><?php echo count(WC()->cart->get_cart_contents()); ?></span>
		</div>
		<div class="mobile-nav-item--name">Корзина</div>
	</a>

<?php
}
add_action('header_action', 'my_wc_cart_count');



add_action('woocommerce_before_quantity_input_field', 'truemisha_quantity_plus', 25);
add_action('woocommerce_after_quantity_input_field', 'truemisha_quantity_minus', 25);

function truemisha_quantity_plus()
{
	echo '<button type="button" class="btn minus d-flex align-items-center justify-content-center">-</button>';
}

function truemisha_quantity_minus()
{
	echo '<button type="button" class="btn plus d-flex align-items-center justify-content-center">+</button>';
}


add_filter('woocommerce_cart_needs_payment', '__return_false');

add_action('woocommerce_review_order_before_submit', 'add_privacy_checkbox', 9);
function add_privacy_checkbox()
{
	woocommerce_form_field('privacy_policy', array(
		'type' => 'checkbox',
		'class' => array('form-row privacy'),
		'label_class' => array('woocommerce-form__label woocommerce-form__label-for-checkbox checkbox custom-checkbox d-flex align-items-center'),
		'input_class' => array('woocommerce-form__input woocommerce-form__input-checkbox input-checkbox'),
		'required' => true,
		'label' => '<span class="custom-checkbox_name">Нажимая кнопку «Оформить заказ», я даю свое согласие на обработку моих  персональных данных, в соответствии с Федеральным законом от 27.07.2006  года №152-ФЗ «О персональных данных», на условиях и для целей,  определенных в Согласии на обработку персональных данных</span>',
	));
}
add_action('woocommerce_checkout_process', 'privacy_checkbox_error_message');
function privacy_checkbox_error_message()
{
	if (!(int) isset($_POST['privacy_policy'])) {
		wc_add_notice(__('Вам нужно принять политику конфиденциальности'), 'error');
	}
}

add_action('pre_get_posts', 'custom_pre_get_posts_query');
function custom_pre_get_posts_query($q)
{
	if (!is_page() and !is_single()) {
		if (!$q->is_main_query()) {
			return;
		}

		if (!is_admin()) {
			$cat_obj = $q->get_queried_object();
			if ($cat_obj->slug == 'izgotovlenie-na-zakaz') {
				$q->set(
					'tax_query',
					[
						[
							'taxonomy' => 'product_tag',
							'field' => 'slug',
							'terms' => 'usluga',
							'operator' => 'NOT IN'
						]
					]
				);
			}
		}
	}

	remove_action('pre_get_posts', 'custom_pre_get_posts_query');
}

function product_render($post)
{
	// setup_postdata($post);
	global $product;
	$product = wc_get_product($post->ID);
	$categories = get_the_terms($post->ID, 'product_cat');
	$context["product"] = $product;
	if ($product->get_type() == "variable") {
		$context["min_price"] = $product->get_variation_price( 'min', true );
		$context["max_price"] = $product->get_variation_price( 'max', true );
	}
	$image_id = $product->get_image_id();
	if ($image_id) {
		$context['thumb'] = wp_get_attachment_image_url($image_id, 'full');
	}
	$context['id'] = $product->get_id();
	// $context['thumb'] = get_the_post_thumbnail_url();
	$context['title'] = $product->get_title();
	$context['link'] = $product->get_permalink();
	$context['price'] = $product->get_price();
	$context['sitethemelink'] = get_template_directory_uri();
	$context['sitelink'] = get_site_url();
	$context['stock_status'] = $product->get_stock_status();
	$context['prod_type'] = $product->get_type();
	$context['newest'] = get_field('новинка', $context['id']);
	$context['hit'] = get_field('хит', $context['id']);

	// C-direction: честные бейдж скидки и наличие (этап 3).
	$context['in_stock'] = ($context['stock_status'] === 'instock');
	$context['sale_price'] = '';
	$context['sale_percent'] = 0;
	if ($product->is_on_sale()) {
		if ($product->get_type() === 'variable') {
			$reg = (float) $product->get_variation_regular_price('min', true);
			$sale = (float) $product->get_variation_sale_price('min', true);
		} else {
			$reg = (float) $product->get_regular_price();
			$sale = (float) $product->get_sale_price();
		}
		if ($reg > 0 && $sale > 0 && $sale < $reg) {
			$context['sale_percent'] = (int) round(($reg - $sale) / $reg * 100);
			$context['sale_price'] = $sale;
		}
	}


	Timber::render('partials/product-item.twig', $context);
}


add_action('wp_footer', function() {
    if (!class_exists('WooCommerce')) return;
    ?>
    <script>
    (function($) {
        $(document).ajaxComplete(function(event, xhr, settings) {
            // Фильтруем только запросы плагина XT для одиночного товара
            if (settings.url && settings.url.indexOf('wc-ajax=xt_atc_single') !== -1) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response && response.fragments) {
                        // Проходим по всем фрагментам и заменяем HTML
                        $.each(response.fragments, function(selector, html) {
                            if ($(selector).length) {
                                $(selector).replaceWith(html);
                            }
                        });
                        // Инициируем стандартные события WooCommerce
                        $(document.body).trigger('wc_fragments_refreshed', [response.fragments]);
                        $(document.body).trigger('added_to_cart', [response.fragments]);
                        // Обновляем мини-корзину (на всякий случай)
                        $(document.body).trigger('wc_fragment_refresh');
                    }
                } catch(e) {
                    console.warn('Ошибка парсинга ответа XT:', e);
                }
                // Снимаем прелоадер с кнопки
                $('.single_add_to_cart_button, .xt_atc_single').removeClass('loading');
            }
        });
    })(jQuery);
    </script>
    <?php
}, 999);