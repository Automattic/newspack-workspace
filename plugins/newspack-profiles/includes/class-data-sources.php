<?php
/**
 * Data sources for the Newspack Profiles plugin.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles;

use RemoteDataBlocks\Store\DataSource\DataSourceConfigManager;

/**
 * Data_Sources class to handle data sources.
 */
class Data_Sources {

	/**
	 * Get available data sources.
	 *
	 * @return array The list of data sources.
	 */
	public static function get_all(): array {
		$data_sources = DataSourceConfigManager::get_all();

		$wpdb_data_sources = get_option( NEWSPACK_PROFILES_DATA_SOURCES_OPTION, array() );

		$data_sources = array_merge( $data_sources, $wpdb_data_sources );

		$supported_sources = array_map(
			array( __CLASS__, 'format_source' ),
			$data_sources,
		);

		$flattened_sources = array();

		foreach ( $supported_sources as $source ) {
			if ( ! empty( $source['type'] ) ) {
				$flattened_sources[] = $source;
			} elseif ( is_array( $source ) ) {
				$flattened_sources = array_merge( $flattened_sources, $source );
			}
		}

		return array_values(
			array_filter(
				array_map(
					'unserialize',
					array_unique( array_map( 'serialize', $flattened_sources ) )
				),
				function ( $source ) {
					return ! empty( $source );
				}
			)
		);
	}

	/**
	 * Get data source by config.
	 *
	 * @param array $config The configuration.
	 *
	 * @return null|array The data source.
	 */
	public static function get( array $config ): ?array {
		$data_sources = self::get_all();

		foreach ( $data_sources as $source ) {
			if (
				'google-sheet' === $source['type']
				&& $source['name'] === $config['name']
				&& $source['spreadsheet'] === $config['spreadsheet']
				&& $source['sheet'] === $config['sheet']
			) {
				return $source;
			}

			if (
				'airtable' === $source['type']
				&& $source['name'] === $config['name']
				&& $source['base'] === $config['base']
				&& $source['table'] === $config['table']
			) {
				return $source;
			}

			if (
				'wpdb' === $source['type']
				&& $source['name'] === $config['name']
				&& $source['table'] === $config['table']
			) {
				return $source;
			}
		}

		return null;
	}

	/**
	 * Format source for output.
	 *
	 * @param array $source The source configuration.
	 */
	private static function format_source( array $source ): array {
		if ( empty( $source['service'] ) ) {
			return array();
		}

		switch ( $source['service'] ) {
			case 'google-sheets':
				return self::format_google_sheets_source( $source );
			case 'airtable':
				return self::format_airtable_source( $source );
			case 'wpdb':
				return self::format_wpdb_source( $source );
			default:
				return array();
		}
	}

	/**
	 * Format Google Sheets source.
	 *
	 * @param array $source The source configuration.
	 */
	private static function format_google_sheets_source( array $source ): array {

		if ( empty( $source['service_config']['spreadsheet']['name'] ) ) {
			return array();
		}

		return array_map(
			function ( $sheet ) use ( $source ) {
				if ( empty( $sheet['name'] ) ) {
					return null;
				}

				return array(
					'type'        => 'google-sheet',
					'name'        => $source['service_config']['display_name'],
					'spreadsheet' => $source['service_config']['spreadsheet']['name'],
					'sheet'       => $sheet['name'],
					'fields'      => self::get_fields_from_query_mappings( $sheet['output_query_mappings'] ),
				);
			},
			$source['service_config']['sheets']
		);
	}

	/**
	 * Format Airtable source.
	 *
	 * @param array $source The source configuration.
	 */
	private static function format_airtable_source( array $source ): array {
		if ( empty( $source['service_config']['base']['name'] ) ) {
			return array();
		}

		return array_map(
			function ( $table ) use ( $source ) {
				if ( empty( $table['name'] ) ) {
					return null;
				}

				return array(
					'type'   => 'airtable',
					'name'   => $source['service_config']['display_name'],
					'base'   => $source['service_config']['base']['name'],
					'table'  => $table['name'],
					'fields' => self::get_fields_from_query_mappings( $table['output_query_mappings'] ),
				);
			},
			$source['service_config']['tables']
		);
	}

	/**
	 * Format WPDB source.
	 *
	 * @param array $source The source configuration.
	 */
	private static function format_wpdb_source( array $source ): array {
		if ( empty( $source['service_config']['table'] ) ) {
			return array();
		}

		return array(
			'type'   => 'wpdb',
			'name'   => $source['service_config']['display_name'],
			'table'  => $source['service_config']['table'],
			'fields' => self::get_fields_from_query_mappings( $source['service_config']['output_query_mappings'] ),
		);
	}

	/**
	 * Fetch example data for a data source.
	 *
	 * @param array $config The profile configuration.
	 *
	 * @return array The data source with example data.
	 */
	public static function get_sample_data( array $config ): array {
		$query_builder = Query_Manager::get_query_builder( $config );

		if ( null === $query_builder || ! $query_builder->has_valid_data_source() ) {
			return array();
		}

		$list_query = $query_builder->get_list_query( true );

		$data = $list_query->execute(
			array(
				'page_size' => 1,
				'cursor'    => '',
			)
		);

		if ( is_wp_error( $data ) || empty( $data['results'][0]['result'] ) ) {
			return array();
		}

		$values = array();

		foreach ( $data['results'][0]['result'] as $key => $value ) {
			$values[ $key ] = empty( $value['value'] ) ? '' : $value['value'];
		}

		return $values;
	}

	/**
	 * Get fields from query mappings.
	 *
	 * @param array $query_mappings The query mappings.
	 *
	 * @return array The fields.
	 */
	private static function get_fields_from_query_mappings( array $query_mappings ): array {
		$fields = array_filter(
			array_column( $query_mappings, 'key' ),
			function ( $value ) {
				return ! empty( trim( $value ) );
			}
		);
		natcasesort( $fields );

		return array_values( $fields );
	}
}
