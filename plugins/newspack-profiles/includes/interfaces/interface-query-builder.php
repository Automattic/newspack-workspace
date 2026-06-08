<?php
/**
 * Interface for query builders.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles\Interfaces;

use RemoteDataBlocks\Config\Query\HttpQuery;
use NewspackProfiles\Wpdb_Query;

/**
 * Query_Builder_Interface interface.
 */
interface Query_Builder_Interface {
	/**
	 * Check if the query has a valid data source.
	 *
	 * @return bool
	 */
	public function has_valid_data_source(): bool;

	/**
	 * Get item query.
	 *
	 * @param bool $for_import Whether the query is for import.
	 *
	 * @return HttpQuery|Wpdb_Query
	 */
	public function get_item_query( bool $for_import = false ): HttpQuery|Wpdb_Query;

	/**
	 * Get list query.
	 *
	 * @param bool $for_import Whether the query is for import.
	 *
	 * @return HttpQuery|Wpdb_Query
	 */
	public function get_list_query( bool $for_import = false ): HttpQuery|Wpdb_Query;

	/**
	 * Get table creation query.
	 *
	 * @return array
	 */
	public function get_table_creation_query(): array;
}
