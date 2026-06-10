<?php
/**
 * WordPress database (wpdb) query.
 *
 * @package NewspackProfiles
 */

declare(strict_types = 1);

namespace NewspackProfiles;

use NewspackProfiles\Interfaces\Wpdb_Data_Source_Interface;
use NewspackProfiles\Interfaces\Wpdb_Query_Interface;
use RemoteDataBlocks\Config\ArraySerializable;
use RemoteDataBlocks\Validation\Types;
use WP_Error;

/**
 * Class Wpdb_Query
 *
 * Represents a WordPress database query.
 */
class Wpdb_Query extends ArraySerializable implements Wpdb_Query_Interface {

	/**
	 * Execute the query with the given input variables.
	 *
	 * @param array $input_variables The input variables for the query.
	 *
	 * @return array|WP_Error The query results or WP_Error on failure.
	 */
	public function execute( array $input_variables ): array|WP_Error {
		$query_runner = new Wpdb_Query_Runner();
		return $query_runner->execute( $this, $input_variables );
	}

	/**
	 * Execute a batch of queries with the given array of input variables.
	 *
	 * @param array $array_of_input_variables An array of input variables for each query.
	 *
	 * @return array|WP_Error The batch query results or WP_Error on failure.
	 */
	public function execute_batch( array $array_of_input_variables ): array|WP_Error {
		$query_runner = new Wpdb_Query_Runner();
		return $query_runner->execute_batch( $this, $array_of_input_variables );
	}

	/**
	 * Get the cache TTL (time to live) for this query, in seconds.
	 *
	 * @param array $input_variables The input variables for the query.
	 *
	 * @return int|null Cache TTL in seconds, or null for default.
	 */
	public function get_cache_ttl( array $input_variables ): ?int {
		if ( isset( $this->config['cache_ttl'] ) ) {
			return $this->get_or_call_from_config( 'cache_ttl', $input_variables );
		}

		// Use default cache TTL.
		return null;
	}

	/**
	 * Get the data source for this query.
	 *
	 * @return Wpdb_Data_Source_Interface
	 */
	public function get_data_source(): Wpdb_Data_Source_Interface {
		if ( is_array( $this->config['data_source'] ) ) {
			$this->config['data_source'] = Wpdb_Data_Source::from_array( $this->config['data_source'] );
		}
		return $this->config['data_source'];
	}

	/**
	 * Get the SQL query string for this query.
	 *
	 * @param array $input_variables The input variables for the query.
	 *
	 * @return string The SQL query string.
	 */
	public function get_sql_query( array $input_variables ): string {
		return $this->get_or_call_from_config( 'sql_query', $input_variables );
	}

	/**
	 * Get the count query string for this query, if defined.
	 *
	 * @return string The count query string, or null if not defined.
	 */
	public function get_count_query(): string {
		if ( isset( $this->config['count_query'] ) ) {
			return $this->get_or_call_from_config( 'count_query' );
		}

		return '';
	}

	/**
	 * Get the display name of the query.
	 *
	 * @return string|null
	 */
	public function get_image_url(): ?string {
		return $this->config['image_url'] ?? $this->get_data_source()->get_image_url();
	}

	/**
	 * Get the input schema for this query.
	 *
	 * @return array
	 */
	public function get_input_schema(): array {
		return $this->config['input_schema'] ?? array();
	}

	/**
	 * Get the output schema for this query.
	 *
	 * @return array
	 */
	public function get_output_schema(): array {
		return $this->config['output_schema'];
	}

	/**
	 * Get the pagination schema for this query.
	 *
	 * @return array|null
	 */
	public function get_pagination_schema(): ?array {
		return $this->config['pagination_schema'] ?? null;
	}

	/**
	 * Get the configuration schema for the query.
	 *
	 * @return array
	 */
	public static function get_config_schema(): array {
		return Types::object(
			array(
				'display_name'      => Types::nullable( Types::string() ),
				'cache_ttl'         => Types::nullable( Types::one_of( Types::callable(), Types::integer(), Types::null() ) ),
				'data_source'       => Types::one_of(
					Types::instance_of( Wpdb_Data_Source::class ),
					Types::serialized_config_for( Wpdb_Data_Source::class ),
				),
				'sql_query'         => Types::one_of( Types::callable(), Types::string() ),
				'count_query'       => Types::nullable( Types::one_of( Types::callable(), Types::string() ) ),
				'image_url'         => Types::nullable( Types::image_url() ),
				'input_schema'      => Types::nullable(
					Types::record(
						Types::string(),
						Types::object(
							array(
								'default_value' => Types::nullable( Types::any() ),
								'name'          => Types::nullable( Types::string() ),
								'type'          => Types::enum(
									'boolean',
									'id',
									'integer',
									'null',
									'number',
									'string',
									'id:list',
									'ui:input',
									'ui:search_input',
									'ui:pagination_offset',
									'ui:pagination_page',
									'ui:pagination_per_page',
									'ui:pagination_cursor_next',
									'ui:pagination_cursor_previous',
									'ui:pagination_cursor',
								),
								'required'      => Types::nullable( Types::boolean() ),
							)
						),
					)
				),
				'output_schema'     => Types::create_ref(
					'FIELD_SCHEMA',
					Types::object(
						array(
							'default_value' => Types::nullable( Types::any() ),
							'format'        => Types::nullable( Types::callable() ),
							'generate'      => Types::nullable( Types::callable() ),
							'is_collection' => Types::nullable( Types::boolean() ),
							'name'          => Types::nullable( Types::string() ),
							'path'          => Types::nullable( Types::json_path() ),
							'type'          => Types::one_of(
								Types::enum(
									'boolean',
									'integer',
									'null',
									'number',
									'string',
									'button_text',
									'button_url',
									'currency_in_current_locale',
									'email_address',
									'html',
									'id',
									'image_alt',
									'image_url',
									'markdown',
									'title',
									'url',
									'uuid',
								),
								Types::record( Types::string(), Types::use_ref( 'FIELD_SCHEMA' ) ),
							),
						)
					),
				),
				'pagination_schema' => Types::nullable(
					Types::object(
						array(
							'total_items'     => Types::nullable(
								Types::object(
									array(
										'generate' => Types::nullable( Types::callable() ),
										'name'     => Types::nullable( Types::string() ),
										'path'     => Types::nullable( Types::json_path() ),
										'type'     => Types::enum( 'integer' ),
									)
								),
							),
							'cursor_next'     => Types::nullable(
								Types::object(
									array(
										'generate' => Types::nullable( Types::callable() ),
										'name'     => Types::nullable( Types::string() ),
										'path'     => Types::nullable( Types::json_path() ),
										'type'     => Types::enum( 'string' ),
									)
								),
							),
							'cursor_previous' => Types::nullable(
								Types::object(
									array(
										'generate' => Types::nullable( Types::callable() ),
										'name'     => Types::nullable( Types::string() ),
										'path'     => Types::nullable( Types::json_path() ),
										'type'     => Types::enum( 'string' ),
									)
								),
							),
						)
					)
				),
			)
		);
	}
}
