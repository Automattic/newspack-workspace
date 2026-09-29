<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Mock reader-data integration for popups tests.
 *
 * Stands in for a newspack-plugin integration, which owns the prefix and the
 * selection of fields it syncs to its platform.
 *
 * @package Newspack_Popups
 */

if ( ! class_exists( 'Newspack_Popups_Test_Integration' ) ) {
	/**
	 * Configurable stand-in integration.
	 */
	class Newspack_Popups_Test_Integration {
		/**
		 * The platform this integration syncs to, in the newsletter provider's naming.
		 *
		 * @var string|null
		 */
		public $provider_slug = 'mailchimp';

		/**
		 * Prefix of the fields this integration syncs.
		 *
		 * @var string
		 */
		public $prefix = 'NP_';

		/**
		 * Names of the fields this integration syncs, unprefixed.
		 *
		 * @var string[]
		 */
		public $enabled_fields = [ 'Account' ];

		/**
		 * The platform this integration syncs to.
		 *
		 * @return string|null
		 */
		public function get_provider_slug() {
			return $this->provider_slug;
		}

		/**
		 * Prefix of the fields this integration syncs.
		 *
		 * @return string
		 */
		public function get_metadata_prefix() {
			return $this->prefix;
		}

		/**
		 * Names of the fields this integration syncs, unprefixed.
		 *
		 * @return string[]
		 */
		public function get_enabled_outgoing_fields() {
			return $this->enabled_fields;
		}
	}
}
