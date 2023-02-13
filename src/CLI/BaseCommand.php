<?php

namespace BvdB\WPML\MediaCleanUp\CLI;

/**
 * Base Class for Importer Commands
 *
 */
class BaseCommand extends \WP_CLI_Command {

    /**
     * Mimimize the load while importing.
     *
     * If you don't want this maxed out, then overwrite it in your own Command.
     */
    public function __construct() {

        // Ensure only the minimum of extra actions are fired.
        if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
        }

        // This can cut down significantly on memory usage. Thank you 10up
        if ( ! defined( 'WP_POST_REVISIONS' ) ) {
			define( 'WP_POST_REVISIONS', 0 );
        }

        $this->disable_hooks();
    }

    /**
     * Disable hooks that you don't want to run while running inserts of updates.
     * Run these hooks from their own individuel commands.
     */
    protected function disable_hooks(): void {

        // SearchWP: Stop the SearchWP indexer process
        add_filter( 'searchwp\index\process\enabled', '__return_false' );

        // Post 3.8
        add_filter( 'facetwp_indexer_is_enabled', '__return_false' );

        // ElasticPress: Disable indexes to nothing will be synced
        add_filter(
			'ep_indexable_sites', function() {
				return [];
			}
        );

        /**
         * Empty image_sizes array so the won't be generated.
         *
         * Run "wp media regenerate --all" afterwards.
         */
        // add_filter( 'intermediate_image_sizes_advanced', function(){
        //     return [];
        // });
    }

    /**
	 * Disable Term and Comment counting so that they are not all recounted after every term or post operation.
     *
     * Run "wp term recount <taxonomy>..." afterwards.
	 */
	protected function start_bulk_operation(): void {
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
	}

	/**
	 * Re-enable Term and Comment counting and trigger a term counting operation to update all term counts
	 */
	protected function end_bulk_operation(): void {
        wp_defer_term_counting( false );
        wp_defer_comment_counting( false );
        wp_cache_flush();
	}

    /**
	 *  Clear all of the caches for memory management
	 */
	protected function clear_caches(): void {
        $this->reset_db_query_log();
        $this->reset_actions_log();
        $this->reset_local_object_cache();
    }

    /**
     * Reset the WordPress DB query log
     */
    protected function reset_db_query_log(): void {
        global $wpdb;

        $wpdb->queries = array();
    }

    /**
     * Reset the WordPress Actions log
     */
    protected function reset_actions_log(): void {
        global $wp_actions;

        $wp_actions = []; //phpcs:ignore
    }

    /**
     * Reset the local WordPress object cache
     *
     * This only cleans the local cache in WP_Object_Cache, without
     * affecting memcache
     *
     * @return void
     *
     * @props https://github.com/wp-cli/wp-cli/blob/master/php/utils-wp.php and https://github.com/Automattic/vip-go-mu-plugins/blob/develop/vip-helpers/vip-wp-cli.php
     *
     * But beware, because VIP remove the clearing of the memcached, probaly because that's just to heavy?
     */
    protected function reset_local_object_cache(): void {
        global $wp_object_cache;

        if ( ! is_object( $wp_object_cache ) ) {
            return;
        }

        // The following are Memcached (Redux) plugin specific (see https://core.trac.wordpress.org/ticket/31463).
        if ( isset( $wp_object_cache->group_ops ) ) {
            $wp_object_cache->group_ops = [];
        }

        if ( isset( $wp_object_cache->stats ) ) {
            $wp_object_cache->stats = [];
        }

        if ( isset( $wp_object_cache->memcache_debug ) ) {
            $wp_object_cache->memcache_debug = [];
        }

        // Used by `WP_Object_Cache` also.
        if ( isset( $wp_object_cache->cache ) ) {
            $wp_object_cache->cache = [];
        }

        if ( is_callable( $wp_object_cache, '__remoteset' ) ) {
            $wp_object_cache->__remoteset(); // important
        }
    }
}