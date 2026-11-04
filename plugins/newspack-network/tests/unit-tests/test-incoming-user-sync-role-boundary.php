<?php
/**
 * Class Test_Incoming_User_Sync_Role_Boundary
 *
 * @package Newspack_Network
 */

use Newspack_Network\Incoming_Events\Reader_Registered;
use Newspack_Network\Incoming_Events\User_Manually_Synced;
use Newspack_Network\Incoming_Events\User_Updated;
use Newspack_Network\User_Update_Watcher;

require_once __DIR__ . '/mock-reader-activation.php';

/**
 * These incoming-event handlers resolve the target account by email
 * alone. The tests below pin the boundary that keeps that resolution from
 * reaching an account whose role was set outside the network sync.
 *
 * @group incoming-user-sync
 */
class Test_Incoming_User_Sync_Role_Boundary extends WP_UnitTestCase {

	/**
	 * Whether the user update watcher was enabled before the test.
	 *
	 * @var bool
	 */
	private $original_watcher_enabled;

	/**
	 * Save the process-scoped watcher flag the handlers under test disable.
	 */
	public function set_up() {
		parent::set_up();
		$this->original_watcher_enabled = User_Update_Watcher::$enabled;
	}

	/**
	 * Restore the watcher flag so later tests in this process see it as they left it.
	 */
	public function tear_down() {
		User_Update_Watcher::$enabled = $this->original_watcher_enabled;
		parent::tear_down();
	}

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
	 * reader role, same as before the fix—this is the ordinary case the
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
	 * A user_updated event also applies watched profile fields to an account
	 * with no role of its own, same as the synced-reader case below—
	 * is_syncable_account() treats both states as safe to sync, and the
	 * roleless case above only exercises reader_registered's role-add path,
	 * not this one.
	 */
	public function test_user_updated_updates_profile_fields_of_existing_roleless_account() {
		$user_id = $this->factory->user->create(
			[
				'role'         => '',
				'user_email'   => 'noaccount@example.test',
				'display_name' => 'Old Name',
			]
		);

		$event = new User_Updated(
			'https://node.example.test',
			[
				'email' => 'noaccount@example.test',
				'prop'  => [
					'display_name' => 'New Name',
					'user_email'   => 'noaccount-new@example.test',
				],
			],
			time()
		);
		$event->maybe_update_user();

		$user = get_user_by( 'id', $user_id );
		$this->assertSame( 'noaccount-new@example.test', $user->user_email );
		$this->assertSame( 'New Name', $user->display_name );
	}

	/**
	 * A user_updated event still applies watched profile fields to an
	 * account already holding a synced reader role—the ordinary case the
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
	 * An account holding a synced role alongside another role is still a
	 * non-synced account: every role it holds must be in the synced set, not
	 * just one of them. A role gained this way is exactly what the fixed
	 * reader_registered handler above no longer adds, but an account that
	 * already carried one before the fix must stay protected too.
	 */
	public function test_user_updated_does_not_change_profile_fields_of_existing_mixed_role_account() {
		$user_id = $this->factory->user->create(
			[
				'role'         => 'administrator',
				'user_email'   => 'owner@example.test',
				'display_name' => 'Original Name',
			]
		);
		get_user_by( 'id', $user_id )->add_role( 'subscriber' );

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
	 * The user URL is watched alongside the display name and email
	 * (User_Update_Watcher::$user_props), but the role boundary applies only
	 * to the latter two—a non-synced account's website still syncs, the
	 * same as before the fix.
	 */
	public function test_user_updated_still_updates_user_url_of_existing_non_synced_account() {
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
				'prop'  => [
					'user_url' => 'https://example.test/byline',
				],
			],
			time()
		);
		$event->maybe_update_user();

		$this->assertSame( 'https://example.test/byline', get_user_by( 'id', $user_id )->user_url );
	}

	/**
	 * Watched meta (author bio, social links, etc.) still syncs to a
	 * non-synced account—the role boundary above applies only to the
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

	/**
	 * Builds a manual-sync event for the given email, as a Node sends it.
	 *
	 * @param string $email The email the event resolves its target by.
	 * @param array  $roles The roles the sending site holds for that account.
	 * @return User_Manually_Synced
	 */
	private function manual_sync_event( $email, $roles ) {
		return new User_Manually_Synced(
			'https://node.example.test',
			[
				'email'   => $email,
				'user_id' => 42,
				'role'    => $roles,
				'prop'    => [
					'display_name' => 'Replacement Name',
					'user_email'   => 'replacement@example.test',
					'user_url'     => 'https://replacement.example.test',
				],
				'meta'    => [
					'description' => 'Replacement bio',
				],
			],
			time()
		);
	}

	/**
	 * A manual sync carrying only reader roles leaves an account that holds a
	 * staff role here as it is: no demotion, no new display name or email, so a
	 * demotion to reader roles has to be made on each site. The URL and watched
	 * meta still apply, as they do for User_Updated.
	 */
	public function test_manual_sync_with_reader_roles_does_not_demote_or_rename_staff_account() {
		$user_id = $this->factory->user->create(
			[
				'role'         => 'administrator',
				'user_email'   => 'owner@example.test',
				'display_name' => 'Original Name',
			]
		);

		$this->manual_sync_event( 'owner@example.test', [ 'subscriber' ] )->maybe_sync_user();

		$user = get_user_by( 'id', $user_id );
		$this->assertSame( [ 'administrator' ], $user->roles );
		$this->assertSame( 'Original Name', $user->display_name );
		$this->assertSame( 'owner@example.test', $user->user_email );
		$this->assertSame( 'https://replacement.example.test', $user->user_url );
		$this->assertSame( 'Replacement bio', get_user_meta( $user_id, 'description', true ) );
	}

	/**
	 * A payload with no roles at all would otherwise strip every role from the
	 * target, so it gets the same treatment as a reader-only one.
	 */
	public function test_manual_sync_with_no_roles_leaves_staff_account_roles() {
		$user_id = $this->factory->user->create(
			[
				'role'       => 'editor',
				'user_email' => 'byline@example.test',
			]
		);

		$this->manual_sync_event( 'byline@example.test', [] )->maybe_sync_user();

		$this->assertSame( [ 'editor' ], get_user_by( 'id', $user_id )->roles );
	}

	/**
	 * Holding a reader role alongside a staff role doesn't make an account a
	 * reader: a reader-only payload still leaves its roles and identity alone.
	 */
	public function test_manual_sync_with_reader_roles_leaves_mixed_role_account() {
		$user_id = $this->factory->user->create(
			[
				'role'         => 'editor',
				'user_email'   => 'mixed@example.test',
				'display_name' => 'Original Name',
			]
		);
		get_user_by( 'id', $user_id )->add_role( 'subscriber' );

		$this->manual_sync_event( 'mixed@example.test', [ 'subscriber' ] )->maybe_sync_user();

		$user = get_user_by( 'id', $user_id );
		$this->assertContains( 'editor', $user->roles );
		$this->assertSame( 'Original Name', $user->display_name );
		$this->assertSame( 'mixed@example.test', $user->user_email );
	}

	/**
	 * Propagating a role outside the reader set is what manual sync is for, and
	 * still applies in full, identity fields included.
	 */
	public function test_manual_sync_still_propagates_a_staff_role() {
		$user_id = $this->factory->user->create(
			[
				'role'         => 'subscriber',
				'user_email'   => 'promoted@example.test',
				'display_name' => 'Original Name',
			]
		);

		$this->manual_sync_event( 'promoted@example.test', [ 'administrator' ] )->maybe_sync_user();

		$user = get_user_by( 'id', $user_id );
		$this->assertSame( [ 'administrator' ], $user->roles );
		$this->assertSame( 'Replacement Name', $user->display_name );
		$this->assertSame( 'replacement@example.test', $user->user_email );
	}

	/**
	 * The guard covers reader-only payloads alone: a staff role sent to an
	 * account that already holds another one replaces it, demotions included.
	 */
	public function test_manual_sync_still_applies_a_staff_demotion() {
		$user_id = $this->factory->user->create(
			[
				'role'         => 'administrator',
				'user_email'   => 'demoted@example.test',
				'display_name' => 'Original Name',
			]
		);

		$this->manual_sync_event( 'demoted@example.test', [ 'editor' ] )->maybe_sync_user();

		$user = get_user_by( 'id', $user_id );
		$this->assertSame( [ 'editor' ], array_values( $user->roles ) );
		$this->assertSame( 'Replacement Name', $user->display_name );
		$this->assertSame( 'replacement@example.test', $user->user_email );
	}

	/**
	 * A reader account still takes the incoming identity fields: the guard
	 * reaches only accounts holding another role.
	 */
	public function test_manual_sync_still_updates_reader_account() {
		$user_id = $this->factory->user->create(
			[
				'role'         => 'subscriber',
				'user_email'   => 'reader@example.test',
				'display_name' => 'Original Name',
			]
		);

		$this->manual_sync_event( 'reader@example.test', [ 'subscriber' ] )->maybe_sync_user();

		$user = get_user_by( 'id', $user_id );
		$this->assertSame( [ 'subscriber' ], $user->roles );
		$this->assertSame( 'Replacement Name', $user->display_name );
		$this->assertSame( 'replacement@example.test', $user->user_email );
	}
}
