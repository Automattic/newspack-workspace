<?php
/**
 * Interface for the WordPress database (wpdb) query runner.
 *
 * @package NewspackProfiles
 */

declare(strict_types = 1);

namespace NewspackProfiles\Interfaces;

use WP_Error;

interface Wpdb_Query_Runner_Interface {

	/**
	 * Execute a single query.
	 *
	 * @param Wpdb_Query_Interface $query The query to execute.
	 * @param array                $input_variables The input variables for the query.
	 *
	 * @return array|WP_Error The query results or WP_Error on failure.
	 */
	public function execute( Wpdb_Query_Interface $query, array $input_variables ): array|WP_Error;

	/**
	 * Execute a batch of queries.
	 *
	 * @param Wpdb_Query_Interface $query The query to execute.
	 * @param array                $array_of_input_variables An array of input variables for each query.
	 *
	 * @return array|WP_Error The batch query results or WP_Error on failure.
	 */
	public function execute_batch( Wpdb_Query_Interface $query, array $array_of_input_variables ): array|WP_Error;
}
