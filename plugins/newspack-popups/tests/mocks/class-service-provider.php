<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Mock ESP service provider for popups tests.
 *
 * Stands in for a newspack-newsletters service provider so the account-param
 * link handler can resolve a merge-tag name without the plugin loaded.
 *
 * @package Newspack_Popups
 */

if ( ! class_exists( 'Newspack_Popups_Test_Service_Provider' ) ) {
	/**
	 * Configurable stand-in provider.
	 */
	class Newspack_Popups_Test_Service_Provider {
		/**
		 * Tag name to return, keyed by field name.
		 *
		 * @var array
		 */
		public $tags = [];

		/**
		 * List ID the handler passed in on the last call.
		 *
		 * @var string|null
		 */
		public $received_list_id = 'unset';

		/**
		 * Resolve a field's merge-tag name.
		 *
		 * @param string      $field_name Field name.
		 * @param string|null $list_id    Audience ID.
		 * @return string
		 */
		public function get_field_merge_tag_name( $field_name, $list_id = null ) {
			$this->received_list_id = $list_id;
			return $this->tags[ $field_name ] ?? '';
		}
	}
}
