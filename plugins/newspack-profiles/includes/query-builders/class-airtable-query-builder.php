<?php
/**
 * Airtable query builder.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles\QueryBuilders;

use NewspackProfiles\Interfaces\Query_Builder_Interface;
use NewspackProfiles\Registrars\Rewrite_Rule_Registrar;
use RemoteDataBlocks\Config\Query\HttpQuery;
use RemoteDataBlocks\Integrations\Airtable\AirtableDataSource;
use RemoteDataBlocks\Store\DataSource\DataSourceConfigManager;

/**
 * Airtable_Query_Builder class to build queries for Airtable data source.
 */
class Airtable_Query_Builder implements Query_Builder_Interface {

	/**
	 * Data source.
	 *
	 * @var object|null
	 */
	private mixed $data_source;

	/**
	 * Data source table.
	 *
	 * @var array|null
	 */
	private mixed $table;

	/**
	 * Profile configuration.
	 *
	 * @var array
	 */
	private array $config;

	/**
	 * Constructor.
	 *
	 * @param array $profile_collection Profile configuration.
	 */
	public function __construct( array $profile_collection ) {
		$this->config = $profile_collection;
		$this->set_data_source( $profile_collection['dataSource'] );
	}

	/**
	 * Set data source.
	 *
	 * @param array $data_source_config Data source configuration.
	 */
	private function set_data_source( array $data_source_config ) {
		$data_sources = DataSourceConfigManager::get_all(
			array(
				'service' => REMOTE_DATA_BLOCKS_AIRTABLE_SERVICE,
			)
		);

		foreach ( $data_sources as $data_source ) {
			if ( $data_source['service_config']['display_name'] !== $data_source_config['name'] ) {
				continue;
			}

			if ( $data_source['service_config']['base']['name'] !== $data_source_config['base'] ) {
				continue;
			}

			foreach ( $data_source['service_config']['tables'] as $table ) {
				if ( $table['name'] === $data_source_config['table'] ) {
					$this->data_source = AirtableDataSource::from_array( $data_source );
					$this->table       = $table;
					return;
				}
			}
		}

		$this->data_source = null;
		$this->table       = null;
	}

	/**
	 * Check if the query has a valid data source.
	 *
	 * @return bool
	 */
	public function has_valid_data_source(): bool {
		return $this->data_source && $this->table;
	}

	/**
	 * Get item query.
	 *
	 * @param bool $for_import Whether the query is for import.
	 *
	 * @return HttpQuery
	 */
	public function get_item_query( bool $for_import = false ): HttpQuery {
		$input_schema = array(
			'slug' => array(
				'name' => 'Profile Slug',
				'type' => 'id',
			),
		);

		$output_schema = array(
			'is_collection' => false,
			'path'          => '$.records[*]',
			'type'          => $for_import ? $this->get_output_schema_mappings_for_import( $this->table ) : $this->get_output_schema_mappings( $this->table ),
		);

		return HttpQuery::from_array(
			array(
				'display_name'    => sprintf( 'Get %s row by ID', $this->table['name'] ),
				'data_source'     => $this->data_source,
				'endpoint'        => function ( array $input_variables ): string {
					$formula = $this->get_formula_for_slug( $input_variables['slug'] ?? '' );

					return $this->data_source->get_endpoint() . '/' . $this->table['id'] . '?filterByFormula=' . urlencode( $formula );
				},
				'request_headers' => function () use ( $for_import ): array {
					return array_merge(
						$this->data_source->get_request_headers(),
						array(
							/**
							 * Query type header to differentiate between normal and import queries.
							 * Otherwise, both queries will be cached under the same key,
							 * causing issues with output schema mapping.
							 */
							'query_type' => $for_import ? 'import' : 'normal',
						)
					);
				},
				'input_schema'    => $input_schema,
				'output_schema'   => $output_schema,
			)
		);
	}

	/**
	 * Get list query.
	 *
	 * @param bool $for_import Whether the query is for import.
	 *
	 * @return HttpQuery
	 */
	public function get_list_query( bool $for_import = false ): HttpQuery {
		$output_schema = array(
			'is_collection' => true,
			'path'          => '$.records[*]',
			'type'          => $for_import ? $this->get_output_schema_mappings_for_import( $this->table ) : $this->get_output_schema_mappings( $this->table ),
		);

		return HttpQuery::from_array(
			array(
				'display_name'      => sprintf( 'List %s records', $this->table['name'] ),
				'data_source'       => $this->data_source,
				'endpoint'          => function ( array $input_variables ): string {
					$endpoint = $this->data_source->get_endpoint() . '/' . $this->table['id'];

					if ( isset( $input_variables['cursor'] ) ) {
						// While named as "offset", this is implemented as a string cursor.
						$endpoint = add_query_arg( 'offset', rawurlencode( (string) $input_variables['cursor'] ), $endpoint );
					}

					if ( isset( $input_variables['page_size'] ) ) {
						$endpoint = add_query_arg( 'pageSize', rawurlencode( (string) $input_variables['page_size'] ), $endpoint );
					}

					return $endpoint;
				},
				'request_headers'   => function () use ( $for_import ): array {
					return array_merge(
						$this->data_source->get_request_headers(),
						array(
							/**
							 * Query type header to differentiate between normal and import queries.
							 * Otherwise, both queries will be cached under the same key,
							 * causing issues with output schema mapping.
							 */
							'query_type' => $for_import ? 'import' : 'normal',
						)
					);
				},
				'output_schema'     => $output_schema,
				'input_schema'      => array(
					'cursor'    => array(
						'name'     => 'Pagination cursor',
						'required' => false,
						'type'     => 'ui:pagination_cursor',
					),
					'page_size' => array(
						'default_value' => 10,
						'name'          => 'Page Size',
						'required'      => false,
						'type'          => 'ui:pagination_per_page',
					),
				),
				'pagination_schema' => array(
					'cursor_next' => array(
						'name' => 'Next page cursor',
						'path' => '$.offset', // named "offset" but functions as cursor.
						'type' => 'string',
					),
				),
			)
		);
	}

	/**
	 * Get table creation query.
	 *
	 * @return array
	 */
	public function get_table_creation_query(): array {
		global $wpdb;

		$mappings = $this->table['output_query_mappings'];

		if ( ! is_array( $mappings ) ) {
			return array();
		}

		$table_columns = array_column( $mappings, 'key' );

		if ( empty( $table_columns ) ) {
			return array();
		}

		// slug is already sanitized, so no need to sanitize again.
		$table_name          = 'newspack_profiles_' . str_replace( '-', '_', $this->config['slug'] );
		$prefixed_table_name = $wpdb->prefix . $table_name;
		$charset_collate     = $wpdb->get_charset_collate();

		$columns = implode(
			',',
			array_map(
				function ( $column_name ) {
					$sanitized_column_name = preg_replace( '/[^a-zA-Z0-9 _()&:\/-]/', '', $column_name );

					return sprintf( '`%s` TEXT NOT NULL', $sanitized_column_name );
				},
				$table_columns
			)
		);

		$drop_query = "DROP TABLE IF EXISTS $prefixed_table_name;";

		$creation_query = "CREATE TABLE $prefixed_table_name (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(191) NOT NULL,
			$columns,
			PRIMARY KEY (id),
			INDEX idx_slug (slug)
		) $charset_collate;";

		return array(
			'table_name'            => $table_name,
			'queries'               => array(
				'drop'   => $drop_query,
				'create' => $creation_query,
			),
			'output_query_mappings' => $this->table['output_query_mappings'],
		);
	}

	/**
	 * Get output schema mappings.
	 *
	 * @param array $table Table configuration.
	 *
	 * @return array
	 */
	private function get_output_schema_mappings( array $table ): array {
		$output_schema = array();

		foreach ( $table['output_query_mappings'] as $remote_mapping ) {
			$mapping_key = $remote_mapping['key'];

			$profile_mapping = $this->config['mappings'][ $mapping_key ] ?? array();

			$output_schema[ $mapping_key ] = array(
				'name' => $profile_mapping['label'] ?? $remote_mapping['name'] ?? $mapping_key,
				'path' => $remote_mapping['path'] ?? '$.fields["' . $mapping_key . '"]',
				'type' => $profile_mapping['type'] ?? $remote_mapping['type'] ?? 'string',
			);

			if ( 'social_link' !== $output_schema[ $mapping_key ]['type'] ) {
				continue;
			}

			$output_schema[ $mapping_key ]['type'] = 'button_url';

			if ( 'phone' === ( $profile_mapping['social_platform'] ?? '' ) ) {
				unset( $output_schema[ $mapping_key ]['path'] );

				$output_schema[ $mapping_key ]['generate'] = function ( $data ) use ( $mapping_key ) {
					$phone = $data['fields'][ $mapping_key ] ?? '';

					if ( empty( $phone ) || ! preg_match( '/^\+?[0-9()\s\-]+$/', $phone ) ) {
						return '';
					}

					return 'tel:' . sanitize_text_field( $phone );
				};
			} elseif ( 'mail' === ( $profile_mapping['social_platform'] ?? '' ) ) {
				$output_schema[ $mapping_key ]['type'] = 'email_address';
			}
		}

		$output_schema['slug'] = array(
			'name'     => 'slug',
			'generate' => array( $this, 'generate_slug' ),
			'type'     => 'id',
		);

		$output_schema['permalink'] = array(
			'name'     => 'permalink',
			'generate' => function ( $data ): string {
				$base_path = Rewrite_Rule_Registrar::get_instance()->get_base_path();
				$slug = $this->generate_slug( $data );

				return sprintf( '/%s/%s/%s', $base_path, $this->config['slug'], $slug );
			},
			'type'     => 'button_url',
		);

		return $output_schema;
	}

	/**
	 * Get output schema mappings for import.
	 *
	 * @param array $table Table configuration.
	 *
	 * @return array
	 */
	private function get_output_schema_mappings_for_import( array $table ): array {
		$output_schema = array();

		foreach ( $table['output_query_mappings'] as $remote_mapping ) {
			$mapping_key = $remote_mapping['key'];

			$output_schema[ $mapping_key ] = array(
				'name' => $remote_mapping['name'] ?? $mapping_key,
				'path' => $remote_mapping['path'] ?? '$.fields["' . $mapping_key . '"]',
				'type' => $remote_mapping['type'] ?? 'string',
			);
		}

		$output_schema['slug'] = array(
			'name'     => 'slug',
			'generate' => array( $this, 'generate_slug' ),
			'type'     => 'id',
		);

		return $output_schema;
	}

	/**
	 * Generate slug from data.
	 *
	 * @param array $data Row data.
	 *
	 * @return string
	 */
	public function generate_slug( $data ): string {
		$values = array_map(
			function ( $field ) use ( $data ) {
				return ! empty( $data['fields'][ $field ] ) ? sanitize_title( $data['fields'][ $field ] ) : '';
			},
			$this->config['slugFields']
		);
		$values = array_filter( $values );

		if ( empty( $values ) ) {
			return 'unknown';
		}

		return implode( '-', $values );
	}

	/**
	 * Get formula for slug.
	 *
	 * @param string $slug Slug value.
	 *
	 * @return string
	 */
	private function get_formula_for_slug( string $slug ): string {
		if ( empty( $slug ) ) {
			// Return a formula that will never match any records if slug is empty.
			return '1=0';
		}

		$fields = array_map(
			function ( $field ) {
				return sprintf( '{%s}', rawurldecode( str_replace( array( '{', '}' ), '', $field ) ) );
			},
			$this->config['slugFields']
		);

		/**
		 * Build formula to sanitize the field value like WordPress sanitize_title:
		 * 1. LOWER - convert to lowercase.
		 * 2. Replace non-alphanumeric with hyphens.
		 * 3. Replace multiple hyphens with single hyphen.
		 * 4. Trim hyphens from start and end.
		 */
		$sanitized_field = sprintf(
			'REGEX_REPLACE(REGEX_REPLACE(REGEX_REPLACE(LOWER(%s), "[^a-z0-9]+", "-"), "-+", "-"), "^-|-$", "")',
			implode( ' & "-" & ', $fields )
		);


		$formula = sprintf( '%s="%s"', $sanitized_field, addslashes( $slug ) );

		return $formula;
	}
}
