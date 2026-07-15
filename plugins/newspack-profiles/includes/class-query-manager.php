<?php
/**
 * Query manager that resolves the query builder for a data source.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles;

use NewspackProfiles\QueryBuilders\Airtable_Query_Builder;
use NewspackProfiles\QueryBuilders\Google_Sheet_Query_Builder;
use NewspackProfiles\Interfaces\Query_Builder_Interface;
use NewspackProfiles\QueryBuilders\Wpdb_Query_Builder;

/**
 * Query_Manager class.
 */
class Query_Manager {

	/**
	 * Get query builder based on profile configuration.
	 *
	 * @param array $profile_collection_config Profile configuration.
	 *
	 * @return Query_Builder_Interface|null
	 */
	public static function get_query_builder( array $profile_collection_config ): ?Query_Builder_Interface {
		switch ( $profile_collection_config['dataSource']['type'] ) {
			case 'google-sheet':
				return new Google_Sheet_Query_Builder( $profile_collection_config );
			case 'airtable':
				return new Airtable_Query_Builder( $profile_collection_config );
			case 'wpdb':
				return new Wpdb_Query_Builder( $profile_collection_config );
			default:
				return null;
		}
	}
}
