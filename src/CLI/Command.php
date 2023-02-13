<?php

namespace BvdB\WPML\MediaCleanUp\CLI;

class Command extends BaseCommand {

	/**
	 * Loop all attachments and delete duplicates created by WPML
	 *
	 * ## OPTIONS
	 * 
	 * [<attachment-id>...]
	 * : One ID of the attachment to regenerate. If no ID is passed, process all attachments.
	 * 
	 * [--dry-run]
	 * : If present, no updates will be made.
	 *
	 * [--rewind]
	 * : Resets the cursor so the next time the command is run it will start from the beginning.
	 * 
	 * ## EXAMPLES
	 * 
	 *		wp bvdb wpml clean-up-media-twins
	 *
	 * @subcommand clean-up-media-twins
	 */
	public function clean_up_media_twins( $args, $assoc_args ) {

		$this->start_bulk_operation();

		$bulk_task = new \Alley\WP_Bulk_Task\Bulk_Task(
			'clean-up-media-twins',
			new \Alley\WP_Bulk_Task\Progress\PHP_CLI_Progress_Bar(
				__( 'Bulk Task: Remove duplicate post attachments', 'wpml-fix-command' )
			)
		);

		// Always run completly
		$bulk_task->cursor->reset();

		// Set up and run the bulk task.
		$dry_run = ! empty( $assoc_args['dry-run'] );

		// Setup WP_Query args for this function
		$query_args['post_status'] = 'inherit';
		$query_args['post_type'] = 'attachment';

		// If a post ID is passed, then only process those IDs (and reset the cursor)
		if ( ! empty( $args ) ) {
			$query_args['post__in'] = $args;
		}

		// Loop in batches
		$bulk_task->run(
			$query_args,
			function( $post ) use ( $dry_run ) {

				if ( $dry_run ) {
					\WP_CLI::line( 'ID: ' . $post->ID );
				} else {
					$this->clean_up_media_twins_by_name_or_title( $post );
				}
				
			}
		);

		$this->end_bulk_operation();
	}

	/**
	 * Find and delete all attachments that are derived from this $post_id based on the post_title and post_name.
	 * 
	 * Background information: All twins are created with these post_name / post_title:
	 * 
	 * First born: 'pexels-sonya-livshits-9828172'
	 * 
	 * Twin post_name: 'pexels-sonya-livshits-9828172-2'
	 * Twin post_name: 'pexels-sonya-livshits-9828172-3'
	 * Twin post_name: 'pexels-sonya-livshits-9828172-2-2'
	 * 
	 * First born: 'westwaarts-30a-zoetermeer-house-photography-basic_013.jpg'
	 * 
	 * Twin post_title: 'westwaarts-30a-zoetermeer-house-photography-basic_013-1.jpg'
	 * Twin post_title: 'westwaarts-30a-zoetermeer-house-photography-basic_013-2.jpg'
	 * 
	 * Twin post_name: 'westwaarts-30a-zoetermeer-house-photography-basic_013-6-jpg'
	 * Twin post_name: 'westwaarts-30a-zoetermeer-house-photography-basic_013-1-jpg-2'
	 * 
	 */
	protected function clean_up_media_twins_by_name_or_title( $post, $assoc_args = [] ) {

		$results = [];
	
		\WP_CLI::line( "Finding twins of '{$post->post_title}' ({$post->ID})" );

		$where_claus = $post->post_title . '-%';
		$twin_ids = $this->select_twin_ids( 'post_title', $where_claus );
		$this->delete_twins_related_data( $twin_ids );

		$where_claus = $post->post_name . '-%';
		$twin_ids = $this->select_twin_ids( 'post_name', $where_claus );
		$this->delete_twins_related_data( $twin_ids );
		
		return $results;
	}

	/**
	 * SELECT ALL TWINS so we can also cleanup the `wp_icl_translations` and `wp_postmeta` tables which are based on the ID's
	 */
	protected function select_twin_ids( $where_column, $where_value ) {
		
		$conditions = [
			'table' => 'wp_posts',
			'select_column' => 'ID',
			'where_column' => $where_column,
			'where_value' => [ $where_value ]
		];

		$result = $this->select_rows( $conditions );
		$twin_ids = \wp_list_pluck( $result, 'ID' );

		if( empty( $twin_ids ) ) {
			\WP_CLI::warning("Found no twins using: '{$where_column}' with '{$where_value}'" );
			return [];
		}

		\WP_CLI::warning("Found some twins using: '{$where_column}' with '{$where_value}'" );

		return $twin_ids;
	}

	/**
	 * Delete multiple rows from multiple Tables based on Post ID's
	 */
	protected function delete_twins_related_data( Array $twin_ids ) {

		// DELETE wp_posts
		$conditions = [
			'table' => 'wp_posts',
			'where_column' => 'ID',
			'where_value' => $twin_ids
		];

		$results['wp_posts'] = $this->delete_row( $conditions );
		
		// DELETE wp_icl_translations
		$conditions = [
			'table' => 'wp_icl_translations',
			'where_column' => 'element_id',
			'where_value' => $twin_ids
		];

		$results['wp_icl_translations'] = $this->delete_row( $conditions );

		// DELETE wp_postmeta
		$conditions = [
			'table' => 'wp_postmeta',
			'where_column' => 'post_id',
			'where_value' => $twin_ids 
		];

		$results['wp_postmeta'] = $this->delete_row( $conditions );

		if( ! empty( $twin_ids ) ) {
			\WP_CLI::line( 'Purged attachments: ' . implode( ', ', $twin_ids ) );
			foreach( $results as $table => $rows ) {
				\WP_CLI::line( "Deleted: {$rows} from {$table}" );
			}
		}
	}


	/**
	 * Select rows via SQL Query
	 */
	protected function select_rows( Array $conditions ) {

		global $wpdb;

		$table = $conditions['table']; //string
		$column = $conditions['select_column']; //string
		$where_column = $conditions['where_column']; //string
		$where_value = $conditions['where_value']; //array
		
		// Prepare values
		$where = "`$where_column` LIKE '" . implode( "' OR `$where_column` LIKE '" , $where_value ) . "'";

		// Prepare SQL
		$query = "SELECT $column FROM `$table` WHERE $where;";

		// Get data from DB
		$result = $wpdb->get_results( $query );

		return $result;
	}

	/**
	 * Delete a row via SQL Query
	 */
	protected function delete_row( Array $conditions ) {

		global $wpdb;
		$result = '?';

		$table = $conditions['table'];
		$where_column = $conditions['where_column'];
		$where_value = $conditions['where_value'];

		$where = "`$where_column` LIKE '" . implode( "' OR `$where_column` LIKE '" , $where_value ) . "'";

		$query = "DELETE FROM `$table` WHERE $where;";
		
		if( $this->dry_run ) {
			\WP_CLI::line( "Dry-run: {$query}" );
		} else {
			$result = $wpdb->query( $query );
		}
		
		return $result;
	}

	}
}