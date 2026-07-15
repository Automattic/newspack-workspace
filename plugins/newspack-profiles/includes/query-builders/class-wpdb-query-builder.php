<?php
/**
 * WordPress database (wpdb) query builder.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles\QueryBuilders;

use NewspackProfiles\Interfaces\Query_Builder_Interface;
use NewspackProfiles\Registrars\Rewrite_Rule_Registrar;
use NewspackProfiles\Wpdb_Data_Source;
use NewspackProfiles\Wpdb_Query;

use const NewspackProfiles\NEWSPACK_PROFILES_DATA_SOURCES_OPTION;

/**
 * Wpdb_Query_Builder class to build queries for imported profiles stored in custom tables using WPDB.
 */
class Wpdb_Query_Builder implements Query_Builder_Interface {

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
		$data_sources = get_option( NEWSPACK_PROFILES_DATA_SOURCES_OPTION, array() );

		if ( empty( $data_sources[ $data_source_config['table'] ] ) ) {
			$this->data_source = null;
			$this->table       = null;
			return;
		}

		$data_source = $data_sources[ $data_source_config['table'] ];

		$this->data_source = Wpdb_Data_Source::from_array( $data_source );
		$this->table       = $data_source['service_config'];
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
	 * @return Wpdb_Query
	 */
	public function get_item_query( bool $for_import = false ): Wpdb_Query {
		$input_schema = array(
			'slug' => array(
				'name' => 'Profile Slug',
				'type' => 'id',
			),
		);

		$output_schema = array(
			'is_collection' => false,
			'path'          => '$[*]',
			'type'          => $for_import ? $this->get_output_schema_mappings_for_import( $this->table ) : $this->get_output_schema_mappings( $this->table ),
		);

		return Wpdb_Query::from_array(
			array(
				'display_name'  => sprintf( 'Get %s row by slug', $this->table['table'] ),
				'data_source'   => $this->data_source,
				'input_schema'  => $input_schema,
				'output_schema' => $output_schema,
				'sql_query'     => function ( array $input_variables ): string {
					global $wpdb;

					$slug = $input_variables['slug'] ?? '';

					return $wpdb->prepare(
						'SELECT * FROM %i WHERE slug = %s LIMIT 1;',
						$this->data_source->get_table_name(),
						sanitize_title( $slug )
					);
				},
			)
		);
	}

	/**
	 * Get list query.
	 *
	 * @param bool $for_import Whether the query is for import.
	 *
	 * @return Wpdb_Query
	 */
	public function get_list_query( bool $for_import = false ): Wpdb_Query {
		$output_schema = array(
			'is_collection' => true,
			'type'          => $this->get_output_schema_mappings( $this->table ),
		);

		return Wpdb_Query::from_array(
			array(
				'display_name'      => sprintf( 'List %s rows', $this->table['table'] ),
				'data_source'       => $this->data_source,
				'table_name'        => $this->data_source->get_table_name(),
				'output_schema'     => $output_schema,
				'sql_query'         => function ( array $input_variables ): string {
					global $wpdb;

					$offset    = isset( $input_variables['cursor'] ) ? intval( $input_variables['cursor'] ) : 0;
					$page_size = isset( $input_variables['page_size'] ) ? intval( $input_variables['page_size'] ) : 10;

					return $wpdb->prepare(
						'SELECT * FROM %i LIMIT %d OFFSET %d;',
						$this->data_source->get_table_name(),
						$page_size,
						$offset
					);
				},
				'count_query'       => function (): string {
					global $wpdb;

					return $wpdb->prepare(
						'SELECT COUNT(*) FROM %i',
						$this->data_source->get_table_name(),
					);
				},
				'input_schema'      => array(
					'cursor'    => array(
						'name'          => 'Pagination cursor',
						'default_value' => 0,
						'required'      => false,
						'type'          => 'ui:pagination_cursor',
					),
					'page_size' => array(
						'name'          => 'Page Size',
						'default_value' => 10,
						'required'      => false,
						'type'          => 'ui:pagination_per_page',
					),
				),
				'pagination_schema' => array(
					'cursor_next'     => array(
						'name'     => 'Next page cursor',
						'generate' => function ( array $response_data ) {
							$total  = intval( $response_data['total'] ?? 0 );
							$offset = intval( $response_data['offset'] ?? 0 );
							$limit  = intval( $response_data['limit'] ?? 10 );

							if ( ( $offset + $limit ) >= $total ) {
								return null;
							}

							return (string) ( $offset + $limit );
						},
						'type'     => 'string',
					),
					'cursor_previous' => array(
						'name'     => 'Previous page cursor',
						'generate' => function ( array $response_data ) {
							$offset = intval( $response_data['offset'] ?? 0 );
							$limit  = intval( $response_data['limit'] ?? 10 );

							$previous_offset = $offset - $limit;

							if ( $previous_offset < 0 ) {
								return null;
							}

							return (string) $previous_offset;
						},
						'type'     => 'string',
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
		return array();
	}

	/**
	 * Get output schema mappings.
	 *
	 * @param array $table Data source table configuration.
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
				'path' => '$["' . $mapping_key . '"]',
				'type' => $profile_mapping['type'] ?? $remote_mapping['type'] ?? 'string',
			);

			if ( 'social_link' !== $output_schema[ $mapping_key ]['type'] ) {
				continue;
			}

			$output_schema[ $mapping_key ]['type'] = 'button_url';

			if ( 'phone' === ( $profile_mapping['social_platform'] ?? '' ) ) {
				unset( $output_schema[ $mapping_key ]['path'] );

				$output_schema[ $mapping_key ]['generate'] = function ( $data ) use ( $mapping_key ) {
					$phone = $data[ $mapping_key ] ?? '';

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
			'name' => 'slug',
			'path' => '$["slug"]',
			'type' => 'id',
		);

		$output_schema['permalink'] = array(
			'name'     => 'permalink',
			'generate' => function ( $data ): string {
				$base_path = Rewrite_Rule_Registrar::get_instance()->get_base_path();
				$slug = $data['slug'] ?? 'unknown';

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
				'path' => '$["' . $mapping_key . '"]',
				'type' => $remote_mapping['type'] ?? 'string',
			);
		}

		$output_schema['slug'] = array(
			'name' => 'slug',
			'path' => '$["slug"]',
			'type' => 'id',
		);

		return $output_schema;
	}
}
