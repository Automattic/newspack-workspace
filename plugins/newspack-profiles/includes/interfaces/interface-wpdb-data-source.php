<?php
/**
 * Interface for WordPress database (wpdb) data sources.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles\Interfaces;

use RemoteDataBlocks\Config\DataSource\DataSourceInterface;

interface Wpdb_Data_Source_Interface extends DataSourceInterface {

	/**
	 * Get the display name of the data source.
	 *
	 * @return string
	 */
	public function get_table_name(): string;
}
