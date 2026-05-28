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

		// 3 local lists should be marked as deactivated.
		// 1 remote list should be deactivated and one should be added.
		$this->assertSame( $count + 1, $new_count );

		$list = new Subscription_List( self::$posts['without_settings'] );
		$this->assertSame( false, $list->is_active() );

		$list = new Subscription_List( self::$posts['two_settings'] );
		$this->assertSame( false, $list->is_active() );

		$list = new Subscription_List( self::$posts['mc_invalid'] );
		$this->assertSame( false, $list->is_active() );

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
}
