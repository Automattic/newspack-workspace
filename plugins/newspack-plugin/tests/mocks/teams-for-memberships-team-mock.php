<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing, WordPress.NamingConventions.PrefixAllGlobals

/**
 * Stub SkyVerge Teams Team for sync_member_end_date_to_team() unit tests.
 *
 * Carries the real class name so the method's is_a() guard passes, and answers
 * the get_membership_end_date() call the method makes.
 *
 * @package Newspack\Tests
 */

namespace SkyVerge\WooCommerce\Memberships\Teams;

if ( ! class_exists( __NAMESPACE__ . '\\Team' ) ) {
	class Team {
		public $id;
		public $end_ts;

		public function __construct( $id, $end_ts ) {
			$this->id     = $id;
			$this->end_ts = $end_ts;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_membership_end_date( $format = 'mysql' ) {
			if ( empty( $this->end_ts ) ) {
				return null;
			}
			return 'timestamp' === $format ? (int) $this->end_ts : gmdate( 'Y-m-d H:i:s', (int) $this->end_ts );
		}
	}
}
