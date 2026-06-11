<?php
/**
 * Class TestMembershipDeletedEvent
 *
 * @package Newspack_Network
 */

use Newspack_Network\Woocommerce_Memberships\Events as Memberships_Events;

require_once __DIR__ . '/mock-wc-memberships.php';

/**
 * Test Events::membership_deleted() handling of a missing parent plan.
 *
 * @group membership-deleted-event
 */
class TestMembershipDeletedEvent extends WP_UnitTestCase {

	/**
	 * Calling membership_deleted() with a membership whose plan has been deleted must not fatal.
	 *
	 * Reproduces the production fatal:
	 *   Uncaught Error: Call to a member function get_id() on false
	 * Triggered when `wp_delete_auto_drafts` cascades into membership deletion after the
	 * parent plan post is already gone, so `$user_membership->get_plan()` returns false.
	 */
	public function test_does_not_fatal_when_plan_is_missing() {
		$user_id   = $this->factory->user->create( [ 'user_email' => 'stub@example.com' ] );
		$test_user = get_user_by( 'id', $user_id );

		$membership_stub = new class( $test_user ) {
			/**
			 * Backing user returned by get_user().
			 *
			 * @var \WP_User
			 */
			private $user;

			/**
			 * Constructor.
			 *
			 * @param \WP_User $user Backing user.
			 */
			public function __construct( $user ) {
				$this->user = $user;
			}

			/**
			 * Return the backing user.
			 *
			 * @return \WP_User
			 */
			public function get_user() {
				return $this->user;
			}

			/**
			 * Return false to reproduce the production fatal path.
			 *
			 * @return false
			 */
			public function get_plan() {
				return false;
			}

			/**
			 * Return the membership id.
			 *
			 * @return int
			 */
			public function get_id() {
				return 42;
			}
		};

		$result = Memberships_Events::membership_deleted( $membership_stub );
		$this->assertNull( $result );
	}
}
