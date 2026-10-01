<?php
/**
 * Class TestResend
 *
 * @package Newspack_Network
 */

use Newspack_Network\Incoming_Events\Reader_Registered;

/**
 * Re-sending what a site holds for a reader when a sibling site creates their account.
 */
class TestResend extends WP_UnitTestCase {

	/**
	 * A sibling's registration event re-sends each of this site's grants for the email.
	 *
	 * The event creates this site's account for the reader, and the re-send runs once it exists.
	 */
	public function test_registration_elsewhere_resends_this_sites_grants() {
		$sent = [];
		add_filter(
			'newspack_network_resend_pairs',
			function ( $pairs, $user_id, $email ) {
				return 'reader@example.test' === $email ? [ [ 'newspack_node_group_seat_changed', [ 'id' => 90 ] ] ] : $pairs;
			},
			10,
			3
		);
		add_action(
			'newspack_network_resend',
			function ( $action, $data ) use ( &$sent ) {
				$sent[] = [ $action, $data ];
			},
			10,
			2
		);

		( new Reader_Registered( 'https://other.example.test', [ 'email' => 'reader@example.test' ], time() ) )->process_in_node();

		$this->assertSame( [ [ 'newspack_node_group_seat_changed', [ 'id' => 90 ] ] ], $sent );
	}

	/**
	 * This site's own registration event re-sends nothing: only a sibling's can mean a new account elsewhere.
	 */
	public function test_local_registration_does_not_resend() {
		$sent = [];
		add_filter(
			'newspack_network_resend_pairs',
			function () {
				return [ [ 'newspack_node_group_seat_changed', [ 'id' => 90 ] ] ];
			}
		);
		add_action(
			'newspack_network_resend',
			function ( $action, $data ) use ( &$sent ) {
				$sent[] = [ $action, $data ];
			},
			10,
			2
		);

		( new Reader_Registered( get_bloginfo( 'url' ), [ 'email' => 'reader@example.test' ], time() ) )->process_in_node();

		$this->assertSame( [], $sent );
	}
}
