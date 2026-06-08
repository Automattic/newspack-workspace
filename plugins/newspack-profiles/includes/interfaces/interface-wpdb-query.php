<?php
/**
 * Interface for WordPress database (wpdb) queries.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles\Interfaces;

use RemoteDataBlocks\Config\Query\QueryInterface;

interface Wpdb_Query_Interface extends QueryInterface {

	/**
	 * Get the data source for this query.
	 *
	 * @return Wpdb_Data_Source_Interface
	 */
	public function get_data_source(): Wpdb_Data_Source_Interface;

	/**
	 * Get the cache TTL (time to live) for this query, in seconds.
	 *
	 * @param array $input_variables The input variables for the query.
	 *
	 * @return int|null Cache TTL in seconds, or null for default.
	 */
	public function get_cache_ttl( array $input_variables ): ?int;

	/**
	 * Get the SQL query string for this query.
	 *
	 * @param array $input_variables The input variables for the query.
	 *
	 * @return string The SQL query string.
	 */
	public function get_sql_query( array $input_variables ): string;

	/**
	 * Get the count query string for this query.
	 *
	 * @return string The count query string.
	 */
	public function get_count_query(): string;
}
