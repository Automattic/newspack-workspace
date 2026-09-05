<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Universal.Files.SeparateFunctionsFromOO.Mixed
/**
 * Just enough of WooCommerce Memberships to resolve a plan by ID and ask it for its
 * active member count.
 *
 * Deliberately separate from `wc-memberships-mocks.php`. That file also defines
 * `wc_memberships()`, and a function defined for one test stays defined for the rest of
 * the process — every later test that branches on Memberships being active would take
 * that branch against a mock built for a different purpose. This file defines one
 * function and one class, and nothing keys off either.
 *
 * @package Newspack
 */

if ( ! class_exists( 'Newspack_Mock_Counted_Membership_Plan' ) ) {
	/**
	 * A plan that knows its ID and its active member count.
	 */
	class Newspack_Mock_Counted_Membership_Plan {
		/**
		 * Plan post ID.
		 *
		 * @var int
		 */
		private $id;

		/**
		 * Active memberships on the plan.
		 *
		 * @var int
		 */
		private $member_count;

		/**
		 * Constructor.
		 *
		 * @param int $id           Plan post ID.
		 * @param int $member_count Active memberships on the plan.
		 */
		public function __construct( $id, $member_count ) {
			$this->id           = (int) $id;
			$this->member_count = (int) $member_count;
		}

		/**
		 * Plan post ID.
		 *
		 * @return int
		 */
		public function get_id() {
			return $this->id;
		}

		/**
		 * Active memberships on the plan.
		 *
		 * @param string $status Membership status to count.
		 *
		 * @return int
		 */
		public function get_memberships_count( $status = 'any' ) {
			return $this->member_count;
		}
	}
}

if ( ! function_exists( 'wc_memberships_get_membership_plan' ) ) {
	/**
	 * One plan by post ID, recording each lookup.
	 *
	 * The migration resolves a plan's member count on first read and caches it; the
	 * lookup log is how a test sees that the cache held.
	 *
	 * @param int $membership_plan Plan post ID.
	 *
	 * @return Newspack_Mock_Counted_Membership_Plan|null
	 */
	function wc_memberships_get_membership_plan( $membership_plan = null ) {
		global $newspack_mock_counted_plans, $newspack_mock_plan_lookups;
		$newspack_mock_plan_lookups[] = (int) $membership_plan;
		return $newspack_mock_counted_plans[ (int) $membership_plan ] ?? null;
	}
}
