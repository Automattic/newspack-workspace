<?php
/**
 * Google Sheets query builder.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles\QueryBuilders;

use NewspackProfiles\Interfaces\Query_Builder_Interface;
use NewspackProfiles\Registrars\Rewrite_Rule_Registrar;
use RemoteDataBlocks\Config\Query\HttpQuery;
use RemoteDataBlocks\Integrations\Google\Sheets\GoogleSheetsDataSource;
use RemoteDataBlocks\Store\DataSource\DataSourceConfigManager;

/**
 * Google_Sheet_Query_Builder class to build queries for Google Sheets data source.
 */
class Google_Sheet_Query_Builder implements Query_Builder_Interface {

	/**
	 * Data source.
	 *
	 * @var object|null
	 */
	private mixed $data_source;

	/**
	 * Data source sheet.
	 *
	 * @var array|null
	 */
	private mixed $sheet;

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
				'service' => REMOTE_DATA_BLOCKS_GOOGLE_SHEETS_SERVICE,
			)
		);

		foreach ( $data_sources as $data_source ) {
			if ( $data_source['service_config']['display_name'] !== $data_source_config['name'] ) {
				continue;
			}

			if ( $data_source['service_config']['spreadsheet']['name'] !== $data_source_config['spreadsheet'] ) {
				continue;
			}

			foreach ( $data_source['service_config']['sheets'] as $sheet ) {
				if ( $sheet['name'] === $data_source_config['sheet'] ) {
					$this->data_source = GoogleSheetsDataSource::from_array( $data_source );
					$this->sheet       = $sheet;
					return;
				}
			}
		}

		$this->data_source = null;
		$this->sheet       = null;
	}

	/**
	 * Check if the query has a valid data source.
	 *
	 * @return bool
	 */
	public function has_valid_data_source(): bool {
		return $this->data_source && $this->sheet;
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
			'type'          => $for_import ? $this->get_output_schema_mappings_for_import( $this->sheet ) : $this->get_output_schema_mappings( $this->sheet ),
		);

		return HttpQuery::from_array(
			array(
				'display_name'        => sprintf( 'Get %s row by ID', $this->sheet['name'] ),
				'data_source'         => $this->data_source,
				'endpoint'            => $this->data_source->get_endpoint() . '/values/' . rawurlencode( $this->sheet['name'] ),
				'input_schema'        => $input_schema,
				'output_schema'       => $output_schema,
				'request_headers'     => function ( array $input_variables ) use ( $for_import ): array {
					return array_merge(
						$this->data_source->get_request_headers(),
						array(
							'slug'       => $input_variables['slug'] ?? null,
							/**
							 * Query type header to differentiate between normal and import queries.
							 * Otherwise, both queries will be cached under the same key,
							 * causing issues with output schema mapping.
							 */
							'query_type' => $for_import ? 'import' : 'normal',
						)
					);
				},
				'preprocess_response' => function ( mixed $response_data, array $request_details ): array {
					$slug = $request_details['options']['headers']['slug'];

					if ( isset( $response_data['values'] ) && is_array( $response_data['values'] ) && ! empty( $slug ) ) {
						$values = $response_data['values'];
						$columns = array_shift( $values );

						if ( empty( $values ) ) {
							return array();
						}

						foreach ( $values as $row ) {
							if ( ! is_array( $row ) ) {
								continue;
							}

							$row = $this->modify_row_to_match_columns( $row, $columns );
							$mapped_row = array_combine( $columns, $row );

							if ( $this->generate_slug( $mapped_row ) === $slug ) {
								$mapped_row['slug'] = $slug;
								return $mapped_row;
							}
						}
					}

					return array();
				},
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
			'path'          => '$.values[*]',
			'type'          => $for_import ? $this->get_output_schema_mappings_for_import( $this->sheet ) : $this->get_output_schema_mappings( $this->sheet ),
		);

		return HttpQuery::from_array(
			array(
				'display_name'        => sprintf( 'List %s rows', $this->sheet['name'] ),
				'data_source'         => $this->data_source,
				'endpoint'            => function ( array $input_variables ): string {
					$endpoint = $this->data_source->get_endpoint() . '/values:batchGet?';

					$cursor = intval( $input_variables['cursor'] ) ? intval( $input_variables['cursor'] ) : 2;
					$page_size = intval( $input_variables['page_size'] ) ? intval( $input_variables['page_size'] ) : 10;

					$header_range = sprintf( "'%s'!1:1", rawurlencode( $this->sheet['name'] ) );
					$data_range = sprintf( "'%s'!%d:%d", rawurlencode( $this->sheet['name'] ), $cursor, $cursor + $page_size - 1 );

					$endpoint .= sprintf( 'ranges=%s&ranges=%s', $header_range, $data_range );

					return $endpoint;
				},
				'request_headers'     => function () use ( $for_import ): array {
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
				'output_schema'       => $output_schema,
				'preprocess_response' => function ( mixed $response_data ): array {
					$columns = $response_data['valueRanges'][0]['values'][0] ?? array();

					if ( ! is_array( $columns ) || count( $columns ) === 0 ) {
						return array();
					}

					$values  = $response_data['valueRanges'][1]['values'] ?? array();

					if ( ! is_array( $values ) || count( $values ) === 0 ) {
						return array();
					}

					$response_data['values'] = array_map(
						function ( $row ) use ( $columns ) {
							$row = $this->modify_row_to_match_columns( $row, $columns );
							$mapped_row = array_combine( $columns, $row );

							$mapped_row['slug'] = $this->generate_slug( $mapped_row );

							return $mapped_row;
						},
						$values,
						array_keys( $values )
					);

					return $response_data;
				},
				'input_schema'        => array(
					'cursor'    => array(
						'name'          => 'Pagination cursor',
						'default_value' => 2,
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
				'pagination_schema'   => array(
					'cursor_next'     => array(
						'name'     => 'Next page cursor',
						'generate' => function ( array $response_data ) {
							$range  = $response_data['valueRanges'][1]['range'] ?? '';

							if ( empty( $range ) ) {
								return null;
							}

							preg_match( '/^.*?![A-Z]+([0-9]+):[A-Z]+([0-9]+)$/', $range, $matches );

							$end_row_number   = (int) ( $matches[2] ?? 0 );

							return (string) ( $end_row_number + 1 );
						},
						'type'     => 'string',
					),
					'cursor_previous' => array(
						'name'     => 'Previous page cursor',
						'generate' => function ( array $response_data ) {
							$range  = $response_data['valueRanges'][1]['range'] ?? '';

							if ( empty( $range ) ) {
								return null;
							}

							preg_match( '/^.*?![A-Z]+([0-9]+):[A-Z]+([0-9]+)$/', $range, $matches );

							$start_row_number = (int) ( $matches[1] ?? 0 );
							$end_row_number   = (int) ( $matches[2] ?? 0 );

							$page_size = $end_row_number - $start_row_number + 1;

							if ( $start_row_number - $page_size < 2 ) {
								return null;
							}

							return (string) ( $start_row_number - $page_size );
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
		global $wpdb;

		$mappings = $this->sheet['output_query_mappings'];

		if ( ! is_array( $mappings ) ) {
			return array();
		}

		$sheet_columns = array_column( $mappings, 'key' );

		if ( empty( $sheet_columns ) ) {
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
				$sheet_columns
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
			'output_query_mappings' => $this->sheet['output_query_mappings'],
		);
	}

	/**
	 * Get output schema mappings.
	 *
	 * @param array $sheet Sheet configuration.
	 *
	 * @return array
	 */
	private function get_output_schema_mappings( array $sheet ): array {
		$output_schema = array();

		foreach ( $sheet['output_query_mappings'] as $remote_mapping ) {
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
	 * @param array $sheet Sheet configuration.
	 *
	 * @return array
	 */
	private function get_output_schema_mappings_for_import( array $sheet ): array {
		$output_schema = array();

		foreach ( $sheet['output_query_mappings'] as $remote_mapping ) {
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
	 * Modify row to match columns.
	 *
	 * @param array $row     Row data.
	 * @param array $columns Column names.
	 *
	 * @return array
	 */
	private function modify_row_to_match_columns( array $row, array $columns ): array {
		if ( count( $row ) < count( $columns ) ) {
			$row = array_pad( $row, count( $columns ), '' );
		} elseif ( count( $row ) > count( $columns ) ) {
			$row = array_slice( $row, 0, count( $columns ) );
		}

		return $row;
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
				return ! empty( $data[ $field ] ) ? sanitize_title( $data[ $field ] ) : '';
			},
			$this->config['slugFields']
		);
		$values = array_filter( $values );

		if ( empty( $values ) ) {
			return 'unknown';
		}

		return implode( '-', $values );
	}
}
