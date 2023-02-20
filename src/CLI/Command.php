<?php

namespace BvdB\WPML\MediaCleanUp\CLI;

class Command extends BaseCommand {

	var $dry_run = false;


	/**
	 * SELECT ALL TWINS so we can also cleanup the `wp_icl_translations` and `wp_postmeta` tables which are based on the ID's
	 */
	protected function select_twin_ids( $where_column, $where_value ) {
		
		$conditions = [
			'table'         => 'wp_posts',
			'select_column' => 'ID',
			'where_column'  => $where_column,
			'where_value'   => [ $where_value ],
		];

		$result   = $this->select_rows( $conditions );
		$twin_ids = \wp_list_pluck( $result, 'ID' );

		if ( empty( $twin_ids ) ) {
			\WP_CLI::warning( "Found no twins using: '{$where_column}' with '{$where_value}'" );
			return [];
		}

		\WP_CLI::warning( "Found some twins using: '{$where_column}' with '{$where_value}'" );

		return $twin_ids;
	}

	/**
	 * Delete multiple rows from multiple Tables based on Post ID's
	 */
	protected function delete_twins_related_data( array $twin_ids ) {

		// DELETE wp_posts
		$conditions = [
			'table'        => 'wp_posts',
			'where_comparison' => '=',
			'where_column' => 'ID',
			'where_value'  => $twin_ids,
		];

		$results['wp_posts'] = $this->delete_row( $conditions );
		
		// DELETE wp_icl_translations
		$conditions = [
			'table'        => 'wp_icl_translations',
			'where_comparison' => '=',
			'where_column' => 'element_id',
			'where_value'  => $twin_ids,
		];

		$results['wp_icl_translations'] = $this->delete_row( $conditions );

		// DELETE wp_postmeta
		$conditions = [
			'table'        => 'wp_postmeta',
			'where_column' => 'post_id',
			'where_comparison' => '=',
			'where_value'  => $twin_ids, 
		];

		$results['wp_postmeta'] = $this->delete_row( $conditions );

		if ( ! empty( $twin_ids ) ) {
			\WP_CLI::line( 'Purged attachments: ' . implode( ', ', $twin_ids ) );
			foreach ( $results as $table => $rows ) {
				\WP_CLI::line( "Deleted: {$rows} from {$table}" );
			}
		}
	}


	/**
	 * Select rows via SQL Query
	 */
	protected function select_rows( array $conditions ) {

		global $wpdb;

		$table        = $conditions['table']; // string
		$column       = $conditions['select_column']; // string
		$where_column = $conditions['where_column']; // string
		$where_value  = $conditions['where_value']; // array
		
		// Prepare values
		$where = "`$where_column` LIKE '" . implode( "' OR `$where_column` LIKE '", $where_value ) . "'";

		// Prepare SQL
		$query = "SELECT $column FROM `$table` WHERE $where;";

		// Get data from DB
		$result = $wpdb->get_results( $query );

		return $result;
	}

	/**
	 * Delete a row via SQL Query
	 */
	protected function delete_row( array $conditions ) {

		global $wpdb;
		$result = '?';

		$table        = $conditions['table'];
		$where_column = $conditions['where_column'];
		$where_comparison = $conditions['where_comparison'];
		$where_value  = $conditions['where_value'];

		$where = "`$where_column` " . $where_comparison . " '" . implode( "' OR `$where_column` " . $where_comparison. " '", $where_value ) . "'";

		$query = "DELETE FROM `$table` WHERE $where;";
		$result = $wpdb->query( $query );
		
		return $result;
	}

	/**
	 * Loop all posts with a specific meta key which holds attachment IDs.
	 * 
	 * Find twin attachments with the same GUID and delete those, because these were created by WPML.
	 *
	 * ## OPTIONS
	 * 
	 * [<post-id>...]
	 * : One or multiple IDs of the post. If no ID is passed, process all attachments.
	 * 
	 * --post_type=<post_type>
	 * : The post type which we are targeting.
	 * 
	 * [--<field>=<value>]
	 * : Associative args for the new post. See WP_Query.
	 * 
	 * [--dry-run]
	 * : If present, no updates will be made.
	 * 
	 * ## EXAMPLES
	 * 
	 *      wp bvdb wpml clean-up-twins-per-meta-key --post_type=portfolio --meta_key=gallery
	 *
	 * @subcommand clean-up-twins-per-meta-key
	 */
	public function clean_up_twins_per_meta_key( $args, $assoc_args ) {

		$this->start_bulk_operation();

		// Setup WP_Query args for this function
		$query_args = array(
			'post_type' => $assoc_args['post_type'],
			'fields' => 'all',
		  );
		
		$query_args = wp_parse_args( $assoc_args, $query_args );

		// If a post ID is passed, then only process those IDs
		if ( ! empty( $args ) ) {
			$query_args['post__in'] = $args;
		}

		// Set up and run the bulk task.
		$this->dry_run = ! empty( $assoc_args['dry-run'] );

		// Keep these post IDs via --meta_values
		$this->post_ids = ( isset( $query_args['keep_ids'] ) ) ? $query_args['keep_ids'] : [];
		$this->post_ids = apply_filters( 'bvdb_clean_up_twins_keep_ids', $this->post_ids );

		// Search through these meta values. Beware that if you leave out 1 meta key, a ID in that value could be delete, so always pass ALL meta values.
		$meta_keys = ( isset( $query_args['keep_keys'] ) ) ? explode( ',', $query_args['keep_keys'] ) : [];
		$meta_keys = apply_filters( 'bvdb_clean_up_twins_keep_keys', $meta_keys );

		print_r($meta_keys);
		// Portfolio meta fields
		$loop = $this->loop_posts( $query_args, function( $post ) use ( $meta_keys ) {

			\WP_CLI::line( 'ID: ' . $post->ID );

			foreach( $meta_keys as $meta_key ) {
				$this->post_ids = array_merge( $this->post_ids, $this->get_meta_values( $post, $meta_key ) );
			}
		} );

		$this->post_ids = array_unique( $this->post_ids ); // Ontdubbelen
		$this->post_ids = array_filter( $this->post_ids ); // Lege eruit halen

		// Prepare SQL en juist NIET de post id's selecteren welke we willen behouden
		$query = "SELECT ID FROM `wp_posts` WHERE ID NOT IN (" . implode( ',', $this->post_ids ) . ") AND `post_type` = 'attachment'";

		// Get data from Database
		global $wpdb;
		$not_used_ids = $wpdb->get_results( $query ); // query
		$not_used_ids = \wp_list_pluck( $not_used_ids, 'ID' ); // just use ID's

		// Can't be deleting ALL items at once, in our case 133.000 items at once...
		$ppp = ( isset( $query_args['post_per_page'] ) ) ? $query_args['post_per_page'] : 500;
		$looping_times = count( $not_used_ids ) / $ppp;
		$looping_times = ceil( $looping_times );

		\WP_CLI::log( count( $this->post_ids ) . ' / ' . count( $not_used_ids ) . ' / ' . $ppp . ' / ' . $looping_times);

		for ( $i = 0; $i < $looping_times ; $i++ ) { 
			$slice = array_slice( $not_used_ids, $i * $ppp, $ppp );
			$this->delete_twins_related_data( $slice );
		}

		$this->end_bulk_operation();
	}

	protected function get_meta_values( $post, $meta_key ) {
		// Get meta field value
		$meta_value = \get_post_meta( $post->ID, $meta_key, true );
		
		$meta_value = maybe_unserialize( $meta_value );
		
		if ( ! \is_array( $meta_value ) ) {
			$meta_value = [ $meta_value ];
		}
		
		// If the meta field is empty!
		if ( empty( $meta_value ) ) {
			return [];
		}

		// \WP_CLI::line( 'Meta_value (' . $meta_key . '): ' . implode(',', $meta_value) );

		return $meta_value;
	}

	/**
	 * Check posts if they have post IDs in a custom meta field and then check if those "related" IDs have any twins / duplicates based on it's GUID (filename).
	 */
	protected function clean_up_twins_by_guid( $post, $meta_key ) {

		// Get meta field value
		$meta_value = \get_post_meta( $post->ID, $meta_key, true );
		
		$meta_value = maybe_unserialize( $meta_value );
		
		if ( \is_string( $meta_value ) ) {
			$meta_value = [ $meta_value ];
		}
		
		// If the meta field is empty!
		if ( empty( $meta_value ) ) {
			return;
		}

		\WP_CLI::line( 'Meta_value (' . $meta_key . '): ' . implode(',', $meta_value) );


		// Get all guids from the attachments in this meta field
		$conditions = [
			'table'         => 'wp_posts',
			'select_column' => 'ID, guid',
			'where_column'  => 'ID',
			'where_value'   => $meta_value,
		];

		$results = $this->select_rows( $conditions );

		// Check if there are attachments with the same `guid`?
		foreach ( $results as $row ) {
			
			$conditions = [
				'table'         => 'wp_posts',
				'select_column' => 'ID',
				'where_column'  => 'guid',
				'where_value'   => [ $row->guid ],
			];
			
			$attachments_with_same_guid = $this->select_rows( $conditions );
			$attachments_with_same_guid = \wp_list_pluck( $attachments_with_same_guid, 'ID' ); // just use ID's

			if ( empty( $attachments_with_same_guid ) ) {
				continue;
			}

			// Unset the "original attachment ID" and keep all duplicates. Not that we will always have a key, because because we have at least 1 attachment in the Database ;)
			$key = \array_search( $row->ID, $attachments_with_same_guid );

			// Just in case... and check for FALSE because $key can be 0 (zero)
			if ( $key !== false ) {
				unset( $attachments_with_same_guid[ $key ] );   
			}
			
			if ( empty( $attachments_with_same_guid ) ) {
				continue;
			}

			// Delete the resterende twins
			if( ! $this->dry_run ) {
				$this->delete_twins_related_data( $attachments_with_same_guid );
			}
		}
	}
}
