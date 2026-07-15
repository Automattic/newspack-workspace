<?php
/**
 * Profiles management.
 *
 * @package NewspackProfiles
 */

declare( strict_types=1 );

namespace NewspackProfiles;

use NewspackProfiles\Traits\Singleton;

/**
 * Profile_Collections class to manage profiles.
 */
class Profile_Collections {

	use Singleton;

	const OPTION_NAME = 'newspack_profiles_profile_collections';

	/**
	 * Get all profiles.
	 *
	 * @param bool $published_only Whether to return only published collections. Default false.
	 *
	 * @return array The list of profiles.
	 */
	public function get_all( bool $published_only = false ): array {
		$profiles = get_option( self::OPTION_NAME, array() );

		$profiles = is_array( $profiles ) ? $profiles : array();

		if ( $published_only ) {
			$profiles = array_filter(
				$profiles,
				function ( $profile ) {
					return ( isset( $profile['status'] ) && 'publish' === $profile['status'] );
				}
			);
		}

		$profiles = array_map(
			function ( $profile ) {
				$profile['isImporting'] = Import_Manager::get_instance()->is_import_in_progress( $profile['slug'] );
				return $profile;
			},
			$profiles
		);

		return $profiles;
	}

	/**
	 * Add a new profile.
	 *
	 * @param array $profile The profile data.
	 *
	 * @return void
	 */
	public function add( array $profile ): void {
		$profiles   = $this->get_all();
		$profiles[] = $this->sanitize( $profile );

		update_option( self::OPTION_NAME, $profiles );
	}

	/**
	 * Get a profile by slug.
	 *
	 * @param string $profile_slug The profile slug.
	 *
	 * @return array The profile data.
	 */
	public function get( string $profile_slug ): array {
		$profiles = $this->get_all();

		foreach ( $profiles as $profile ) {
			if ( $profile['slug'] === $profile_slug ) {
				return $profile;
			}
		}

		return array();
	}

	/**
	 * Update a profile.
	 *
	 * @param array $updated_profile The updated profile data.
	 *
	 * @return void
	 */
	public function update( array $updated_profile ): void {
		$profiles = $this->get_all();

		foreach ( $profiles as &$profile ) {
			if ( $profile['slug'] === $updated_profile['slug'] ) {
				$profile = $this->sanitize( $updated_profile );
				break;
			}
		}

		update_option( self::OPTION_NAME, $profiles );
	}

	/**
	 * Delete a profile by slug.
	 *
	 * @param string $profile_slug The profile slug.
	 *
	 * @return void
	 */
	public function delete( string $profile_slug ): void {
		$profiles = $this->get_all();

		$profiles = array_filter(
			$profiles,
			function ( $profile ) use ( $profile_slug ) {
				return $profile['slug'] !== $profile_slug;
			}
		);

		update_option( self::OPTION_NAME, array_values( $profiles ) );
	}

	/**
	 * Disconnect the remote data source for a profile.
	 *
	 * @param string $profile_slug The profile slug.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function disconnect_remote_data_source( string $profile_slug ): bool {
		$collection = $this->get( $profile_slug );

		if ( empty( $collection ) ) {
			return false;
		}

		$importer = Import_Manager::get_instance();

		return $importer->import( $collection );
	}

	/**
	 * Sanitize the collection data.
	 *
	 * @param array $collection The collection data.
	 *
	 * @return array The sanitized collection data.
	 */
	private function sanitize( array $collection ): array {
		$source = Data_Sources::get( $collection['dataSource'] );

		$fields = is_array( $source['fields'] ?? '' ) ? $source['fields'] : array();

		$sanitized_mappings = array();

		foreach ( $fields as $field ) {
			if ( isset( $collection['mappings'][ $field ] ) ) {
				$sanitized_mappings[ $field ] = $collection['mappings'][ $field ];
			}
		}

		$collection['mappings'] = $sanitized_mappings;

		return $collection;
	}
}
