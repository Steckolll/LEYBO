<?php
if (class_exists('Timber')) {
	Timber::$cache = false;
}
//Скрытие версии wp
add_filter('the_generator', '__return_empty_string');

//TODO: Отключение авторизации rest. Удалить на production
function wc_authenticate_alter()
{
	return new WP_User(1);
}
add_filter('woocommerce_api_check_authentication', 'wc_authenticate_alter', 1);
add_filter('woocommerce_rest_check_permissions', 'my_woocommerce_rest_check_permissions', 90, 4);
function my_woocommerce_rest_check_permissions($permission, $context, $object_id, $post_type)
{
	return true;
}

include_once(get_template_directory() . '/include/Timber/Integrations/WooCommerce/WooCommerce.php');
include_once(get_template_directory() . '/include/Timber/Integrations/WooCommerce/ProductsIterator.php');
include_once(get_template_directory() . '/include/Timber/Integrations/WooCommerce/Product.php');

add_action('after_setup_theme', function () {
	add_theme_support('woocommerce');
});

if (class_exists('WooCommerce')) {
	Timber\Integrations\WooCommerce\WooCommerce::init();
}


remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('admin_print_scripts', 'print_emoji_detection_script');
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('admin_print_styles', 'print_emoji_styles');
remove_filter('the_content_feed', 'wp_staticize_emoji');
remove_filter('comment_text_rss', 'wp_staticize_emoji');
remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
add_filter('tiny_mce_plugins', 'disable_wp_emojis_in_tinymce');
function disable_wp_emojis_in_tinymce($plugins)
{
	if (is_array($plugins)) {
		return array_diff($plugins, array('wpemoji'));
	} else {
		return array();
	}
}
function true_remove_default_widget()
{
	unregister_widget('WP_Widget_Archives');
	unregister_widget('WP_Widget_Calendar');
	unregister_widget('WP_Widget_Categories');
	unregister_widget('WP_Widget_Meta');
	unregister_widget('WP_Widget_Pages');
	unregister_widget('WP_Widget_Recent_Comments');
	unregister_widget('WP_Widget_Recent_Posts');
	unregister_widget('WP_Widget_RSS');
	unregister_widget('WP_Widget_Search');
	unregister_widget('WP_Widget_Tag_Cloud');
	unregister_widget('WP_Widget_Text');
	unregister_widget('WP_Nav_Menu_Widget');
}


add_filter('woocommerce_enqueue_styles', '__return_empty_array');


add_action('widgets_init', 'true_remove_default_widget', 20);
add_theme_support('post-thumbnails');

register_nav_menus(array(
	'menu_women' => 'Меню женщинам',
	'menu_men' => 'Меню мужчинам',
	'menu_women2' => 'Меню женщинам 2',
	'menu_men2' => 'Меню мужчинам 2',
	'main_menu' => 'Общее меню',
	'footer_menu' => 'Меню в подвале',
));

function add_async_forscript($url)
{
	if (strpos($url, '#asyncload') === false)
		return $url;
	else if (is_admin())
		return str_replace('#asyncload', '', $url);
	else
		return str_replace('#asyncload', '', $url) . "' defer='defer";
}

add_filter('clean_url', 'add_async_forscript', 11, 1);
function time_enqueuer($my_handle, $relpath, $type = 'script', $async = 'false', $media = "all", $my_deps = array())
{
	if ($async == 'true') {
		$uri = get_theme_file_uri($relpath . '#asyncload');
	} else {
		$uri = get_theme_file_uri($relpath);
	}
	$vsn = filemtime(get_theme_file_path($relpath));
	if ($type == 'script')
		wp_enqueue_script($my_handle, $uri, $my_deps, $vsn);
	else if ($type == 'style')
		wp_enqueue_style($my_handle, $uri, $my_deps, $vsn, $media);
}

add_action('wp_footer', 'add_scripts');
function add_scripts()
{
	time_enqueuer('swiperjs', '/assets/js/vendors/swiper-bundle.min.js', 'script', true);
	time_enqueuer('maskedinputjs', '/assets/js/vendors/jquery.maskedinput.min.js', 'script', true);
	time_enqueuer('app', '/assets/js/src/app.js', 'script', true);

	wp_localize_script('app', 'SITEDATA', array(
		'url' => get_site_url(),
		'themepath' => get_template_directory_uri(),
		'ajax_url' => admin_url('admin-ajax.php'),
	));
}

//wp-embed.min.js remove
remove_action('wp_head', 'wp_oembed_add_discovery_links');
remove_action('wp_head', 'wp_oembed_add_host_js');

//remove jquery-migrate
function dequeue_jquery_migrate($scripts)
{
	if (!is_admin() && !empty($scripts->registered['jquery'])) {
		$jquery_dependencies = $scripts->registered['jquery']->deps;
		$scripts->registered['jquery']->deps = array_diff($jquery_dependencies, array('jquery-migrate'));
	}
}
add_action('wp_default_scripts', 'dequeue_jquery_migrate');

function add_styles()
{
	if (is_admin())
		return false;
	time_enqueuer('swipercss', '/assets/css/swiper-bundle.min.css', 'style', false, 'all');
	time_enqueuer('main', '/assets/css/main.css', 'style', false, 'all');
	time_enqueuer('redesign', '/assets/css/redesign.css', 'style', false, 'all');
}

add_action('wp_print_styles', 'add_styles');

if (function_exists('acf_add_options_page')) {
	acf_add_options_page();
}

Timber::$dirname = array('templates', 'views');

// Auto cache-busting for theme assets — appends ?v=filemtime
add_filter('get_twig', function ($twig) {
	$twig->addFunction(new \Twig\TwigFunction('asset', function ($path) {
		$rel = ltrim($path, '/');
		$full = get_template_directory() . '/' . $rel;
		$url = get_template_directory_uri() . '/' . $rel;
		return file_exists($full) ? $url . '?v=' . filemtime($full) : $url;
	}));
	return $twig;
});
class StarterSite extends TimberSite
{
	function __construct()
	{
		add_theme_support('post-formats');
		add_theme_support('post-thumbnails');
		add_theme_support('woocommerce');
		add_theme_support('menus');
		add_filter('timber_context', array($this, 'add_to_context'));
		add_theme_support('html5', array('comment-list', 'comment-form', 'search-form', 'gallery', 'caption'));
		parent::__construct();
	}

	function add_to_context($context)
	{
		$context['menu_women'] = new TimberMenu('menu_women');
		$context['menu_men'] = new TimberMenu('menu_men');
		$context['menu_women2'] = new TimberMenu('menu_women2');
		$context['menu_men2'] = new TimberMenu('menu_men2');
		$context['main_menu'] = new TimberMenu('main_menu');
		$context['footer_menu'] = new TimberMenu('footer_menu');

		if (function_exists('yoast_breadcrumb')) {
			$context['breadcrumbs'] = yoast_breadcrumb('<div id="breadcrumbs" class="breadcrumbs">', '</div>', false);
		}


		$context['is_admin'] = is_super_admin();
		$context['is_shop'] = is_shop();

		$context['certs'] = get_field('sertifikat_gal', 'options');

		$context['post_type'] = get_post_type();

		$primary_id = yoast_get_primary_term_id('product_cat', get_the_ID());
		$primary_cat = Timber::get_term($primary_id, 'product_cat');
		if ($primary_cat->parent) {
			$primary_parent_id = $primary_cat->parent;
		} else {
			$primary_parent_id = $primary_id;
		}
		$primary_parent_cat = Timber::get_term($primary_parent_id, 'product_cat');


		
		


  
		$context['phone1'] = get_field('телефон_1', 'options');
		$context['phone2'] = get_field('телефон_2', 'options');
		$context['email'] = get_field('email', 'options');
		$context['address'] = get_field('адрес', 'options');
		$context['work_time'] = get_field('график_работы', 'options');
		$context['inn'] = get_field('инн', 'options');
		$context['wa'] = get_field('whatsapp', 'options');
		$context['tg'] = get_field('telegram', 'options');
		$context['vk'] = get_field('vk', 'options');
		$context['fb'] = get_field('facebook', 'options');
		$context['ig'] = get_field('instagram', 'options');
		$context['yt'] = get_field('youtube', 'options');
		$context['tt'] = get_field('tiktok', 'options');

		$context['leftBlock'] = get_field('левый_блок', 'options');
		$context['rightBlock'] = get_field('правый_блок', 'options');

		$context['cat_id'] = get_queried_object()->term_id;

		$category = get_term( $context['cat_id'], 'product_cat' );
		$context['count_prod'] = $category->count;


		$current_url = $_SERVER['REQUEST_URI']; // Gets the path part of the URL, e.g., /articles/my-article-slug

		$context['men_cat'] = strpos($current_url, 'product-category/men') !== false;
		$context['women_cat'] = strpos($current_url, 'product-category/women') !== false;


		$products = get_posts(array('post_type' => 'product','posts_per_page' => 4));
		$context['recommend'] = $products;
		$terms = array(
			'taxonomy' => 'product_cat',
			'hide_empty' => false,
			'parent' => get_queried_object()->term_id
		);

		$context['childs'] = Timber::get_terms($terms);



		return $context;
	}
}
new StarterSite();

function timber_set_product($post)
{
	global $product;

	if (is_woocommerce() || is_home() || is_page('filter')) {
		$product = wc_get_product($post->ID);
	}
}






add_action('init', 'create_my_post_types');

function create_my_post_types()
{
	register_post_type(
		'women',
		array(
			'labels' => array(
				'name' => __('Женщинам'),
				'singular_name' => __('Образ')
			),
			'supports'      => array('title', 'thumbnail', 'editor'),
			'public' => true,
			'publicly_queryable' => true,
			'show_ui' => true,
			// 'exclude_from_search' => true,
			'show_in_nav_menus' => false,
			'has_archive' => true,
			'taxonomies' => array('women_looks'),
		)
	);
	register_post_type(
		'men',
		array(
			'labels' => array(
				'name' => __('Мужчинам'),
				'singular_name' => __('Образ')
			),
			'supports'      => array('title', 'thumbnail'),
			'public' => true,
			'publicly_queryable' => true,
			'show_ui' => true,
			// 'exclude_from_search' => true,
			'show_in_nav_menus' => false,
			'has_archive' => true,
			'taxonomies' => array('men_looks'),
		)
	);
}


function add_custom_taxonomies()
{
	register_taxonomy('women_looks', 'women-looks', array(
		'hierarchical' => true,
		'labels' => array(
			'name' => _x('Категории', 'категории'),
			'singular_name' => _x('Категории', 'категории'),
		),
		'rewrite' => array(
			'with_front' => true,
			'hierarchical' => true
		),
	));
	register_taxonomy('men_looks', 'men-looks', array(
		'hierarchical' => true,
		'labels' => array(
			'name' => _x('Категории', 'категории'),
			'singular_name' => _x('Категории', 'категории'),
		),
		'rewrite' => array(
			'with_front' => true,
			'hierarchical' => true
		),
	));
}

add_action('init', 'add_custom_taxonomies', 0);



//Disable gutenberg style in Front
function wps_deregister_styles()
{
	wp_dequeue_style('wp-block-library');
}
add_action('wp_print_styles', 'wps_deregister_styles', 100);

//remove type js and css for validator
add_action('wp_loaded', 'prefix_output_buffer_start');
function prefix_output_buffer_start()
{
	ob_start("prefix_output_callback");
}
add_action('shutdown', 'prefix_output_buffer_end');
function prefix_output_buffer_end()
{
	ob_end_flush();
}
function prefix_output_callback($buffer)
{
	return preg_replace("%[ ]type=[\'\"]text\/(javascript|css)[\'\"]%", '', $buffer);
}


add_filter("loop_shop_per_page", function ($cols) {

	return 8;
}, 20);



//поиск только по заголовкам записей start
function wph_search_by_title($search, $wp_query)
{
	global $wpdb;
	if (empty($search))
		return $search;

	$q = $wp_query->query_vars;
	$n = !empty($q['exact']) ? '' : '%';
	$search = $searchand = '';

	foreach ((array) $q['search_terms'] as $term) {
		$term = esc_sql(like_escape($term));
		$search .= "{$searchand}($wpdb->posts.post_title LIKE '{$n}{$term}{$n}')";
		$searchand = ' AND ';
	}

	if (!empty($search)) {
		$search = " AND ({$search}) ";
		if (!is_user_logged_in())
			$search .= " AND ($wpdb->posts.post_password = '') ";
	}
	return $search;
}
add_filter('posts_search', 'wph_search_by_title', 500, 2);
//поиск только по заголовкам записей end


/* Start code to add in the functions.php */
add_filter('wpc_mobile_width', 'my_custom_wpc_mobile_width');
function my_custom_wpc_mobile_width($width)
{
	// Screen width in px when Filters widget should become mobile
	$width = 1240;
	return $width;
}
/* End code to add in the functions.php  */

add_shortcode('wc_sorting', 'woocommerce_catalog_ordering');



add_filter('woocommerce_show_variation_price', '__return_true', 25);
/**
 * @snippet       Replace Variable Price With Variation Price | WooCommerce
 * @how-to        https://businessbloomer.com/woocommerce-customization
 * @author        Rodolfo Melogli
 * @testedwith    WooCommerce 9
 * @community     https://businessbloomer.com/club/
 */

add_action('woocommerce_variable_add_to_cart', 'bbloomer_update_price_with_variation_price');

function bbloomer_update_price_with_variation_price()
{
	global $product;
	$price = $product->get_price_html();
	wc_enqueue_js("     
      $(document).on('show_variation', 'form.cart', function( event, variation ) {   
         if(variation.price_html) $('.current_price').html(variation.price_html);
         $('.woocommerce-variation-price').hide();
      });
      $(document).on('hide_variation', 'form.cart', function( event, variation ) {   
         $('.current_price').html('" . $price . "');
      });
   ");
}

add_filter('wpseo_breadcrumb_links', function ($links) {

	if (is_product()) {
		// True, remove 'Products' archive from breadcrumb links
		unset($links[1]);
	}

	// Rebase array keys
	$links = array_values($links);

	// Return modified array
	return $links;
});

// function my_home_query( $query ) {
// 	  if ( $query->is_main_query() && !is_admin() ) {
// 		$query->set( 'post_type', array( 'women', 'men' ));
// 	  }
// 	}
// 	add_action( 'pre_get_posts', 'my_home_query' );


add_filter( 'woocommerce_ship_to_different_address_checked', '__return_true');



add_action( 'woocommerce_product_query', 'sort_by_min_price_for_variable_products' );
function sort_by_min_price_for_variable_products( $query ) {
    // Проверяем: не админка, основной запрос, и идёт сортировка по цене
    if ( ! is_admin() && $query->is_main_query() && ( is_shop() || is_product_taxonomy() ) ) {
        $orderby = $query->get( 'orderby' );
        $order   = $query->get( 'order' );

        // Если сортировка по цене (по возрастанию или убыванию)
        if ( 'price' === $orderby || 'meta_value_num' === $orderby ) {
            $query->set( 'meta_key', '_price' );       // WooCommerce хранит минимальную цену в _price
            $query->set( 'orderby', 'meta_value_num' );
            $query->set( 'order', $order ?: 'ASC' );   // сохраняем порядок
        }
    }
}
add_action( 'pre_get_posts', 'debug_price_sort_query' );
function debug_price_sort_query( $query ) {
    if ( ! is_admin() && $query->is_main_query() && ( is_shop() || is_product_taxonomy() ) ) {
        $orderby = $query->get( 'orderby' );
        $meta_key = $query->get( 'meta_key' );
        error_log( 'Сортировка: orderby=' . $orderby . ', meta_key=' . $meta_key . ', order=' . $query->get('order') );
    }
}
// Поиск с фронта всегда по товарам (по умолчанию WP ищет по записям блога)
add_action('pre_get_posts', function($q){
	if (is_admin() || !$q->is_main_query() || !$q->is_search()) return;
	$q->set('post_type', ['product']);
	$q->set('posts_per_page', 12);
});

// Staging-safe mail defaults. Production delivery belongs in deployment config.
add_action('phpmailer_init', function($pm){
	$pm->Sender = 'noreply@example.invalid';
	$pm->clearReplyTos();
	$pm->addReplyTo('noreply@example.invalid', 'ЛЕЙБО');
});

include_once(get_template_directory() . '/include/acf-fields.php');
include_once(get_template_directory() . '/include/woocommerce-theme-settings.php');
include_once(get_template_directory() . '/include/rest-api.php');

add_filter('timber/context', function ($context) {
    if (is_product_taxonomy()) {
        $term = get_queried_object();

        if ($term && !is_wp_error($term) && $term->taxonomy === 'product_cat') {
            $context['current_term'] = Timber::get_term($term->term_id);

            $context['child_categories'] = Timber::get_terms([
                'taxonomy'   => 'product_cat',
                'parent'     => $term->term_id,
                'hide_empty' => true,
                'orderby'    => 'menu_order',
                'order'      => 'ASC',
            ]);
        }
    }

    return $context;
});
