<?php
$context = Timber::get_context();
$post = new TimberPost();
$context['post'] = $post;
if ( is_front_page() ) {    	
	Timber::render( array('home.twig'), $context );
} else {
    Timber::render( array( 'page-' . $post->post_name . '.twig', 'page.twig' ), $context );
}
