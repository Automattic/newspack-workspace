<?php
/**
 * Class TestReaderAccessSync
 *
 * @package Newspack_Network
 */

use Newspack_Network\Content_Gate\Access;
use Newspack_Network\Content_Gate\Reader_Access_Envelope;
use Newspack_Network\Content_Gate\Reader_Access_Sync;
use Newspack_Network\Crypto;
use Newspack_Network\Incoming_Events\Subscription_Changed;
use Newspack_Network\Site_Role;
use Newspack_Network\Woocommerce\Product_Admin;

/**
 * How a node pulls, stores and refreshes what a reader holds on the other network sites.
 */
class TestReaderAccessSync extends WP_UnitTestCase {

	const HUB_URL = 'https://hub.example.test';

	const OTHER_SITE = 'https://other.example.test';

	/**
	 * Key shared with the hub.
	 *
	 * @var string
	 */
	private $secret;

	/**
	 * Reader under test.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Local product tagged 'premium'.
	 *
	 * @var int
	 */
	private $premium_product;

	/**
	 * Number of requests made to the hub.
	 *
	 * @var int
	 */
	private $hub_calls = 0;

	/**
	 * Set up a node connected to a hub, and a reader.
	 */
	public function set_up() {
		parent::set_up();
		$this->secret = Crypto::generate_secret_key();
		update_option( Site_Role::OPTION_NAME, Site_Role::NODE_ROLE );
		update_option( 'newspack_node_hub_url', self::HUB_URL );
		update_option( 'newspack_node_secret_key', $this->secret );
		$this->user_id         = self::factory()->user->create( [ 'user_email' => 'reader@example.test' ] );
		$this->premium_product = self::factory()->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $this->premium_product, Product_Admin::NETWORK_ID_META_KEY, 'premium' );
		$this->hub_calls = 0;
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		delete_option( Site_Role::OPTION_NAME );
		delete_option( 'newspack_node_hub_url' );
		delete_option( 'newspack_node_secret_key' );
		wp_set_current_user( 0 );
		wp_clear_scheduled_hook( Reader_Access_Sync::REFRESH_HOOK, [ $this->user_id ] );
		parent::tear_down();
	}

	/**
	 * Answer the node's requests to the hub.
	 *
	 * @param array       $sites      Site URL => what the reader holds there.
	 * @param string|null $request_id Request ID to answer with; null echoes the one sent.
	 * @param int         $status     HTTP status.
	 */
	private function mock_hub( $sites, $request_id = null, $status = 200 ) {
		add_filter(
			'pre_http_request',
			function ( $response, $args, $url ) use ( $sites, $request_id, $status ) {
				if ( 0 !== strpos( $url, self::HUB_URL ) ) {
					return $response;
				}
				++$this->hub_calls;
				$envelope = Reader_Access_Envelope::seal(
					[
						'email'      => $args['body']['email'],
						'request_id' => $request_id ?? $args['body']['request_id'],
						'sites'      => $sites,
					],
					$this->secret
				);
				return [
					'response' => [ 'code' => $status ],
					'body'     => wp_json_encode( $envelope ),
				];
			},
			10,
			3
		);
	}

	/**
	 * What the other site holds: an active premium subscription.
	 *
	 * @return array
	 */
	private function other_site_with_subscription() {
		return [
			self::OTHER_SITE => [
				'subscriptions' => [
					42 => [
						'id'       => 42,
						'status'   => 'active',
						'products' => [
							7 => [
								'id'         => 7,
								'name'       => 'Premium',
								'slug'       => 'premium',
								'network_id' => 'premium',
							],
						],
					],
				],
				'groups'        => [],
				'orders'        => [],
			],
		];
	}

	/**
	 * Store a snapshot directly.
	 *
	 * @param int   $synced_at When it was last pulled.
	 * @param array $sites     Per-site groups and orders.
	 */
	private function set_snapshot( $synced_at, $sites = [] ) {
		update_user_meta(
			$this->user_id,
			Reader_Access_Sync::META_KEY,
			[
				'synced_at'    => $synced_at,
				'attempted_at' => $synced_at,
				'sites'        => $sites,
			]
		);
	}

	/**
	 * Whether the premium gate lets the reader in.
	 *
	 * @return bool
	 */
	private function passes_premium_gate() {
		return Access::check_network_subscriptions( false, $this->user_id, [ $this->premium_product ], false );
	}

	/**
	 * A pull stores owned subscriptions where subscription events put them, and group
	 * seats and orders in the snapshot.
	 */
	public function test_sync_stores_pulled_data() {
		$sites                                = $this->other_site_with_subscription();
		$sites[ self::OTHER_SITE ]['groups'] = [
			[
				'id'          => 900,
				'status'      => 'active',
				'network_ids' => [ 'premium' ],
			],
		];
		$sites[ self::OTHER_SITE ]['orders'] = [
			[
				'id'           => 700,
				'date_created' => time(),
				'network_ids'  => [ 'annual-pass' ],
			],
		];
		$this->mock_hub( $sites );

		$this->assertTrue( Reader_Access_Sync::sync( $this->user_id ) );

		$subscriptions = get_user_meta( $this->user_id, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, true );
		$this->assertSame( 'premium', $subscriptions[ self::OTHER_SITE ][42]['products'][7]['network_id'] );
		$this->assertSame( [ 900 ], wp_list_pluck( Reader_Access_Sync::get_group_seats( $this->user_id ), 'id' ) );
		$this->assertSame( [ 700 ], wp_list_pluck( Reader_Access_Sync::get_orders( $this->user_id ), 'id' ) );
		$this->assertGreaterThanOrEqual( time() - 5, Reader_Access_Sync::get_snapshot( $this->user_id )['synced_at'] );
	}

	/**
	 * A site the answer names replaces the subscriptions stored for it; a site it
	 * doesn't name keeps the records its subscription events wrote. Group seats and
	 * orders only come from the hub, so they are replaced outright.
	 */
	public function test_sync_replaces_named_sites_and_keeps_others() {
		update_user_meta(
			$this->user_id,
			Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY,
			[
				self::OTHER_SITE             => [
					1 => [
						'id'       => 1,
						'status'   => 'active',
						'products' => [],
					],
				],
				'https://quiet.example.test' => [
					2 => [
						'id'       => 2,
						'status'   => 'active',
						'products' => [],
					],
				],
			]
		);
		$this->set_snapshot(
			0,
			[
				'https://quiet.example.test' => [
					'groups' => [
						[
							'id'          => 901,
							'status'      => 'active',
							'network_ids' => [ 'premium' ],
						],
					],
					'orders' => [],
				],
			]
		);
		$this->mock_hub( $this->other_site_with_subscription() );

		Reader_Access_Sync::sync( $this->user_id );

		$subscriptions = get_user_meta( $this->user_id, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, true );
		$this->assertSame( [ 42 ], array_keys( $subscriptions[ self::OTHER_SITE ] ) );
		$this->assertSame( [ 2 ], array_keys( $subscriptions['https://quiet.example.test'] ) );
		$this->assertSame( [], Reader_Access_Sync::get_group_seats( $this->user_id ) );
	}

	/**
	 * An answer that doesn't name this request (a replay) is ignored, and the attempt
	 * is recorded so the next check backs off.
	 */
	public function test_sync_rejects_answer_for_another_request() {
		$this->mock_hub( $this->other_site_with_subscription(), 'replayed-request' );

		$this->assertInstanceOf( WP_Error::class, Reader_Access_Sync::sync( $this->user_id ) );

		$this->assertEmpty( get_user_meta( $this->user_id, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, true ) );
		$snapshot = Reader_Access_Sync::get_snapshot( $this->user_id );
		$this->assertSame( 0, $snapshot['synced_at'] );
		$this->assertGreaterThanOrEqual( time() - 5, $snapshot['attempted_at'] );
	}

	/**
	 * A failed pull is recorded where the node's settings screen shows it, and a later
	 * successful pull clears it, so a broken connection doesn't fail quietly.
	 */
	public function test_failed_pull_is_recorded_until_a_pull_succeeds() {
		$this->mock_hub( [], null, 404 );
		Reader_Access_Sync::sync( $this->user_id );
		$this->assertStringContainsString( '404', Reader_Access_Sync::get_last_error() );

		remove_all_filters( 'pre_http_request' );
		$this->mock_hub( $this->other_site_with_subscription() );
		Reader_Access_Sync::sync( $this->user_id );
		$this->assertSame( '', Reader_Access_Sync::get_last_error() );
	}

	/**
	 * A logged-in reader with nothing pulled yet (already logged in before this shipped,
	 * or whose account postdates their subscription) is pulled on their first gated check.
	 */
	public function test_first_gated_check_pulls_for_the_current_reader() {
		$this->mock_hub( $this->other_site_with_subscription() );
		wp_set_current_user( $this->user_id );

		$this->assertTrue( $this->passes_premium_gate() );
		$this->assertTrue( $this->passes_premium_gate() );
		$this->assertSame( 1, $this->hub_calls );
	}

	/**
	 * A reader whose subscription already reached this site through subscription
	 * events passes without a pull; those events keep the record current.
	 */
	public function test_no_pull_when_a_synced_subscription_already_matches() {
		$this->mock_hub( [] );
		update_user_meta(
			$this->user_id,
			Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY,
			[ self::OTHER_SITE => $this->other_site_with_subscription()[ self::OTHER_SITE ]['subscriptions'] ]
		);
		wp_set_current_user( $this->user_id );

		$this->assertTrue( $this->passes_premium_gate() );
		$this->assertSame( 0, $this->hub_calls );
	}

	/**
	 * Checks made for someone other than the current reader (reports, admin screens)
	 * use what is stored and never pull.
	 */
	public function test_no_pull_for_other_users() {
		$this->mock_hub( $this->other_site_with_subscription() );

		$this->assertFalse( $this->passes_premium_gate() );
		$this->assertSame( 0, $this->hub_calls );
	}

	/**
	 * A stale snapshot is used as it stands while a refresh runs in the background,
	 * so the page isn't held up.
	 */
	public function test_stale_snapshot_schedules_refresh() {
		$this->mock_hub( $this->other_site_with_subscription() );
		$this->set_snapshot( time() - 13 * HOUR_IN_SECONDS );
		wp_set_current_user( $this->user_id );

		$this->passes_premium_gate();

		$this->assertSame( 0, $this->hub_calls );
		$this->assertNotFalse( wp_next_scheduled( Reader_Access_Sync::REFRESH_HOOK, [ $this->user_id ] ) );
	}

	/**
	 * After a failed background refresh, a stale copy isn't queued again on every page view.
	 */
	public function test_failed_refresh_backs_off() {
		update_user_meta(
			$this->user_id,
			Reader_Access_Sync::META_KEY,
			[
				'synced_at'    => time() - 13 * HOUR_IN_SECONDS,
				'attempted_at' => time(),
				'sites'        => [],
			]
		);
		wp_set_current_user( $this->user_id );

		$this->passes_premium_gate();

		$this->assertFalse( wp_next_scheduled( Reader_Access_Sync::REFRESH_HOOK, [ $this->user_id ] ) );
	}

	/**
	 * After a failed pull, gated checks don't retry on every page view.
	 */
	public function test_failed_pull_backs_off() {
		$this->mock_hub( [], null, 500 );
		wp_set_current_user( $this->user_id );

		$this->passes_premium_gate();
		$this->passes_premium_gate();

		$this->assertSame( 1, $this->hub_calls );
	}

	/**
	 * Logging in refreshes what is stored on the next gated check, even when it is recent.
	 */
	public function test_login_forces_refresh() {
		$this->mock_hub( $this->other_site_with_subscription() );
		$this->set_snapshot( time() );
		wp_set_current_user( $this->user_id );

		do_action( 'wp_login', 'reader', get_userdata( $this->user_id ) );

		$this->assertTrue( $this->passes_premium_gate() );
		$this->assertSame( 1, $this->hub_calls );
	}

	/**
	 * A seat the group's owner removed, or whose subscription ended, stops granting
	 * access once the next pull no longer reports it.
	 */
	public function test_removed_group_seat_denies_access_after_next_sync() {
		$this->set_snapshot(
			time(),
			[
				self::OTHER_SITE => [
					'groups' => [
						[
							'id'          => 900,
							'status'      => 'active',
							'network_ids' => [ 'premium' ],
						],
					],
					'orders' => [],
				],
			]
		);
		$this->assertTrue( $this->passes_premium_gate() );

		$this->mock_hub(
			[
				self::OTHER_SITE => [
					'subscriptions' => [],
					'groups'        => [],
					'orders'        => [],
				],
			]
		);
		do_action( Reader_Access_Sync::REFRESH_HOOK, $this->user_id );

		$this->assertFalse( $this->passes_premium_gate() );
	}

	/**
	 * A site outside a network never tries to pull.
	 */
	public function test_no_pull_outside_a_network() {
		delete_option( Site_Role::OPTION_NAME );
		wp_set_current_user( $this->user_id );

		$this->passes_premium_gate();

		$this->assertSame( [], Reader_Access_Sync::get_snapshot( $this->user_id ) );
	}
}
