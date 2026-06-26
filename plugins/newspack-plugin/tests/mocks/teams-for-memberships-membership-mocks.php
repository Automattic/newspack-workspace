<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing, WordPress.NamingConventions.PrefixAllGlobals

/**
 * Stub User Membership for sync_member_end_date_to_team() unit tests, plus the
 * namespaced Team stub it pairs with.
 *
 * These carry the real class names so the method's is_a() guards pass. The hook
 * records set_end_date() calls; update_status()/status_calls are retained only as a
 * forward regression guard -- the hook makes no status change today, so the
 * status_calls assertions trip only if one is ever reintroduced. Runs where neither
 * WC Memberships nor SkyVerge Teams is loaded.
 *
 * @package Newspack\Tests
 */

require_once __DIR__ . '/teams-for-memberships-team-mock.php';

if ( ! class_exists( 'WC_Memberships_User_Membership' ) ) {
	class WC_Memberships_User_Membership {
		public $end_ts;
		public $status;
		public $set_end_calls = [];
		public $status_calls  = [];
		public $notes         = [];

		public function __construct( $end_ts = 0, $status = 'active' ) {
			$this->end_ts = $end_ts;
			$this->status = $status;
		}

		public function get_end_date( $format = 'mysql' ) {
			if ( empty( $this->end_ts ) ) {
				return null;
			}
			return 'timestamp' === $format ? (int) $this->end_ts : gmdate( 'Y-m-d H:i:s', (int) $this->end_ts );
		}

		public function set_end_date( $date = '' ) {
			$this->set_end_calls[] = $date;
			$this->end_ts          = is_numeric( $date ) ? (int) $date : strtotime( $date );
		}

		public function update_status( $status ) {
			$this->status_calls[] = $status;
			$this->status         = $status;
		}

		public function add_note( $note ) {
			$this->notes[] = $note;
		}
	}
}
