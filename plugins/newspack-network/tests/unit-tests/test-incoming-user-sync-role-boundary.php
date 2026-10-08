<?php
/**
 * Class Test_Incoming_User_Sync_Role_Boundary
 *
 * @package Newspack_Network
 */

use Newspack_Network\Incoming_Events\Reader_Registered;
use Newspack_Network\Incoming_Events\User_Updated;

require_once __DIR__ . '/mock-reader-activation.php';

/**
 * These two incoming-event handlers resolve the target account by email
 * alone. The tests below pin the boundary that keeps that resolution from
 * reaching an account whose role was set outside the network sync.
 *
 * @group incoming-user-sync
 */
class Test_Incoming_User_Sync_Role_Boundary extends WP_UnitTestCase {

	/**
	 * A role held on this site, outside the synced set, must not be touched
	 * by a reader_registered event matched to it by email.
	 */
	public function test_reader_registered_does_not_change_role_of_existing_non_synced_account() {
		$user_id = $this->factory->user->create(
			[
				'role'       => 'administrator',
				'user_email' => 'owner@example.test',
			]
		);

		$event = new Reader_Registered( 'https://node.example.test', [ 'email' => 'owner@example.test' ], time() );
		$event->maybe_create_user();

		$user = get_user_by( 'id', $user_id );
		$this->assertSame( [ 'administrator' ], $user->roles );
	}

	/**
	 * An existing account with no role of its own still picks up the synced
	 * reader role, same as before the fix — this is the ordinary case the
	 * handler exists for.
	 */
	public function test_reader_registered_adds_synced_role_to_existing_roleless_account() {
		$user_id = $this->factory->user->create(
			[
				'role'       => '',
				'user_email' => 'noaccount@example.test',
			]
		);

		$event = new Reader_Registered( 'https://node.example.test', [ 'email' => 'noaccount@example.test' ], time() );
		$event->maybe_create_user();

		$user = get_user_by( 'id', $user_id );
		$this->assertSame( [ 'subscriber' ], $user->roles );
	}

	/**
	 * A role held on this site, outside the synced set, must not have its
	 * display name or email address changed by a user_updated event matched
	 * to it by email.
	 */
	public function test_user_updated_does_not_change_profile_fields_of_existing_non_synced_account() {
		$user_id = $this->factory->user->create(
			[
				'role'         => 'administrator',
				'user_email'   => 'owner@example.test',
				'display_name' => 'Original Name',
			]
		);

		$event = new User_Updated(
			'https://node.example.test',
			[
				'email' => 'owner@example.test',
				'prop'  => [
					'display_name' => 'Replacement Name',
					'user_email'   => 'replacement@example.test',
				],
			],
			time()
		);
		$event->maybe_update_user();

		$user = get_user_by( 'id', $user_id );
		$this->assertSame( 'owner@example.test', $user->user_email );
		$this->assertSame( 'Original Name', $user->display_name );
	}

	/**
	 * A user_updated event still applies watched profile fields to an
	 * account already holding a synced reader role — the ordinary case the
	 * handler exists for.
	 */
	public function test_user_updated_updates_profile_fields_of_existing_synced_reader_account() {
		$user_id = $this->factory->user->create(
			[
				'role'         => 'subscriber',
				'user_email'   => 'reader@example.test',
				'display_name' => 'Old Name',
			]
		);

		$event = new User_Updated(
			'https://node.example.test',
			[
				'email' => 'reader@example.test',
				'prop'  => [
					'display_name' => 'New Name',
					'user_email'   => 'reader-new@example.test',
				],
			],
			time()
		);
		$event->maybe_update_user();

		$user = get_user_by( 'id', $user_id );
		$this->assertSame( 'reader-new@example.test', $user->user_email );
		$this->assertSame( 'New Name', $user->display_name );
	}

	/**
	 * Watched meta (author bio, social links, etc.) still syncs to a
	 * non-synced account — the role boundary above applies only to the
	 * display name and email address, not to this existing bio-sync use.
	 */
	public function test_user_updated_still_updates_watched_meta_of_existing_non_synced_account() {
		$user_id = $this->factory->user->create(
			[
				'role'       => 'editor',
				'user_email' => 'byline@example.test',
			]
		);

		$event = new User_Updated(
			'https://node.example.test',
			[
				'email' => 'byline@example.test',
				'meta'  => [
					'description' => 'Updated bio',
				],
			],
			time()
		);
		$event->maybe_update_user();

		$this->assertSame( 'Updated bio', get_user_meta( $user_id, 'description', true ) );
	}
}
