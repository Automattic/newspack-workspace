<?php
/**
 * Query runner for WordPress database (wpdb) data sources.
 *
 * @package NewspackProfiles
 */

declare(strict_types = 1);

namespace NewspackProfiles;

use NewspackProfiles\Interfaces\Wpdb_Query_Interface;
use NewspackProfiles\Interfaces\Wpdb_Query_Runner_Interface;
use RemoteDataBlocks\Config\QueryRunner\QueryResponseParser;
use RemoteDataBlocks\Editor\DataBinding\Pagination;
use WP_Error;

/**
 * Class Wpdb_Query_Runner
 *
 * Executes WordPress database queries.
 */
class Wpdb_Query_Runner implements Wpdb_Query_Runner_Interface {

	const CACHE_GROUP = 'npp-wpdb-query-runner';

	/**
	 * Execute a single query.
	 *
	 * @param Wpdb_Query_Interface $query The query to execute.
	 * @param array                $input_variables The input variables for the query.
	 *
	 * @return array|WP_Error The query results or WP_Error on failure.
	 */
	public function execute( Wpdb_Query_Interface $query, array $input_variables ): array|WP_Error {
		global $wpdb;

		$input_schema = $query->get_input_schema();

		// Filter and validate input variables.
		$input_variables = array_intersect_key( $input_variables, $input_schema );

		// Set defaults and check required.
		foreach ( $input_schema as $key => $schema ) {
			if ( ! array_key_exists( $key, $input_variables ) && isset( $schema['default_value'] ) ) {
				$input_variables[ $key ] = $schema['default_value'];
			}
			if ( ! array_key_exists( $key, $input_variables ) && isset( $schema['required'] ) && $schema['required'] ) {
				return new WP_Error( 'missing-required-input', sprintf( 'Missing required input: %s', $key ) );
			}
		}

		// Check cache.
		$cache_key     = sprintf( 'npp-wpdb-query:%s', md5( wp_json_encode( array( $query->to_array(), $input_variables ) ) ) );
		$cached_result = $this->cache_get( $cache_key );

		if ( null !== $cached_result ) {
			return $cached_result;
		}

		// Get SQL query.
		$sql = $query->get_sql_query( $input_variables );

		if ( empty( $sql ) ) {
			return new WP_Error( 'empty-sql', 'SQL query is empty' );
		}

		// Query is already prepared and safe, so we can execute it directly.
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Check for database errors.
		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'wpdb-error', $wpdb->last_error );
		}

		// Parse results using output schema.
		$output_schema = $query->get_output_schema();
		$is_collection = $output_schema['is_collection'] ?? false;

		$parser  = new QueryResponseParser();
		$results = $parser->parse( $rows, $output_schema, array( 'input_variables' => $input_variables ) );
		$results = $is_collection ? $results : array( $results );

		$result = array(
			'metadata'     => $this->get_metadata( $results ),
			'pagination'   => $this->get_pagination( $query, $rows, $input_variables, $input_schema, $parser ),
			'results'      => $results,
			'query_inputs' => array( $input_variables ),
		);

		return $this->cache_save( $cache_key, $result );
	}

	/**
	 * Execute a batch of queries.
	 *
	 * @param Wpdb_Query_Interface $query The query to execute.
	 * @param array                $array_of_input_variables The array of input variables for each query.
	 *
	 * @return array|WP_Error The batch query results or WP_Error on failure.
	 */
	public function execute_batch( Wpdb_Query_Interface $query, array $array_of_input_variables ): array|WP_Error {
		if ( 1 === count( $array_of_input_variables ) ) {
			return $this->execute( $query, $array_of_input_variables[0] );
		}

		$merged_results      = array();
		$merged_query_inputs = array();

		foreach ( $array_of_input_variables as $input_variables ) {
			$query_response = $this->execute( $query, $input_variables );

			if ( is_wp_error( $query_response ) ) {
				return $query_response;
			}

			$merged_results      = array_merge( $merged_results, $query_response['results'] );
			$merged_query_inputs = array_merge( $merged_query_inputs, $query_response['query_inputs'] );
		}

		return array(
			'metadata'     => array(
				'total_count' => array(
					'name'  => 'Total count',
					'type'  => 'integer',
					'value' => count( $merged_results ),
				),
			),
			'pagination'   => null,
			'results'      => $merged_results,
			'query_inputs' => $merged_query_inputs,
		);
	}

	/**
	 * Get cached result by cache key.
	 *
	 * @param string $cache_key The cache key.
	 *
	 * @return array|null
	 */
	protected function cache_get( string $cache_key ): ?array {
		$cached_data = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false === $cached_data ) {
			return null;
		}

		return $cached_data;
	}

	/**
	 * Save result to cache.
	 *
	 * @param string $cache_key The cache key.
	 * @param array  $data The data to cache.
	 *
	 * @return array The cached data.
	 */
	protected function cache_save( string $cache_key, array $data ): array {
		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, 300 );

		return $data;
	}

	/**
	 * Get response metadata.
	 *
	 * @param array $results The query result rows.
	 *
	 * @return array The response metadata.
	 */
	protected function get_metadata( array $results ): array {
		$metadata = array(
			'last_updated' => array(
				'name'  => 'Last updated',
				'type'  => 'string',
				'value' => gmdate( 'Y-m-d H:i:s' ),
			),
			'total_count'  => array(
				'name'  => 'Total count',
				'type'  => 'integer',
				'value' => count( $results ),
			),
		);

		return $metadata;
	}

	/**
	 * Get pagination data.
	 *
	 * @param Wpdb_Query_Interface $query The query.
	 * @param array                $rows The query result rows.
	 * @param array                $input_variables The input variables.
	 * @param array                $input_schema The input schema.
	 * @param QueryResponseParser  $parser The response parser.
	 *
	 * @return array|null|WP_Error The pagination data.
	 */
	protected function get_pagination( Wpdb_Query_Interface $query, array $rows, array $input_variables, array $input_schema, QueryResponseParser $parser ): array|null|WP_Error {
		global $wpdb;

		$pagination_schema = $query->get_pagination_schema();

		if ( ! is_array( $pagination_schema ) ) {
			return null;
		}

		// Query is already prepared and safe, so we can execute it directly.
		$total = (int) $wpdb->get_var( $query->get_count_query( $input_variables ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'wpdb-error', $wpdb->last_error );
		}

		$pagination_data = $parser->parse(
			array(
				'rows'   => $rows,
				'total'  => $total,
				'offset' => $input_variables['cursor'] ?? 0,
				'limit'  => $input_variables['page_size'] ?? 10,
			),
			array( 'type' => $pagination_schema )
		)['result'] ?? null;

		if ( ! is_array( $pagination_data ) ) {
			return null;
		}

		$pagination = array();

		foreach ( $pagination_data as $key => $value ) {
			$pagination[ $key ] = $value['value'];
		}

		return Pagination::format_pagination_data_for_query_response( $pagination, $input_schema, $input_variables );
	}
}
