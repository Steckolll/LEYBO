<?php
if (!class_exists('Timber')) {
    echo 'Timber not activated. Make sure you activate the plugin in <a href="/wp-admin/plugins.php#timber">/wp-admin/plugins.php</a>';
    return;
}


$context = Timber::get_context();
$templates = array('archive-product.twig');

$context['cat_desc'] = category_description();
// WooCommerce Notices
$context['wc_notices'] = wc_get_notices();
wc_clear_notices();


if (is_singular('product')) {
    $context['post'] = new TimberPost();
    $product = wc_get_product($context['post']->ID);
    $context['product'] = $product;
    $context['attachment_ids'] = $attachment_ids;
    $context['regular_price'] = $product->get_regular_price();
    $context['sale_price'] = $product->get_sale_price();
    $context['variable_product'] = $product->is_type('variable');

    Timber::render('single-product.twig', $context);
} else {
    $posts = Timber::get_posts();
    $context['products'] = $posts;

    $context['title'] = 'Каталог';

    if (get_queried_object()->taxonomy == 'product_cat') {
        $context['current_product_cat'] = get_queried_object()->slug;
    }

    if (get_queried_object()->taxonomy == 'brand_product') {
        $queried_object = get_queried_object();
        $term_id = $queried_object->term_id;
        $term = get_term($term_id, 'brand_product');
        $context['category'] = $term;
        $context['category_slug'] = $term->slug;
        $context['category_count'] = $term->count;
        $context['title'] = single_term_title('', false);
    }

    if (is_product_category()) {
        $queried_object = get_queried_object();
        $term_id = $queried_object->term_id;
        $term = get_term($term_id, 'product_cat');
        $context['category'] = $term;
        $context['category_slug'] = $term->slug;
        $context['category_count'] = $term->count;
        $context['title'] = single_term_title('', false);
        array_unshift($templates, 'taxonomy-product_cat-' . $term->slug . '.twig');
    }


    Timber::render($templates, $context);
}