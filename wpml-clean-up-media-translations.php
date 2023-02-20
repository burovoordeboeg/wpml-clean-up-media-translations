<?php
/*
Plugin Name: BvdB WPML Clean up media translations
Description: Clean up duplicate media entries in the DB after you (accidentally) enabled Media Translations by WPML.
Version: 0.1.4
Requires at least: 6.0
Requires PHP: 7.4
Author: Buro voor de Boeg
Text Domain: wpml-clean-up-media-translations
Domain Path: /languages
License: GPL-3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.txt

Copyright (C) 2019 - 2023, Buro voor de Boeg

*/

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

if ( defined( 'WP_CLI' ) && \WP_CLI ) {
	WP_CLI::add_command( 'bvdb wpml', '\BvdB\WPML\MediaCleanUp\CLI\Command' );
}

add_filter('bvdb_clean_up_twins_keep_keys', function( $meta_keys ){
	$meta_keys = array_merge( $meta_keys, ['header_image','gallery','map_images'] );
	return $meta_keys;
});

add_filter('bvdb_clean_up_twins_keep_ids', function( $attachment_ids ) {

	// Images in Gutenberg blocks
	$attachment_ids = array_merge( $attachment_ids, [ 7912,8016,8015,8011,194, 8269 ] );
	
	// Term thumbnails via ACF
	$terms = get_terms( array(
		'taxonomy' => 'portfoliocities',
		'hide_empty' => false,
	) );

	foreach( $terms as $term ) {
		$meta_value = \get_field('image', $term );
		if( $meta_value ) {
			$attachment_ids = array_merge( $attachment_ids, [ $meta_value ] );
		}
	}

	var_dump($attachment_ids);

	return $attachment_ids;
});

