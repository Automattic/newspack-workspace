<?php
/**
 * Tests for the membership and subscription data event handlers against
 * legacy non-JSON `active_memberships` values (NPPM-3205).
 *
 * The reader-data sync-memberships CLI used to store plan IDs as a bare
 * scalar (`123`) or comma list (`123,456`) instead of the JSON array the
 * handlers write and expect, so a membership status change for an affected
 * reader crashed the handler before it could repair the value.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Data;
use Newspack\Sync_Reader_Data_CLI;

require_once dirname( __DIR__ ) . '/mocks/wp-cli-mocks.php';

/**
 * Tests for Reader_Data::update_active_memberships(),
 * Reader_Data::update_active_subscriptions() and the sync-memberships CLI.
 *
 * @group reader-data
 */
class Newspack_Test_Reader_Data_Membership_Events extends WP_UnitTestCase {

	/**
	 * Clear the WP_CLI mock's static output buffers, so CLI transcripts don't
	 * leak between tests.
	 */
	public function set_up() {
		parent::set_up();
		WP_CLI::reset();
	}

	/**
	 * Store a raw item value directly, bypassing update_item()'s JSON
	 * encoding, the way legacy writers did.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $key       Item key.
	 * @param string $raw_value Raw meta value.
	 */
	private static function seed_raw_item( $user_id, $key, $raw_value ) {
		update_user_meta( $user_id, 'newspack_reader_data_keys', [ $key ] );
		update_user_meta( $user_id, Reader_Data::get_meta_key_name( $key ), $raw_value );
	}

	/**
	 * The healthy path: an inactive event removes the plan from a JSON list.
	 */
	public function test_inactive_event_removes_plan_from_json_list() {
		$user_id = self::factory()->user->create();
		Reader_Data::update_item( $user_id, 'active_memberships', [ 123, 456 ] );
		Reader_Data::update_active_memberships(
			time(),
			[
				'user_id'      => $user_id,
				'plan_id'      => 123,
				'status_after' => 'expired',
			]
		);
		self::assertSame( '[456]', Reader_Data::get_data( $user_id, 'active_memberships' ) );
	}

	/**
	 * A bare scalar stored value (single-plan legacy shape) is recovered as a
	 * one-item list, so the inactive event can remove the plan.
	 */
	public function test_inactive_event_recovers_bare_scalar_value() {
		$user_id = self::factory()->user->create();
		self::seed_raw_item( $user_id, 'active_memberships', '123' );
		Reader_Data::update_active_memberships(
			time(),
			[
				'user_id'      => $user_id,
				'plan_id'      => 123,
				'status_after' => 'expired',
			]
		);
		self::assertSame( '[]', Reader_Data::get_data( $user_id, 'active_memberships' ) );
	}

	/**
	 * A bare scalar stored value survives an active event for another plan.
	 */
	public function test_active_event_recovers_bare_scalar_value() {
		$user_id = self::factory()->user->create();
		self::seed_raw_item( $user_id, 'active_memberships', '456' );
		Reader_Data::update_active_memberships(
			time(),
			[
				'user_id'      => $user_id,
				'plan_id'      => 123,
				'status_after' => 'active',
			]
		);
		self::assertSame( '[456,123]', Reader_Data::get_data( $user_id, 'active_memberships' ) );
	}

	/**
	 * A comma-separated stored value (multi-plan legacy shape) is recovered,
	 * so the inactive event removes only the affected plan.
	 */
	public function test_inactive_event_recovers_comma_separated_value() {
		$user_id = self::factory()->user->create();
		self::seed_raw_item( $user_id, 'active_memberships', '123,456' );
		Reader_Data::update_active_memberships(
			time(),
			[
				'user_id'      => $user_id,
				'plan_id'      => 123,
				'status_after' => 'expired',
			]
		);
		self::assertSame( '[456]', Reader_Data::get_data( $user_id, 'active_memberships' ) );
	}

	/**
	 * An unrecoverable stored value starts the list fresh instead of crashing.
	 */
	public function test_active_event_starts_fresh_on_unrecoverable_value() {
		$user_id = self::factory()->user->create();
		self::seed_raw_item( $user_id, 'active_memberships', 'not-json' );
		Reader_Data::update_active_memberships(
			time(),
			[
				'user_id'      => $user_id,
				'plan_id'      => 123,
				'status_after' => 'active',
			]
		);
		self::assertSame( '[123]', Reader_Data::get_data( $user_id, 'active_memberships' ) );
	}

	/**
	 * A stored `0` is a value, not an absence: the decode must not drop it the
	 * way an empty() check would.
	 */
	public function test_active_event_preserves_stored_zero() {
		$user_id = self::factory()->user->create();
		self::seed_raw_item( $user_id, 'active_memberships', '0' );
		Reader_Data::update_active_memberships(
			time(),
			[
				'user_id'      => $user_id,
				'plan_id'      => 123,
				'status_after' => 'active',
			]
		);
		self::assertSame( '[0,123]', Reader_Data::get_data( $user_id, 'active_memberships' ) );
	}

	/**
	 * The subscriptions handler shares the same decode, so it recovers a bare
	 * scalar stored value too.
	 */
	public function test_subscription_event_recovers_bare_scalar_value() {
		$user_id = self::factory()->user->create();
		self::seed_raw_item( $user_id, 'active_subscriptions', '789' );
		Reader_Data::update_active_subscriptions(
			time(),
			[
				'user_id'         => $user_id,
				'subscription_id' => 55,
				'product_ids'     => [ 789 ],
				'status_after'    => 'cancelled',
			]
		);
		self::assertSame( '[]', Reader_Data::get_data( $user_id, 'active_subscriptions' ) );
	}

	/**
	 * The sync-memberships CLI writes the JSON shape the handlers expect, not
	 * a comma list.
	 */
	public function test_sync_cli_writes_json_list() {
		$user_id = self::factory()->user->create();
		self::factory()->post->create(
			[
				'post_type'   => 'wc_user_membership',
				'post_status' => 'wcm-active',
				'post_author' => $user_id,
				'post_parent' => 123,
			]
		);
		self::seed_raw_item( $user_id, 'active_memberships', '[456]' );
		Sync_Reader_Data_CLI::fix_reader_data_and_membership_discrepancy( [], [ 'live' => true ] );
		self::assertSame( '[123]', get_user_meta( $user_id, Reader_Data::get_meta_key_name( 'active_memberships' ), true ) );
	}

	/**
	 * A rejected write surfaces as a warning instead of a silent skip, so a
	 * live run's transcript accounts for every flagged reader.
	 */
	public function test_sync_cli_warns_when_update_rejected() {
		$user_id = self::factory()->user->create();
		self::factory()->post->create(
			[
				'post_type'   => 'wc_user_membership',
				'post_status' => 'wcm-active',
				'post_author' => $user_id,
				'post_parent' => 123,
			]
		);
		// Force update_item() to reject the write for this reader's new key.
		add_filter( 'newspack_reader_data_max_items', '__return_zero' );
		Sync_Reader_Data_CLI::fix_reader_data_and_membership_discrepancy( [], [ 'live' => true ] );
		self::assertNotEmpty( WP_CLI::$warnings );
		self::assertSame( '', get_user_meta( $user_id, Reader_Data::get_meta_key_name( 'active_memberships' ), true ) );
	}
}
