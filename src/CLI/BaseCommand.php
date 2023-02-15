<?php

namespace BvdB\WPML\MediaCleanUp\CLI;

/**
 * Base Class for Importer Commands
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
			'ep_indexable_sites',
			function() {
				return [];
			}
		);

		/**
		 * Empty image_sizes array so the won't be generated.
		 *
		 * Run "wp media regenerate --all" afterwards.
		 */
		// add_filter( 'intermediate_image_sizes_advanced', function(){
		// return [];
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

		$wpdb->queries = [];
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

	 /**
	 * Loop through all your posts without it making you site crash.
	 *
	 * Prevents you from doing a 'posts_per_page' => '-1'.
	 *
	 * See: https://docs.wpvip.com/how-tos/write-custom-wp-cli-commands/wp-cli-commands-on-vip/#h-comment-and-provide-verbose-output
	 *
	 * @var array $query_args Arguments for WP_Query
	 * @var callable $callback The function to be called for each Post ID that comes from the WP_Query
	 * @var array $query_args Arguments to passed to the $callback function
	 */
	protected function loop_posts( $query_args = [], $callback = false, $callback_args = []  ) {

		if( ! \is_callable( $callback ) ) {
			error_log( 'Loop: $callback not callable' );
			return;
		}

		// Turn --post__in=1337,187 into an Array
		$query_args = $this->process_csv_arguments_to_arrays( $query_args );

		// Set base value of these variables that are also being used outside of the while loop
		$offset = $total = 0;

		do {
			/**
			 * Keeps track of the post count because we can't overwrite $query->post_count.
			 * I used that variable before in the while() check, but now use $count.
			 */
			$count = 0;

			$defaults = [
				'post_type'              => [ 'post' ],
				'post_status'            => [ 'publish' ],
				'posts_per_page'         => 500,
				'paged'                  => 0,
				'fields'                 => 'ids',
				'update_post_term_cache' => false, // useful when taxonomy terms will not be utilized.
				'update_post_meta_cache' => false, // useful when post meta will not be utilized.
				'ignore_sticky_posts'    => true, // otherwise these will be appened to the query
				'cache_results'          => false, // in rare situations (possibly WP-CLI commands),
				'suppress_filters'       => true // don't want a random `pre_get_posts` get in our way
			];

			$query_args = \wp_parse_args( $query_args, $defaults );

			// Force to false so we can skip SQL_CALC_FOUND_ROWS for performance (no pagination).
			$query_args['no_found_rows'] = false;

			// When adding 'nopaging' the code breaks... don't know why, haven't investigated it yet.
			unset( $query_args['nopaging' ] );

			// Base value is 0 (zero) and is upped with the 'posts_per_page' at the end of this function.
			$query_args['offset'] = $offset;

			// Get them all, you probaly have a pretty good reason to be using these.
			if( isset( $query_args['p'] ) || isset( $query_args['post__in'] ) || isset( $query_args['post__not_in'] ) ) {
				$query_args['posts_per_page'] = -1;
			}

			// Get the posts
			$query = new \WP_Query( $query_args );

			foreach ( $query->posts as $post ) {

				// Pass the Post and the $callback_args
				$result = call_user_func_array( $callback, [ $post, $callback_args ] );
				$count++;
				$total++;
			}

			/**
			 * 'offset' and 'posts_per_page' are being dropped when 'p' or 'post__in' are being used.
			 * So it will run without a limit and $query->post_count will always be higher then 0 (false) or 0 when it didn't find anything offcourse.
			 *
			 * But it is helpfull if you just want to set 1 post ;)
			 */
			if( isset( $query_args['p'] ) || isset( $query_args['post__in'] ) || isset( $query_args['post__not_in'] ) ) {

				if ( defined( 'WP_CLI' ) && \WP_CLI ) {
					\WP_CLI::log( \WP_CLI::colorize('%8Using p, post__in or post__not_in results quering ALL posts, which are not batches by "posts_per_page" and therefor not taking advantage of the goal of this loop.%n') );
					$count = 0;
				}
				
			}

			//Get a slice of all posts, which result in SQL "LIMIT 0,100", "LIMIT 100, 100", "LIMIT 200, 100" etc. And therefor creating an alternative for $query->have_posts() which can't use because we set 'no_found_rows' to TRUE.
			$offset = $offset + $query_args['posts_per_page'];

			// Contain memory leaks
			if ( method_exists( $this, 'clear_caches' ) ) {
				$this->clear_caches();
			}

		} while ( $count > 0 );

		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::line( "{$total} items processed." );
		}
		
	}

	/**
	 * Transforms arguments with '__' from CSV into expected arrays
	 *
	 * Added the check if value is a string, because if it's already an Array, this results in an error.
	 *
	 * @param array $assoc_args
	 * @return array
	 *
	 * @props https://github.com/wp-cli/entity-command/blob/master/src/WP_CLI/CommandWithDBObject.php#L99
	 */
	protected function process_csv_arguments_to_arrays( $assoc_args ) {
		foreach ( $assoc_args as $k => $v ) {
			if ( false !== strpos( $k, '__' ) && is_string( $v ) ) {
				$assoc_args[ $k ] = explode( ',', $v );
			}
		}
		return $assoc_args;
	}
}
