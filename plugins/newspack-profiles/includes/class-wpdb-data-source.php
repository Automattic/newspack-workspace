<?php
/**
 * WordPress database (wpdb) data source.
 *
 * @package NewspackProfiles
 */

declare(strict_types = 1);

namespace NewspackProfiles;

use NewspackProfiles\Interfaces\Wpdb_Data_Source_Interface;
use RemoteDataBlocks\Config\ArraySerializable;
use RemoteDataBlocks\Validation\Types;

/**
 * Class Wpdb_Data_Source
 *
 * Represents a data source for WordPress database queries.
 */
class Wpdb_Data_Source extends ArraySerializable implements Wpdb_Data_Source_Interface {

	/**
	 * Get the display name of the data source.
	 *
	 * @return string
	 */
	final public function get_display_name(): string {
		return $this->config['service_config']['display_name'];
	}

	/**
	 * Get the table name of the data source.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . $this->config['service_config']['table'];
	}

	/**
	 * Get the image URL for the data source.
	 *
	 * @return string|null
	 */
	public function get_image_url(): ?string {
		return $this->config['service_config']['image_url'] ?? null;
	}

	/**
	 * Get the configuration schema for the data source.
	 *
	 * @return array
	 */
	public static function get_config_schema(): array {
		return Types::object(
			array(
				'service'        => Types::const( 'wpdb' ),
				'service_config' => Types::object(
					array(
						'display_name'          => Types::string(),
						'table'                 => Types::string(),
						'output_query_mappings' => Types::list_of(
							Types::object(
								array(
									'key'  => Types::string(),
									'name' => Types::nullable( Types::string() ),
									'path' => Types::nullable( Types::json_path() ),
									'type' => Types::nullable( Types::string() ),
								)
							)
						),
						'image_url'             => Types::nullable(
							Types::string()
						),
					),
				),
			)
		);
	}
}
