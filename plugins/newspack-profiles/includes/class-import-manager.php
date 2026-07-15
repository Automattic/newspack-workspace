<?php
/**
 * Import manager for profiles from remote data sources.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles;

use Exception;
use NewspackProfiles\Traits\Singleton;

const NEWSPACK_PROFILES_DATA_SOURCES_OPTION = 'newspack_profiles_data_sources';
const IMPORT_EXPIRATION_IN_SECONDS          = 1 * 60 * 60; // 1 hour
const IMPORT_BATCH_SIZE                     = 100;
const IMPORT_BATCH_DELAY_IN_SECONDS         = 2;
const IMPORT_BATCH_INSERT_SIZE              = 10; // Number of rows per INSERT statement.

/**
 * Import_Manager class to handle profile imports from remote data sources.
 */
class Import_Manager {

	use Singleton;

	/**
	 * Batch action hook prefix.
	 *
	 * @var string
	 */
	private string $batch_action_hook_prefix = 'newspack_profiles_process_import_batch';

	/**
	 * Option name for import states keyed by slug.
	 */
	private const IMPORT_STATE_OPTION_NAME = 'newspack_profiles_import_state';

	/**
	 * Constructor.
	 */
	protected function __construct() {
		add_action( 'init', array( $this, 'register_active_batch_hooks' ) );
	}

	/**
	 * Register batch hooks for any in-progress imports so cron callbacks fire.
	 *
	 * @return void
	 */
	public function register_active_batch_hooks(): void {
		$states = $this->get_import_states();

		foreach ( array_keys( $states ) as $slug ) {
			$this->add_batch_hook( $slug );
		}
	}

	/**
	 * Import profile data based on configuration.
	 *
	 * @param array $profile_collection_config The profile configuration.
	 *
	 * @return bool
	 */
	public function import( array $profile_collection_config ): bool {
		global $wpdb;

		$slug = $profile_collection_config['slug'];

		// Prevent duplicate imports - critical for data integrity.
		if ( $this->is_import_in_progress( $slug ) ) {
			return false;
		}

		$query_builder = Query_Manager::get_query_builder( $profile_collection_config );

		if ( null === $query_builder || ! $query_builder->has_valid_data_source() ) {
			return false;
		}

		$query = $query_builder->get_table_creation_query();

		if ( empty( $query ) ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		/**
		 * Just droping the table if it exists to start fresh.
		 * So Direct Database Query warnings are acceptable here.
		 */
		$drop_result = $wpdb->query( $query['queries']['drop'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $drop_result ) {
			return false;
		}

		$create_result = dbDelta( $query['queries']['create'] );

		if ( empty( $create_result ) ) {
			return false;
		}

		// Initialize import state.
		$this->update_import_state(
			array(
				'status'                => 'processing',
				'table_name'            => $query['table_name'],
				'offset'                => '',
				'created_at'            => time(),
				'output_query_mappings' => $query['output_query_mappings'],
			),
			$slug
		);

		$this->add_batch_hook( $slug );

		// Schedule first batch.
		$hook = $this->get_batch_action_hook( $slug );

		if ( ! wp_next_scheduled( $hook, array( $slug ) ) ) {
			wp_schedule_single_event(
				time() + $this->get_import_batch_delay_in_seconds(),
				$hook,
				array( $slug )
			);
		}

		return true;
	}

	/**
	 * Process a single batch of imports.
	 *
	 * @param string $slug The collection slug.
	 * @return void
	 */
	public function process_batch( string $slug ): void {
		global $wpdb;

		// Reload config from the collection.
		$profile_collection_config = Profile_Collections::get_instance()->get( $slug );

		if ( empty( $profile_collection_config ) ) {
			return;
		}

		$state = $this->get_import_state( $slug );

		if ( ! $state || 'processing' !== $state['status'] ) {
			return;
		}

		$query_builder = Query_Manager::get_query_builder( $profile_collection_config );

		if ( null === $query_builder || ! $query_builder->has_valid_data_source() ) {
			$this->clear_import_state( $slug );
			return;
		}

		$list_query = $query_builder->get_list_query( true );

		$data = $list_query->execute(
			array(
				'page_size' => $this->get_import_batch_size(),
				'cursor'    => $state['offset'],
			)
		);

		if ( is_wp_error( $data ) || empty( $data['results'] ) ) {
			$this->finish_import( $slug );
			return;
		}

		// Prepare all rows for batch insert.
		$all_rows = array();
		foreach ( $data['results'] as $row ) {
			$values = array();

			foreach ( $row['result'] as $key => $value ) {
				$values[ $key ] = empty( $value['value'] ) ? '' : $value['value'];
			}

			$all_rows[] = $values;
		}

		// Use transaction and batch inserts for better performance.
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery

		// Process in chunks.
		$chunks = array_chunk( $all_rows, $this->get_import_batch_insert_size() );

		try {
			foreach ( $chunks as $chunk ) {
				$this->batch_insert( $wpdb->prefix . $state['table_name'], $chunk );
			}
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Ignore errors and finish import to avoid infinite loops.
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery

		$next_page = $data['pagination']['input_variables']['next_page']['cursor'] ?? '';

		if ( empty( $next_page ) ) {
			$this->finish_import( $slug );
			return;
		}

		$state['offset'] = $next_page;

		$this->update_import_state( $state, $slug );

		// Adding a small delay to prevent server overload.
		$hook = $this->get_batch_action_hook( $slug );

		if ( ! wp_next_scheduled( $hook, array( $slug ) ) ) {
			wp_schedule_single_event(
				time() + $this->get_import_batch_delay_in_seconds(),
				$hook,
				array( $slug )
			);
		}
	}

	/**
	 * Update import state stored per slug.
	 *
	 * @param array  $state The import state to save.
	 * @param string $slug The profile slug.
	 *
	 * @return void
	 */
	private function update_import_state( array $state, string $slug ): void {
		$states          = $this->get_import_states();
		$states[ $slug ] = $state;

		update_option( self::IMPORT_STATE_OPTION_NAME, $states );
	}

	/**
	 * Get import state from persistent storage.
	 *
	 * @param string $slug The profile slug.
	 *
	 * @return array|null
	 */
	private function get_import_state( string $slug ): ?array {
		$states = $this->get_import_states();

		return $states[ $slug ] ?? null;
	}

	/**
	 * Check if an import is currently in progress.
	 *
	 * @param string $slug The profile slug.
	 *
	 * @return bool
	 */
	public function is_import_in_progress( string $slug ): bool {
		$state = $this->get_import_state( $slug );

		return (bool) (
			$state
			&& 'processing' === $state['status']
			&& time() - $state['created_at'] < $this->get_input_expiration_in_seconds()
		);
	}

	/**
	 * Clear import state for a slug.
	 *
	 * @param string $slug The profile slug.
	 *
	 * @return void
	 */
	public function clear_import_state( string $slug ): void {
		$states = $this->get_import_states();

		unset( $states[ $slug ] );

		update_option( self::IMPORT_STATE_OPTION_NAME, $states );
	}

	/**
	 * Get the action hook name for a collection slug.
	 *
	 * @param string $slug The profile slug.
	 *
	 * @return string
	 */
	private function get_batch_action_hook( string $slug ): string {
		return $this->batch_action_hook_prefix . '_' . sanitize_title( $slug );
	}

	/**
	 * Ensure the batch hook for a slug is registered.
	 *
	 * @param string $slug The profile slug.
	 *
	 * @return void
	 */
	private function add_batch_hook( string $slug ): void {
		$hook = $this->get_batch_action_hook( $slug );

		if ( ! has_action( $hook, array( $this, 'process_batch' ) ) ) {
			add_action( $hook, array( $this, 'process_batch' ), 10, 1 );
		}

		/**
		 * Scheduling import cron jobs if not scheduled.
		 * This is a safety measure to ensure imports continue processing
		 * even if the server misses a scheduled event.
		 */
		if ( ! wp_next_scheduled( $hook, array( $slug ) ) ) {
			wp_schedule_single_event(
				time() + $this->get_import_batch_delay_in_seconds(),
				$hook,
				array( $slug )
			);
		}
	}

	/**
	 * Fetch all import states keyed by slug.
	 *
	 * @return array
	 */
	private function get_import_states(): array {
		$states = get_option( self::IMPORT_STATE_OPTION_NAME, array() );

		return is_array( $states ) ? $states : array();
	}

	/**
	 * Perform batch insert of multiple rows in a single query.
	 *
	 * @param string $table_name The table name.
	 * @param array  $rows  The rows to insert.
	 *
	 * @return void
	 */
	private function batch_insert( string $table_name, array $rows ): void {
		global $wpdb;

		if ( empty( $rows ) ) {
			return;
		}

		$columns = array_keys( $rows[0] );

		// Build column placeholders using %i for identifiers.
		$column_placeholders = implode( ', ', array_fill( 0, count( $columns ), '%i' ) );

		$row_placeholders = array();
		$all_prepare_args = array();

		// First, add table name and column names to prepare args.
		$all_prepare_args[] = $table_name;
		$all_prepare_args   = array_merge( $all_prepare_args, $columns );

		// Build placeholders for each row.
		foreach ( $rows as $row ) {
			$value_placeholders = array();

			foreach ( $columns as $column ) {
				$value_placeholders[] = '%s';
				$all_prepare_args[]   = (string) ( $row[ $column ] ?? '' );
			}

			$row_placeholders[] = '(' . implode( ', ', $value_placeholders ) . ')';
		}

		$sql = "INSERT INTO %i ({$column_placeholders}) VALUES " . implode( ', ', $row_placeholders );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic SQL is properly prepared with identifiers (%i) and values (%s).
		$wpdb->query( $wpdb->prepare( $sql, $all_prepare_args ) );
	}

	/**
	 * Finish import process for a slug.
	 *
	 * @param string $slug The profile slug.
	 *
	 * @return void
	 */
	private function finish_import( string $slug ): void {
		$profile_collection_config = Profile_Collections::get_instance()->get( $slug );

		$state = $this->get_import_state( $slug );

		$source_config = array(
			'service'        => 'wpdb',
			'service_config' => array(
				/* translators: %s is the profile label */
				'display_name'          => esc_html( sprintf( __( 'Imported - %s', 'newspack-profiles' ), $profile_collection_config['name'] ) ),
				'table'                 => $state['table_name'],
				'output_query_mappings' => $state['output_query_mappings'],
			),
		);

		$configs = get_option( NEWSPACK_PROFILES_DATA_SOURCES_OPTION, array() );

		$configs[ $state['table_name'] ] = $source_config;

		update_option( NEWSPACK_PROFILES_DATA_SOURCES_OPTION, $configs );

		$profile_collection_config['dataSource'] = array(
			'type'  => 'wpdb',
			'name'  => $source_config['service_config']['display_name'],
			'table' => $source_config['service_config']['table'],
		);

		Profile_Collections::get_instance()->update( $profile_collection_config );

		$this->clear_import_state( $slug );
	}

	/**
	 * Delete imported data from the specified table.
	 *
	 * @param array $collection The profile configuration.
	 *
	 * @return void
	 */
	public function delete_imported_data( array $collection ): void {
		global $wpdb;

		$table_name = '';

		// Delete imported data if the data source is wpdb.
		if ( 'wpdb' === $collection['dataSource']['type'] && ! empty( $collection['dataSource']['table'] ) ) {
			$table_name = $collection['dataSource']['table'];

			$configs = get_option( NEWSPACK_PROFILES_DATA_SOURCES_OPTION, array() );

			unset( $configs[ $table_name ] );

			update_option( NEWSPACK_PROFILES_DATA_SOURCES_OPTION, $configs );
		}

		$import_state = $this->get_import_state( $collection['slug'] );

		// Delete table from import state if it exists.
		if ( ! empty( $import_state['table_name'] ) ) {
			$table_name = $import_state['table_name'];
		}

		$this->clear_import_state( $collection['slug'] );

		if ( ! empty( $table_name ) ) {
			// Direct Database Query as we are dropping a custom table.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}

	/**
	 * Get import input expiration time in seconds.
	 *
	 * @return int
	 */
	private function get_input_expiration_in_seconds(): int {
		/**
		 * Filter import expiration time in seconds.
		 *
		 * @param int Default expiration time in seconds.
		 */
		return apply_filters( 'newspack_profiles_import_expiration_in_seconds', IMPORT_EXPIRATION_IN_SECONDS );
	}

	/**
	 * Get import batch size.
	 *
	 * @return int
	 */
	private function get_import_batch_size(): int {
		/**
		 * Filter import batch size.
		 *
		 * @param int Default batch size.
		 */
		return apply_filters( 'newspack_profiles_import_batch_size', IMPORT_BATCH_SIZE );
	}

	/**
	 * Get import batch delay in seconds.
	 *
	 * @return int
	 */
	private function get_import_batch_delay_in_seconds(): int {
		/**
		 * Filter import batch delay in seconds.
		 *
		 * @param int Default batch delay in seconds.
		 */
		return apply_filters( 'newspack_profiles_import_batch_delay_in_seconds', IMPORT_BATCH_DELAY_IN_SECONDS );
	}

	/**
	 * Get import batch insert size.
	 *
	 * @return int
	 */
	private function get_import_batch_insert_size(): int {
		/**
		 * Filter import batch insert size.
		 *
		 * @param int Default batch insert size.
		 */
		return apply_filters( 'newspack_profiles_import_batch_insert_size', IMPORT_BATCH_INSERT_SIZE );
	}
}
