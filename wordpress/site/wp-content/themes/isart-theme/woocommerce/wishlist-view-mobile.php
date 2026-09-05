<?php

/**
 * Wishlist page template - Standard Layout
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Wishlist\Templates\Wishlist\View
 * @version 3.0.0
 */

/**
 * Template variables:
 *
 * @var $wishlist                      \YITH_WCWL_Wishlist Current wishlist
 * @var $wishlist_items                array Array of items to show for current page
 * @var $wishlist_token                string Current wishlist token
 * @var $wishlist_id                   int Current wishlist id
 * @var $users_wishlists               array Array of current user wishlists
 * @var $pagination                    string yes/no
 * @var $per_page                      int Items per page
 * @var $current_page                  int Current page
 * @var $page_links                    array Array of page links
 * @var $is_user_owner                 bool Whether current user is wishlist owner
 * @var $show_price                    bool Whether to show price column
 * @var $show_dateadded                bool Whether to show item date of addition
 * @var $show_stock_status             bool Whether to show product stock status
 * @var $show_add_to_cart              bool Whether to show Add to Cart button
 * @var $show_remove_product           bool Whether to show Remove button
 * @var $show_price_variations         bool Whether to show price variation over time
 * @var $show_variation                bool Whether to show variation attributes when possible
 * @var $show_cb                       bool Whether to show checkbox column
 * @var $show_quantity                 bool Whether to show input quantity or not
 * @var $show_ask_estimate_button      bool Whether to show Ask an Estimate form
 * @var $show_last_column              bool Whether to show last column (calculated basing on previous flags)
 * @var $move_to_another_wishlist      bool Whether to show Move to another wishlist select
 * @var $move_to_another_wishlist_type string Whether to show a select or a popup for wishlist change
 * @var $additional_info               bool Whether to show Additional info textarea in Ask an estimate form
 * @var $price_excl_tax                bool Whether to show price excluding taxes
 * @var $enable_drag_n_drop            bool Whether to enable drag n drop feature
 * @var $repeat_remove_button          bool Whether to repeat remove button in last column
 * @var $available_multi_wishlist      bool Whether multi wishlist is enabled and available
 * @var $no_interactions               bool
 */

if (! defined('YITH_WCWL')) {
	exit;
} // Exit if accessed directly
?>
<?php if ($wishlist && $wishlist->has_items()) : ?>
	<div class="catalog_list d-grid col-4 gap-2 ajax_pagination <?php echo $no_interactions ? 'no-interactions' : ''; ?> <?php echo $enable_drag_n_drop ? 'sortable' : ''; ?> "
		data-pagination="<?php echo esc_attr($pagination); ?>" data-per-page="<?php echo esc_attr($per_page); ?>" data-page="<?php echo esc_attr($current_page); ?>"
		data-id="<?php echo esc_attr($wishlist_id); ?>" data-token="<?php echo esc_attr($wishlist_token); ?>">
		<?php

		foreach ($wishlist_items as $item) :
			/**
			 * Each of the wishlist items
			 *
			 * @var $item \YITH_WCWL_Wishlist_Item
			 */
			global $product;

			$product = $item->get_product();

			if ($product && $product->exists()) :
				$image_id = $product->get_image_id();
		?>
				<a href="<?php echo esc_url(get_permalink(apply_filters('woocommerce_in_cart_product', $item->get_product_id()))); ?>" class="product-card">
					<div class="product-image position-relative">
						<object>
							<a href="<?php echo esc_url($item->get_remove_url()); ?>" class="remove remove_from_wishlist" title="<?php echo esc_html(apply_filters('yith_wcwl_remove_product_wishlist_message_title', __('Remove this product', 'yith-woocommerce-wishlist'))); ?>">
								<img src="<?php echo get_template_directory_uri(); ?>/assets/images/fav3.svg" alt="">
							</a>
						</object>
						<?php if ($image_id): ?>
							<img class="w-100 h-100 object-cover-50" src="<?php echo wp_get_attachment_image_url($image_id, 'full'); ?>" alt="">
						<?php else: ?>
							<img class="w-100 h-100 object-cover-50" src="<?php echo get_site_link(); ?>/wp-content/uploads/woocommerce-placeholder.png" alt="">
						<?php endif; ?>
						<div class="product-card-bottom position-absolute">
							<div class="tags_list d-flex">
								<?php if (get_field('новинка', $product->id)): ?>
									<div class="prod_tag">New</div>
								<?php endif; ?>
								<?php if (get_field('хит', $product->id)): ?>
									<div class="prod_tag">Hit</div>
								<?php endif; ?>
							</div>

							<object>
								<a href="?add-to-cart='<?php echo $item['product_id']; ?>'" data-quantity="1" data-product_id="<?php echo $item['product_id']; ?>" class="btn btn_buy button add_to_cart_button ajax_add_to_cart d-flex align-items-center justify-content-center w-100">Добавить в корзину</a>
							</object>

						</div>

					</div>
					<div
						class="product-info">
						<div class="product-prices text-center">
							<div class="current-price nunito"><?php echo number_format($product->price, 0, '', ' ') ?> ₽</div>
						</div>
						<h2 class="product-card-name text-center m-0"><?php echo $product->name; ?></h2>
					</div>
				</a>
		<?php
			endif;
		endforeach;
		?>
	</div>
<?php else: ?>

	<?php
	/**
	 * APPLY_FILTERS: yith_wcwl_no_product_to_remove_message
	 *
	 * Filter the message shown when there are no products in the wishlist.
	 *
	 * @param string             $message  Message
	 * @param YITH_WCWL_Wishlist $wishlist Wishlist object
	 *
	 * @return string
	 */
	?>
	<div class="empty-cart d-flex flex-column align-items-center">
		<div class="empty-cart-title">В избранном пусто</div>
		<div class="empty-cart-text">Перейдите в каталог, чтобы выбрать товары, или используйте поиск, чтобы найти необходимое.</div>
		<a class="btn btn_buy btn_back-shop d-flex align-items-center justify-content-center" href="<?php echo get_site_url(); ?>">
			На главную
		</a>
	</div>
<?php endif; ?>