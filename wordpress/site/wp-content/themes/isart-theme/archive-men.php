<?php
global $paged;
if (!isset($paged) || !$paged) {
    $paged = 1;
}
$context = Timber::context();
$args = array(
    'post_type' => 'men',
    'paged' => $paged,
    'meta_key'      => 'показывать_на_главной',
    'meta_value'    => '1'
);
$context['posts'] = new Timber\PostQuery($args);

$cats_args = array(
    'taxonomy' => 'men_looks',
    'hide_empty' => true, // Set to false to show categories with no posts
);

$context['categories'] = Timber::get_terms($cats_args);


Timber::render('archive-men.twig', $context);
