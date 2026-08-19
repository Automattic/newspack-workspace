<?php
/**
 * Class Newsletters Test Mailchimp Cached Data
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Subscription_List;
use Newspack\Newsletters\Subscription_Lists;

/**
 * Tests the Mailchimp Cached Data Class.
 */
class Newsletters_Mailchimp_Cached_Data_Test extends WP_UnitTestCase {

	/**
	 * Audience configured directly as a subscription list.
	 */
	const AUDIENCE_DIRECT = 'audDirect';

	/**
	 * Audience that owns a group configured as a subscription list.
	 */
	const AUDIENCE_SUBLIST = 'audSublist';

	/**
	 * Audience only referenced by a newsletter's send_list_id.
	 */
	const AUDIENCE_SEND = 'audSend';

	/**
	 * Audience the site does not use in any way.
	 */
	const AUDIENCE_UNUSED = 'audUnused';

	/**
	 * Audience configured as a subscription list for a different provider.
	 */
	const AUDIENCE_OTHER_PROVIDER = 'audOtherProvider';

	/**
	 * Audience IDs dispatched for refresh during the test.
	 *
	 * @var string[]
	 */
	private $dispatched = [];

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		// Reset the API key.
		delete_option( 'newspack_mailchimp_api_key' );
		$this->dispatched = [];
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'mailchimp_mock_get', [ $this, 'mock_lists_response' ] );
		remove_filter( 'pre_http_request', [ $this, 'capture_dispatch' ] );
		delete_option( 'newspack_newsletters_service_provider' );
		delete_option( 'newspack_mailchimp_api_key' );
		parent::tear_down();
	}

	/**
	 * Test the API setup.
	 */
	public function test_mailchimp_cached_data_api_setup() {
		// Makes sure cached data fetch_methods throw an exception in case of error.
		$this->expectException( Exception::class );
		$segments = Newspack_Newsletters_Mailchimp_Cached_Data::fetch_segments( 'list1' );
	}

	/**
	 * A cache miss dispatches a background refresh that writes the option moments
	 * later, from another request. WordPress remembers a missing option in its
	 * `notoptions` cache and then answers later reads from that memory without
	 * querying the database, so the write stays invisible and the data never
	 * appears. Reading the cache must not leave that memory behind.
	 */
	public function test_cold_read_does_not_remember_the_option_as_missing() {
		$list_id = 'audColdRead';
		$key     = 'newspack_nl_mailchimp_cache_' . $list_id;

		delete_option( $key );
		wp_cache_delete( 'notoptions', 'options' );

		add_filter( 'pre_http_request', [ $this, 'block_http' ], 10, 3 );
		Newspack_Newsletters_Mailchimp_Cached_Data::get_tags( $list_id );
		remove_filter( 'pre_http_request', [ $this, 'block_http' ], 10 );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		$this->assertFalse(
			is_array( $notoptions ) && isset( $notoptions[ $key ] ),
			'A cold read must not leave the option remembered as missing.'
		);
	}

	/**
	 * Once the option has been forgotten, a value written by another process is
	 * visible to the next read instead of being masked by the cached absence.
	 */
	public function test_value_written_after_a_cold_read_is_visible() {
		$list_id = 'audWrittenAfter';
		$key     = 'newspack_nl_mailchimp_cache_' . $list_id;

		delete_option( $key );
		wp_cache_delete( 'notoptions', 'options' );

		add_filter( 'pre_http_request', [ $this, 'block_http' ], 10, 3 );
		Newspack_Newsletters_Mailchimp_Cached_Data::get_tags( $list_id );
		remove_filter( 'pre_http_request', [ $this, 'block_http' ], 10 );

		// Stand in for the background refresh writing straight to the database,
		// as it does from its own request.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$wpdb->options,
			[
				'option_name'  => $key,
				'option_value' => maybe_serialize(
					[
						'tags' => [
							[
								'id'   => 1,
								'name' => 'Warm',
							],
						],
					] 
				),
				'autoload'     => 'no',
			]
		);

		$this->assertIsArray( get_option( $key ), 'The written value must be visible to the next read.' );
	}

	/**
	 * Swallow the async refresh request the cache dispatches on a miss.
	 *
	 * @param mixed  $preempt Whether to preempt the request.
	 * @param array  $args    The request arguments.
	 * @param string $url     The request URL.
	 * @return array
	 */
	public function block_http( $preempt, $args, $url ) {
		return [
			'headers'  => [],
			'body'     => '',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
	}

	/**
	 * The cron must only warm the audiences the site actually uses, not every
	 * audience in the Mailchimp account.
	 */
	public function test_handle_cron_only_dispatches_audiences_in_use() {
		$this->set_up_mailchimp();

		// An audience configured directly as a subscription list.
		$this->create_remote_list( self::AUDIENCE_DIRECT, 'mailchimp' );

		// A group configured as a subscription list: its parent audience is in use.
		$this->create_remote_list(
			Subscription_List::mailchimp_generate_public_id( 'grp1', self::AUDIENCE_SUBLIST ),
			'mailchimp'
		);

		// A list belonging to another provider must not put its audience in scope.
		$this->create_remote_list( self::AUDIENCE_OTHER_PROVIDER, 'active_campaign' );

		// A newsletter that sends to an audience which is not a subscription list.
		$this->create_newsletter( self::AUDIENCE_SEND );

		Newspack_Newsletters_Mailchimp_Cached_Data::handle_cron();

		$this->assertSame(
			[ self::AUDIENCE_DIRECT, self::AUDIENCE_SEND, self::AUDIENCE_SUBLIST ],
			$this->get_dispatched()
		);
	}

	/**
	 * The set of audiences to refresh can be overridden with a filter, so a site
	 * with a very large account can pin an explicit list.
	 */
	public function test_handle_cron_audiences_are_filterable() {
		$this->set_up_mailchimp();
		$this->create_remote_list( self::AUDIENCE_DIRECT, 'mailchimp' );

		$filter = function () {
			return [ self::AUDIENCE_UNUSED ];
		};
		add_filter( 'newspack_newsletters_mailchimp_audiences_to_refresh', $filter );

		Newspack_Newsletters_Mailchimp_Cached_Data::handle_cron();

		remove_filter( 'newspack_newsletters_mailchimp_audiences_to_refresh', $filter );

		$this->assertSame( [ self::AUDIENCE_UNUSED ], $this->get_dispatched() );
	}

	/**
	 * An audience returned by the filter that no longer exists in the account is
	 * not dispatched.
	 */
	public function test_handle_cron_skips_audiences_missing_from_the_account() {
		$this->set_up_mailchimp();

		$filter = function () {
			return [ self::AUDIENCE_DIRECT, 'audDeletedInMailchimp' ];
		};
		add_filter( 'newspack_newsletters_mailchimp_audiences_to_refresh', $filter );

		Newspack_Newsletters_Mailchimp_Cached_Data::handle_cron();

		remove_filter( 'newspack_newsletters_mailchimp_audiences_to_refresh', $filter );

		$this->assertSame( [ self::AUDIENCE_DIRECT ], $this->get_dispatched() );
	}

	/**
	 * With nothing configured and nothing sent, there is nothing to keep warm.
	 */
	public function test_handle_cron_dispatches_nothing_when_no_audience_is_used() {
		$this->set_up_mailchimp();

		Newspack_Newsletters_Mailchimp_Cached_Data::handle_cron();

		$this->assertSame( [], $this->get_dispatched() );
	}

	/**
	 * Configure Mailchimp as the provider and stub the audiences the account has.
	 */
	private function set_up_mailchimp() {
		update_option( 'newspack_newsletters_service_provider', 'mailchimp' );
		update_option( 'newspack_mailchimp_api_key', 'test-us' );
		add_filter( 'mailchimp_mock_get', [ $this, 'mock_lists_response' ], 10, 2 );
		add_filter( 'pre_http_request', [ $this, 'capture_dispatch' ], 10, 3 );
	}

	/**
	 * Stubs the Mailchimp `lists` endpoint with an account holding both used and
	 * unused audiences.
	 *
	 * @param array  $response The mocked response.
	 * @param string $endpoint The requested endpoint.
	 * @return array
	 */
	public function mock_lists_response( $response, $endpoint ) {
		if ( 'lists' !== $endpoint ) {
			return $response;
		}
		$audiences = [
			self::AUDIENCE_DIRECT,
			self::AUDIENCE_SUBLIST,
			self::AUDIENCE_SEND,
			self::AUDIENCE_UNUSED,
			self::AUDIENCE_OTHER_PROVIDER,
		];
		return [
			'lists' => array_map(
				function ( $id ) {
					return [
						'id'   => $id,
						'name' => $id,
					];
				},
				$audiences
			),
		];
	}

	/**
	 * Records the audience IDs the cron dispatches async refresh requests for.
	 *
	 * @param mixed  $preempt Whether to preempt the request.
	 * @param array  $args    The request arguments.
	 * @param string $url     The request URL.
	 * @return mixed
	 */
	public function capture_dispatch( $preempt, $args, $url ) {
		if ( false === strpos( $url, 'admin-ajax.php' ) ) {
			return $preempt;
		}
		if ( ! empty( $args['body']['list_id'] ) ) {
			$this->dispatched[] = $args['body']['list_id'];
		}
		return [
			'headers'  => [],
			'body'     => '',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
	}

	/**
	 * The dispatched audience IDs, sorted so assertions do not depend on order.
	 *
	 * @return string[]
	 */
	private function get_dispatched() {
		$dispatched = $this->dispatched;
		sort( $dispatched );
		return $dispatched;
	}

	/**
	 * Creates a remote subscription list for a provider.
	 *
	 * @param string $remote_id The remote (public) ID.
	 * @param string $provider  The provider slug.
	 * @return Subscription_List
	 */
	private function create_remote_list( $remote_id, $provider ) {
		$post_id = wp_insert_post(
			[
				'post_title'  => 'List ' . $remote_id,
				'post_type'   => Subscription_Lists::CPT,
				'post_status' => 'publish',
			]
		);
		$list    = new Subscription_List( $post_id );
		$list->set_remote_id( $remote_id );
		$list->set_type( 'remote' );
		$list->set_provider( $provider );
		return $list;
	}

	/**
	 * Creates a newsletter that sends to a given audience.
	 *
	 * @param string $send_list_id The audience ID.
	 * @return int
	 */
	private function create_newsletter( $send_list_id ) {
		$post_id = wp_insert_post(
			[
				'post_title'  => 'Newsletter for ' . $send_list_id,
				'post_type'   => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'draft',
			]
		);
		update_post_meta( $post_id, 'send_list_id', $send_list_id );
		return $post_id;
	}
}
