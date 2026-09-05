<?php
$context = Timber::get_context();
$context['posts'] = new Timber\PostQuery();
$current_term = new TimberTerm();
$context['term_page'] = $current_term;
$current_taxonomy = get_queried_object()->taxonomy;
$context['title'] = single_cat_title( '', false );
$templates = [ 
    'taxonomy-' . get_query_var( 'taxonomy' ) .'-'.get_query_var( 'term' ). '.twig',
    'taxonomy-' . get_query_var( 'taxonomy' ) . '.twig', 
    'taxonomy.twig', 
    'archive.twig', 
];



Timber::render($templates, $context);
?>