<?php
/*
Plugin Name: BvdB WPML Clean up media translations
Description: Clean up duplicate media entries in the DB after you (accidentally) enabled Media Translations by WPML.
Version: 0.1.0
Requires at least: 6.0
Requires PHP: 7.4
Author: Buro voor de Boeg
Text Domain: wpml-clean-up-media-translations
Domain Path: /languages
License: GPL-3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.txt

Copyright (C) 2019 - 2023, Buro voor de Boeg

*/

if( file_exists(__DIR__ . '/vendor/autoload.php') ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

if ( defined( 'WP_CLI' ) && \WP_CLI ) {
    WP_CLI::add_command( 'bvdb wpml', '\BvdB\WPML\MediaCleanUp\CLI\Command' );
}