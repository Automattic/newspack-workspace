<?php
/**
 * Class Newsletters Test Subscription_List
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Subscription_List;
use Newspack\Newsletters\Subscription_Lists;

/**
 * Tests the Subscription_List class
 */
class Subscription_Lists_Test extends WP_UnitTestCase {

	use Lists_Setup;

	/**
	 * Test get_all
	 */
	public function test_get_all() {
		$all = Subscription_Lists::get_all();
		$this->assertSame( 7, count( $all ) );
	}

	/**
	 * Test get_configured_for_provider
	 */
	public function test_get_configured_for_provider() {
		$all = Subscription_Lists::get_configured_for_provider( 'mailchimp' );
		$this->assertSame( 4, count( $all ) );
		foreach ( $all as $list ) {
			$this->assertInstanceOf( Subscription_List::class, $list );
			$this->assertTrue( $list->is_configured_for_provider( 'mailchimp' ) );
			$this->assertNotSame( self::$posts['without_settings'], $list->get_id() );
			$this->assertNotSame( self::$posts['mc_invalid'], $list->get_id() );
		}

		$all = Subscription_Lists::get_configured_for_provider( 'active_campaign' );
		$this->assertSame( 3, count( $all ) );
		foreach ( $all as $list ) {
			$this->assertInstanceOf( Subscription_List::class, $list );
			$this->assertTrue( $list->is_configured_for_provider( 'active_campaign' ) );
			$this->assertNotSame( self::$posts['only_mailchimp'], $list->get_id() );
		}
	}

	/**
	 * Test get_configured_for_provider
	 */
	public function test_get_configured_for_current_provider() {
		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		$all = Subscription_Lists::get_configured_for_current_provider();
		$this->assertSame( 4, count( $all ) );
		foreach ( $all as $list ) {
			$this->assertInstanceOf( Subscription_List::class, $list );
			$this->assertTrue( $list->is_configured_for_provider( 'mailchimp' ) );
			$this->assertNotSame( self::$posts['without_settings'], $list->get_id() );
			$this->assertNotSame( self::$posts['mc_invalid'], $list->get_id() );
		}

		Newspack_Newsletters::set_service_provider( 'active_campaign' );
		$all = Subscription_Lists::get_configured_for_current_provider();
		$this->assertSame( 3, count( $all ) );
		foreach ( $all as $list ) {
			$this->assertInstanceOf( Subscription_List::class, $list );
			$this->assertTrue( $list->is_configured_for_provider( 'active_campaign' ) );
			$this->assertNotSame( self::$posts['only_mailchimp'], $list->get_id() );
		}
	}

	/**
	 * Test get_filtered
	 */
	public function test_get_filtered() {
		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		$id  = self::$posts['only_mailchimp'];
		$all = Subscription_Lists::get_filtered(
			function ( $list ) use ( $id ) {
				return $list->get_id() === $id;
			}
		);

		$this->assertSame( 1, count( $all ) );
		$this->assertSame( $id, $all[0]->get_id() );
	}

	/**
	 * Test get_list_by_remote_id
	 */
	public function test_get_list_by_remote_id() {
		$existing = 'xyz-' . self::$posts['remote_mailchimp'];
		$found    = Subscription_List::from_public_id( $existing );
		$this->assertSame( self::$posts['remote_mailchimp'], $found->get_id() );
		$this->assertSame( 'mailchimp', $found->get_provider() );
		$this->assertSame( $existing, $found->get_remote_id() );

		$non_existing = 'asdqwe';
		$found        = Subscription_List::from_public_id( $non_existing );
		$this->assertNull( $found );
	}

	/**
	 * Test update_lists forces unconfigured locals to inactive even when
	 * the payload tries to flip them on.
	 */
	public function test_update_lists_forces_unconfigured_local_inactive() {
		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		$unconfigured = new Subscription_List( self::$posts['without_settings'] );
		$this->assertEmpty( $unconfigured->get_configured_providers() );

		$result = Subscription_Lists::update_lists(
			[
				[
					'id'     => $unconfigured->get_public_id(),
					'active' => true,
					'title'  => 'Still No Audience',
				],
			]
		);
		$this->assertTrue( $result );

		$reloaded = new Subscription_List( self::$posts['without_settings'] );
		$this->assertFalse( $reloaded->is_active(), 'Locals without current-provider wiring stay inactive even when active=true is submitted' );
	}

	/**
	 * A `title => null` row used to be dropped by sanitize_lists, which then
	 * tripped the cleanup loop into deactivating the list.
	 */
	public function test_update_lists_keeps_row_when_title_is_null() {
		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		$mc_list = new Subscription_List( self::$posts['only_mailchimp'] );
		$mc_list->update( [ 'active' => true ] );
		$this->assertTrue( $mc_list->is_active() );
		$original_title = $mc_list->get_title();

		$result = Subscription_Lists::update_lists(
			[
				[
					'id'     => $mc_list->get_public_id(),
					'active' => true,
					'title'  => null,
				],
			]
		);
		$this->assertTrue( $result );

		$reloaded = new Subscription_List( self::$posts['only_mailchimp'] );
		$this->assertTrue( $reloaded->is_active(), 'A null title must not cause the row to be dropped and then deactivated by the cleanup loop' );
		$this->assertSame( $original_title, $reloaded->get_title(), 'Stored title is preserved when caller sends title => null' );
	}

	/**
	 * A literal `"0"` title is a legal remote list name; `empty()` would reject it.
	 */
	public function test_update_lists_accepts_string_zero_as_title() {
		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		$count_before = count( Subscription_Lists::get_all() );

		$result = Subscription_Lists::update_lists(
			[
				[
					'id'     => 'xyz-zero-titled',
					'active' => true,
					'title'  => '0',
				],
			]
		);
		$this->assertTrue( $result );

		$created = Subscription_List::from_public_id( 'xyz-zero-titled' );
		$this->assertInstanceOf( Subscription_List::class, $created );
		$this->assertSame( '0', $created->get_title() );
		$this->assertSame( $count_before + 1, count( Subscription_Lists::get_all() ) );
	}

	/**
	 * All-skipped payloads must error rather than fall through to the cleanup
	 * loop, which would otherwise deactivate every scoped list.
	 */
	public function test_update_lists_all_skipped_payload_errors_without_cleanup() {
		Newspack_Newsletters::set_service_provider( 'mailchimp' );

		$mc_list = new Subscription_List( self::$posts['only_mailchimp'] );
		$mc_list->update( [ 'active' => true ] );
		$this->assertTrue( $mc_list->is_active() );

		$count_before = count( Subscription_Lists::get_all() );

		$result = Subscription_Lists::update_lists(
			[
				[
					'id'     => 'xyz-brand-new-unknown',
					'active' => true,
					'title'  => null,
				],
			]
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'newspack_newsletters_invalid_lists', $result->get_error_code() );
		$this->assertSame( $count_before, count( Subscription_Lists::get_all() ), 'Unknown remote id without a title must not be created' );
		$this->assertNull( Subscription_List::from_public_id( 'xyz-brand-new-unknown' ) );

		$reloaded = new Subscription_List( self::$posts['only_mailchimp'] );
		$this->assertTrue( $reloaded->is_active(), 'Existing scoped lists must remain active when every payload row was skipped' );
	}

	/**
	 * `description => null` used to be cast to `''` and clobber the stored value.
	 */
	public function test_update_lists_preserves_description_when_passed_null() {
		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		$mc_list = new Subscription_List( self::$posts['only_mailchimp'] );
		$mc_list->update( [ 'active' => true ] );
		$original_description = $mc_list->get_description();
		$this->assertNotSame( '', $original_description );

		$result = Subscription_Lists::update_lists(
			[
				[
					'id'          => $mc_list->get_public_id(),
					'active'      => true,
					'title'       => $mc_list->get_title(),
					'description' => null,
				],
			]
		);
		$this->assertTrue( $result );

		$reloaded = new Subscription_List( self::$posts['only_mailchimp'] );
		$this->assertSame( $original_description, $reloaded->get_description() );
	}

	/**
	 * Test update_lists doesn't drop other-provider rows that were hidden
	 * from the current-provider UI.
	 */
	public function test_update_lists_preserves_other_provider_locals() {
		// Activate the AC-only local list (`mc_invalid` has AC settings,
		// mailchimp errored — appears under AC, hidden under mailchimp).
		Newspack_Newsletters::set_service_provider( 'active_campaign' );
		$ac_only_local = new Subscription_List( self::$posts['mc_invalid'] );
		$ac_only_local->update( [ 'active' => true ] );
		$this->assertTrue( $ac_only_local->is_active() );

		// Save mailchimp lists with a minimal valid payload — `mc_invalid`
		// must not get drafted by the cleanup loop.
		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		$result = Subscription_Lists::update_lists(
			[
				[
					'id'     => 'xyz-' . self::$posts['remote_mailchimp'],
					'active' => true,
					'title'  => 'Remote MC',
				],
			]
		);
		$this->assertTrue( $result );

		$reloaded = new Subscription_List( self::$posts['mc_invalid'] );
		$this->assertTrue( $reloaded->is_active(), 'AC-only local list stayed active despite the mailchimp save' );
	}

	/**
	 * Test get_locals_for_current_provider returns current-provider locals
	 * plus genuinely unconfigured ones, excluding other-provider-only locals.
	 */
	public function test_get_locals_for_current_provider() {
		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		$mc_ids = wp_list_pluck(
			array_map(
				function ( $list ) {
					return [ 'id' => $list->get_id() ];
				},
				Subscription_Lists::get_locals_for_current_provider()
			),
			'id'
		);
		$this->assertContains( self::$posts['only_mailchimp'], $mc_ids, 'mailchimp-only local appears under mailchimp' );
		$this->assertContains( self::$posts['two_settings'], $mc_ids, 'multi-provider local appears under mailchimp' );
		$this->assertContains( self::$posts['without_settings'], $mc_ids, 'genuinely unconfigured local always appears' );
		$this->assertNotContains( self::$posts['mc_invalid'], $mc_ids, 'mailchimp-errored local with AC settings is hidden under mailchimp' );

		Newspack_Newsletters::set_service_provider( 'active_campaign' );
		$ac_ids = wp_list_pluck(
			array_map(
				function ( $list ) {
					return [ 'id' => $list->get_id() ];
				},
				Subscription_Lists::get_locals_for_current_provider()
			),
			'id'
		);
		$this->assertNotContains( self::$posts['only_mailchimp'], $ac_ids, 'mailchimp-only local is hidden under active_campaign' );
		$this->assertContains( self::$posts['two_settings'], $ac_ids, 'multi-provider local appears under active_campaign' );
		$this->assertContains( self::$posts['mc_invalid'], $ac_ids, 'AC-configured local appears under active_campaign even when mailchimp errored' );
		$this->assertContains( self::$posts['without_settings'], $ac_ids, 'genuinely unconfigured local appears under active_campaign too' );
	}

	/**
	 * Test create_local_list
	 */
	public function test_create_local_list() {
		$count = count( Subscription_Lists::get_all() );

		$list = Subscription_Lists::create_local_list( 'Local List Title', 'A description.' );
		$this->assertInstanceOf( Subscription_List::class, $list );
		$this->assertSame( 'Local List Title', $list->get_title() );
		$this->assertSame( 'A description.', $list->get_description() );
		$this->assertSame( 'local', $list->get_type() );
		$this->assertTrue( $list->is_local() );
		$this->assertFalse( $list->is_active() );
		$this->assertSame( $count + 1, count( Subscription_Lists::get_all() ) );
	}

	/**
	 * Test create_local_list trims and rejects empty titles.
	 */
	public function test_create_local_list_rejects_empty_title() {
		$count = count( Subscription_Lists::get_all() );

		foreach ( [ '', '   ', "\t\n" ] as $bad_title ) {
			$result = Subscription_Lists::create_local_list( $bad_title );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'newspack_newsletters_local_list_invalid_title', $result->get_error_code() );
		}

		$this->assertSame( $count, count( Subscription_Lists::get_all() ) );
	}

	/**
	 * Test create_local_list trims surrounding whitespace from the title.
	 */
	public function test_create_local_list_trims_title() {
		$list = Subscription_Lists::create_local_list( '  Padded Title  ' );
		$this->assertInstanceOf( Subscription_List::class, $list );
		$this->assertSame( 'Padded Title', $list->get_title() );
	}

	/**
	 * Test update_local_list happy path (no audience change).
	 */
	public function test_update_local_list() {
		$list = Subscription_Lists::create_local_list( 'Original Title', 'Original description.' );
		$this->assertInstanceOf( Subscription_List::class, $list );

		$updated = Subscription_Lists::update_local_list( $list->get_id(), 'New Title', 'New description.' );
		$this->assertInstanceOf( Subscription_List::class, $updated );
		$this->assertSame( 'New Title', $updated->get_title() );
		$this->assertSame( 'New description.', $updated->get_description() );
	}

	/**
	 * Test update_local_list rejects unknown post id.
	 */
	public function test_update_local_list_rejects_unknown_id() {
		$result = Subscription_Lists::update_local_list( 999999, 'Whatever' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'newspack_newsletters_local_list_not_found', $result->get_error_code() );
	}

	/**
	 * Test update_local_list rejects non-local lists.
	 */
	public function test_update_local_list_rejects_non_local() {
		$remote = Subscription_Lists::create_remote_list( 'remote-x', 'Remote List' );
		$result = Subscription_Lists::update_local_list( $remote->get_id(), 'Renamed' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'newspack_newsletters_local_list_not_local', $result->get_error_code() );
	}

	/**
	 * Test update_local_list rejects empty title.
	 */
	public function test_update_local_list_rejects_empty_title() {
		$list   = Subscription_Lists::create_local_list( 'Title' );
		$result = Subscription_Lists::update_local_list( $list->get_id(), '   ' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'newspack_newsletters_local_list_invalid_title', $result->get_error_code() );
	}

	/**
	 * Test update_local_list persists clearing the description.
	 */
	public function test_update_local_list_clears_description() {
		$list = Subscription_Lists::create_local_list( 'Title', 'Original description.' );
		$this->assertSame( 'Original description.', $list->get_description() );

		$updated = Subscription_Lists::update_local_list( $list->get_id(), 'Title', '' );
		$this->assertInstanceOf( Subscription_List::class, $updated );

		$reloaded = new Subscription_List( $updated->get_id() );
		$this->assertSame( '', $reloaded->get_description() );
	}

	/**
	 * Test delete_local_list happy path.
	 */
	public function test_delete_local_list() {
		$list  = Subscription_Lists::create_local_list( 'To Be Deleted' );
		$id    = $list->get_id();
		$count = count( Subscription_Lists::get_all() );

		$result = Subscription_Lists::delete_local_list( $id );
		$this->assertTrue( $result );
		$this->assertSame( $count - 1, count( Subscription_Lists::get_all() ) );
		$this->assertNull( get_post( $id ) );
	}

	/**
	 * Test delete_local_list rejects unknown post id.
	 */
	public function test_delete_local_list_rejects_unknown_id() {
		$result = Subscription_Lists::delete_local_list( 999999 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'newspack_newsletters_local_list_not_found', $result->get_error_code() );
	}

	/**
	 * Test delete_local_list refuses to delete non-local lists.
	 */
	public function test_delete_local_list_rejects_non_local() {
		$remote = Subscription_Lists::create_remote_list( 'remote-delete-x', 'Remote List' );
		$result = Subscription_Lists::delete_local_list( $remote->get_id() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'newspack_newsletters_local_list_not_local', $result->get_error_code() );
		$this->assertNotNull( get_post( $remote->get_id() ) );
	}

	/**
	 * Test create_remote_list
	 */
	public function test_create_remote_list() {
		$current_provider = Newspack_Newsletters::get_service_provider();
		$remote_id        = 'xyz-123';
		$title            = 'Test Remote List';
		$list             = Subscription_Lists::create_remote_list( $remote_id, $title );
		$this->assertSame( $remote_id, $list->get_remote_id() );
		$this->assertSame( $title, $list->get_title() );
		$this->assertSame( 'remote', $list->get_type() );
		$this->assertSame( $current_provider->service, $list->get_provider() );

		$check = Subscription_List::from_public_id( $remote_id );
		$this->assertSame( $check->get_id(), $list->get_id() );
	}

	/**
	 * Test get_or_create_remote_list
	 */
	public function test_get_or_create_remote_list() {

		$count = count( Subscription_Lists::get_all() );

		// existing.
		$existing = [
			'id'    => 'xyz-' . self::$posts['remote_mailchimp'],
			'title' => 'Test List 5',
		];
		$list     = Subscription_Lists::get_or_create_remote_list( $existing );
		$this->assertSame( self::$posts['remote_mailchimp'], $list->get_id() );
		$this->assertSame( 'Test List 5', $list->get_title() );

		$count_after_existing = count( Subscription_Lists::get_all() );
		$this->assertSame( $count, $count_after_existing );


		// new.
		Newspack_Newsletters::set_service_provider( 'active_campaign' );
		$new  = [
			'id'    => 'xyz-abcde',
			'title' => 'New random list',
		];
		$list = Subscription_Lists::get_or_create_remote_list( $new );
		$this->assertSame( 'New random list', $list->get_title() );
		$this->assertSame( 'xyz-abcde', $list->get_remote_id() );
		$this->assertSame( 'active_campaign', $list->get_provider() );

		$count_after_new = count( Subscription_Lists::get_all() );
		$this->assertSame( $count + 1, $count_after_new );
	}

	/**
	 * Test update title on fetch from ESP
	 */
	public function test_update_title_when_fetching_from_esp() {

		$existing = [
			'id'    => 'xyz-' . self::$posts['remote_mailchimp'],
			'title' => 'Remote mailchimp new title',
		];
		$list     = Subscription_Lists::get_or_create_remote_list( $existing );

		$this->assertSame( self::$posts['remote_mailchimp'], $list->get_id() );
		$this->assertSame( 'Remote mailchimp new title', $list->get_title() );
		$this->assertSame( 'Remote mailchimp new title', $list->get_remote_name() );

		// Now we edit the local title.
		$list->update( [ 'title' => 'Customized title' ] );

		$existing = [
			'id'    => 'xyz-' . self::$posts['remote_mailchimp'],
			'title' => 'Remote mailchimp super new title',
		];

		$list = Subscription_Lists::get_or_create_remote_list( $existing );

		$this->assertSame( self::$posts['remote_mailchimp'], $list->get_id() );
		$this->assertSame( 'Customized title', $list->get_title(), 'The local name should not be updated if it was customized' );
		$this->assertSame( 'Remote mailchimp super new title', $list->get_remote_name(), 'The remote name should always be updated' );
	}

	/**
	 * Test update_lists method
	 *
	 * @return void
	 */
	public function test_update() {

		Newspack_Newsletters::set_service_provider( 'mailchimp' );

		$count                  = count( Subscription_Lists::get_all() );
		$only_mailchimp         = new Subscription_List( self::$posts['only_mailchimp'] );
		$remote_active_campaign = new Subscription_List( self::$posts['remote_active_campaign'] );

		$new_lists = [
			[
				'id'    => $only_mailchimp->get_public_id(),
				'title' => 'New title',
			],
			[
				'id'     => 'xyz-' . self::$posts['remote_mailchimp'],
				'title'  => 'Remote mailchimp new title',
				'active' => false,
			],
			[
				'id'     => $remote_active_campaign->get_public_id(),
				'title'  => 'New title for AC',
				'active' => true,
			],
			[
				'id'     => 'xyz-abcde',
				'title'  => 'New random list',
				'active' => true,
			],
		];

		Subscription_Lists::update_lists( $new_lists );

		$new_count = count( Subscription_Lists::get_all() );

		$this->assertSame( $count + 1, $new_count );

		$list = new Subscription_List( self::$posts['without_settings'] );
		$this->assertSame( false, $list->is_active() );

		$list = new Subscription_List( self::$posts['two_settings'] );
		$this->assertSame( false, $list->is_active() );

		$list = new Subscription_List( self::$posts['mc_invalid'] );
		$this->assertSame( true, $list->is_active(), 'AC-configured local with mailchimp error stays active when saving the mailchimp UI' );

		$list = new Subscription_List( self::$posts['only_mailchimp'] );
		$this->assertSame( false, $list->is_active(), 'If active is not informed it should be set to false' );
		$this->assertSame( 'New title', $list->get_title() );
		$this->assertSame( self::$posts['only_mailchimp'], $list->get_id() );

		$list = Subscription_List::from_public_id( 'xyz-' . self::$posts['remote_mailchimp'] );
		$this->assertSame( false, $list->is_active() );
		$this->assertSame( 'Remote mailchimp new title', $list->get_title() );
		$this->assertSame( self::$posts['remote_mailchimp'], $list->get_id() );

		$list = Subscription_List::from_public_id( 'xyz-' . self::$posts['remote_mailchimp_inactive'] );
		$this->assertSame( false, $list->is_active() );
		$this->assertSame( self::$posts['remote_mailchimp_inactive'], $list->get_id() );

		$list = Subscription_List::from_public_id( 'xyz-abcde' );
		$this->assertSame( true, $list->is_active() );
		$this->assertSame( 'New random list', $list->get_title() );

		$list = Subscription_List::from_public_id( self::$conflicting_post_id );
		$this->assertSame( true, $list->is_active() );
		$this->assertSame( 'New title for AC', $list->get_title() );
		$this->assertSame( self::$posts['remote_active_campaign'], $list->get_id() );
	}

	/**
	 * Test the migration method
	 */
	public function test_migration() {
		$active_campaign = [
			'123' => [
				'active'      => true,
				'title'       => 'AC1',
				'description' => 'ac 1',
			],
			'456' => [
				'active'      => false,
				'title'       => 'AC2',
				'description' => 'ac 2',
			],
		];
		$mailchimp       = [
			'950aaf1a98'                  => [
				'active'      => true,
				'title'       => 'MC1',
				'description' => 'mc 1',
			],
			'group-6a822fca1c-950aaf1a98' => [
				'active'      => false,
				'title'       => 'MC2',
				'description' => 'mc 2',
			],
			'120aaf1a12'                  => [
				'active'      => true,
				'title'       => 'MC3',
				'description' => 'mc 3',
			],
			'tag-14370955-950aaf1a98'     => [
				'active'      => false,
				'title'       => 'MC4',
				'description' => 'mc 4',
			],
		];

		update_option( '_newspack_newsletters_mailchimp_lists', $mailchimp );
		update_option( '_newspack_newsletters_active_campaign_lists', $active_campaign );

		delete_option( '_newspack_newsletters_lists_migrated' );

		Subscription_Lists::migrate_lists();

		$list = Subscription_List::from_public_id( '123' );
		$this->assertSame( 'AC1', $list->get_title() );
		$this->assertSame( 'ac 1', $list->get_description() );
		$this->assertTrue( $list->is_active() );
		$this->assertSame( 'active_campaign', $list->get_provider() );

		$list = Subscription_List::from_public_id( '456' );
		$this->assertSame( 'AC2', $list->get_title() );
		$this->assertSame( 'ac 2', $list->get_description() );
		$this->assertFalse( $list->is_active() );
		$this->assertSame( 'active_campaign', $list->get_provider() );

		$list = Subscription_List::from_public_id( '950aaf1a98' );
		$this->assertSame( 'MC1', $list->get_title() );
		$this->assertSame( 'mc 1', $list->get_description() );
		$this->assertTrue( $list->is_active() );
		$this->assertSame( 'mailchimp', $list->get_provider() );

		$list = Subscription_List::from_public_id( 'group-6a822fca1c-950aaf1a98' );
		$this->assertSame( 'MC2', $list->get_title() );
		$this->assertSame( 'mc 2', $list->get_description() );
		$this->assertFalse( $list->is_active() );
		$this->assertSame( 'mailchimp', $list->get_provider() );

		$list = Subscription_List::from_public_id( '120aaf1a12' );
		$this->assertSame( 'MC3', $list->get_title() );
		$this->assertSame( 'mc 3', $list->get_description() );
		$this->assertTrue( $list->is_active() );
		$this->assertSame( 'mailchimp', $list->get_provider() );

		$list = Subscription_List::from_public_id( 'tag-14370955-950aaf1a98' );
		$this->assertSame( 'MC4', $list->get_title() );
		$this->assertSame( 'mc 4', $list->get_description() );
		$this->assertFalse( $list->is_active() );
		$this->assertSame( 'mailchimp', $list->get_provider() );
	}

	/**
	 * Test garbage_collector method
	 */
	public function test_garbage_collector() {
		Subscription_Lists::garbage_collector( [ self::$posts['remote_mailchimp'] ] );
		$all_lists = Subscription_Lists::get_all();
		$ids       = array_map(
			function ( $list ) {
				return $list->get_id();
			},
			$all_lists
		);
		$this->assertContains( self::$posts['remote_mailchimp'], $ids );
		$this->assertNotContains( self::$posts['remote_mailchimp_inactive'], $ids );
		$this->assertContains( self::$posts['remote_active_campaign'], $ids );
	}

	/**
	 * Test api_patch_list updates ESP rows' title + description.
	 */
	public function test_api_patch_list_updates_remote_title_and_description() {
		$db_id   = self::$posts['remote_mailchimp'];
		$request = new WP_REST_Request( 'PATCH', '/newspack-newsletters/v1/lists/' . $db_id );
		$request->set_param( 'id', $db_id );
		$request->set_param( 'title', 'Custom Title' );
		$request->set_param( 'description', 'Custom description' );

		$response = Newspack_Newsletters_Subscription::api_patch_list( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertSame( 'Custom Title', $data['title'] );
		$this->assertSame( 'Custom description', $data['description'] );
		$this->assertSame( $db_id, $data['db_id'] );

		$reloaded = new Subscription_List( $db_id );
		$this->assertSame( 'Custom Title', $reloaded->get_title() );
		$this->assertSame( 'Custom description', $reloaded->get_description() );
	}

	/**
	 * Test api_patch_list flips the active flag for ESP rows.
	 */
	public function test_api_patch_list_toggles_remote_active() {
		$db_id = self::$posts['remote_mailchimp_inactive'];
		$this->assertFalse( ( new Subscription_List( $db_id ) )->is_active() );

		$request = new WP_REST_Request( 'PATCH', '/newspack-newsletters/v1/lists/' . $db_id );
		$request->set_param( 'id', $db_id );
		$request->set_param( 'active', true );

		$response = Newspack_Newsletters_Subscription::api_patch_list( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['active'] );
		$this->assertTrue( ( new Subscription_List( $db_id ) )->is_active() );
	}

	/**
	 * Test api_patch_list flips the active flag for local rows too — same route serves both kinds for that field.
	 */
	public function test_api_patch_list_toggles_local_active() {
		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		$list  = Subscription_Lists::create_local_list( 'Toggle Me' );
		$db_id = $list->get_id();
		// Wire the list to the current provider so the guard doesn't reject the activation.
		$list->update_current_provider_settings( 'audience-1', 'tag-1', 'tag-name' );
		$this->assertFalse( ( new Subscription_List( $db_id ) )->is_active() );

		$request = new WP_REST_Request( 'PATCH', '/newspack-newsletters/v1/lists/' . $db_id );
		$request->set_param( 'id', $db_id );
		$request->set_param( 'active', true );

		$response = Newspack_Newsletters_Subscription::api_patch_list( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['active'] );
		$this->assertTrue( ( new Subscription_List( $db_id ) )->is_active() );
	}

	/**
	 * Test api_patch_list mirrors the bulk path's guard: locals without
	 * current-provider wiring cannot be activated through the per-row PATCH
	 * either. The active flag is silently coerced to false (not an error).
	 */
	public function test_api_patch_list_forces_unconfigured_local_inactive() {
		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		$unconfigured = new Subscription_List( self::$posts['without_settings'] );
		$this->assertFalse( $unconfigured->is_configured_for_current_provider() );
		$this->assertEmpty( $unconfigured->get_configured_providers() );

		$request = new WP_REST_Request( 'PATCH', '/newspack-newsletters/v1/lists/' . $unconfigured->get_id() );
		$request->set_param( 'id', $unconfigured->get_id() );
		$request->set_param( 'active', true );

		$response = Newspack_Newsletters_Subscription::api_patch_list( $request );
		$data     = $response->get_data();

		$this->assertFalse( $data['active'], 'PATCH coerces active=true to false for unconfigured locals.' );
		$this->assertFalse( ( new Subscription_List( $unconfigured->get_id() ) )->is_active() );
	}

	/**
	 * Test api_patch_list rejects title/description edits on local rows — those go through /lists/local/{id}.
	 */
	public function test_api_patch_list_rejects_local_title_edit() {
		$list  = Subscription_Lists::create_local_list( 'Local Original', 'Original desc' );
		$db_id = $list->get_id();

		$request = new WP_REST_Request( 'PATCH', '/newspack-newsletters/v1/lists/' . $db_id );
		$request->set_param( 'id', $db_id );
		$request->set_param( 'title', 'Renamed via wrong endpoint' );

		$response = Newspack_Newsletters_Subscription::api_patch_list( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'newspack_newsletters_local_list_use_local_endpoint', $response->get_error_code() );

		// Confirm the title was NOT changed.
		$this->assertSame( 'Local Original', ( new Subscription_List( $db_id ) )->get_title() );
	}

	/**
	 * Test api_patch_list rejects empty title (whitespace-only) on ESP rows.
	 */
	public function test_api_patch_list_rejects_empty_remote_title() {
		$db_id = self::$posts['remote_mailchimp'];

		$request = new WP_REST_Request( 'PATCH', '/newspack-newsletters/v1/lists/' . $db_id );
		$request->set_param( 'id', $db_id );
		$request->set_param( 'title', '   ' );

		$response = Newspack_Newsletters_Subscription::api_patch_list( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'newspack_newsletters_list_invalid_title', $response->get_error_code() );
	}

	/**
	 * Test api_patch_list returns 404 when the post is missing or wrong CPT.
	 */
	public function test_api_patch_list_rejects_unknown_id() {
		$request = new WP_REST_Request( 'PATCH', '/newspack-newsletters/v1/lists/999999' );
		$request->set_param( 'id', 999999 );
		$request->set_param( 'active', true );

		$response = Newspack_Newsletters_Subscription::api_patch_list( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'newspack_newsletters_list_not_found', $response->get_error_code() );
	}
}
