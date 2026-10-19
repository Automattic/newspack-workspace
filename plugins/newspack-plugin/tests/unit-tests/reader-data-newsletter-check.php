<?php
/**
 * Tests for the server-side newsletter subscription check: the REST route the
 * browser calls after a signup form submits, the scheduled recheck, and the
 * third-party form hooks.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Data;
use Newspack\Reader_Data_Trust;

require_once dirname( __DIR__ ) . '/mocks/newsletters-mocks.php';
require_once dirname( __DIR__ ) . '/mocks/user-switching-mock.php';
require_once dirname( __DIR__ ) . '/mocks/gravityforms-api-mock.php';

/**
 * Tests for Reader_Data's newsletter-check route and Newsletter_Signup_Detection.
 *
 * @group reader-data
 */
class Newspack_Test_Reader_Data_Newsletter_Check extends WP_UnitTestCase {
	/**
	 * Reader under test.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Reader email.
	 *
	 * @var string
	 */
	private $email = 'check-reader@example.test';

	/**
	 * Per-test setup.
	 */
	public function set_up() {
		parent::set_up();
		Newspack_Newsletters::reset_calls();
		Newspack_Newsletters_Subscription::reset_calls();
		$this->user_id = $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => $this->email,
			]
		);
		wp_clear_scheduled_hook( Reader_Data::RECHECK_HOOK, [ $this->user_id ] );
	}

	/**
	 * Per-test teardown.
	 */
	public function tear_down() {
		Newspack_Newsletters::reset_calls();
		Newspack_Newsletters_Subscription::reset_calls();
		GFAPI::$feeds = [];
		unset( $GLOBALS['newspack_test_switched_from_user'] );
		wp_clear_scheduled_hook( Reader_Data::RECHECK_HOOK, [ $this->user_id ] );
		wp_clear_scheduled_hook( Reader_Data::RECHECK_HOOK, [ 0 ] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Dispatch a newsletter-check request as the current user.
	 *
	 * @param array $params Request parameters.
	 *
	 * @return WP_REST_Response
	 */
	private function check_request( array $params = [] ) {
		$request = new WP_REST_Request( 'POST', '/' . NEWSPACK_API_NAMESPACE . '/reader-data/newsletter-check' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	/**
	 * Number of recheck events queued for the reader.
	 *
	 * @return int
	 */
	private function count_rechecks() {
		$count = 0;
		foreach ( _get_cron_array() as $hooks ) {
			if ( empty( $hooks[ Reader_Data::RECHECK_HOOK ] ) ) {
				continue;
			}
			foreach ( $hooks[ Reader_Data::RECHECK_HOOK ] as $event ) {
				if ( [ $this->user_id ] === $event['args'] ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/**
	 * A logged-out request is refused.
	 */
	public function test_endpoint_requires_login() {
		$response = $this->check_request();
		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}

	/**
	 * A session an admin switched into is refused.
	 */
	public function test_endpoint_rejects_switched_session() {
		wp_set_current_user( $this->user_id );
		$admin_id                                    = $this->factory->user->create( [ 'role' => 'administrator' ] );
		$GLOBALS['newspack_test_switched_from_user'] = get_user_by( 'id', $admin_id );
		Newspack_Newsletters_Subscription::$contact_lists[ $this->email ] = [ 'list-1' ];

		$response = $this->check_request();

		$this->assertSame( 403, $response->get_status() );
		$this->assertFalse( Reader_Data::get_data( $this->user_id, 'is_newsletter_subscriber' ) );
	}

	/**
	 * Parameters sent with the request play no part in what is stored.
	 */
	public function test_endpoint_ignores_body() {
		wp_set_current_user( $this->user_id );

		$response = $this->check_request(
			[
				'key'   => 'is_newsletter_subscriber',
				'value' => 'true',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'items' => [ 'is_newsletter_subscriber' => null ] ], $response->get_data() );
		$this->assertFalse( Reader_Data::get_data( $this->user_id, 'is_newsletter_subscriber' ) );
	}

	/**
	 * A second call within the window answers 429 without another lookup.
	 */
	public function test_endpoint_rate_limited() {
		wp_set_current_user( $this->user_id );
		Newspack_Newsletters_Subscription::$contact_lists[ $this->email ] = [ 'list-1' ];

		$this->assertSame( 200, $this->check_request()->get_status() );
		$second = $this->check_request();

		$this->assertSame( 429, $second->get_status() );
		$this->assertSame( 'rate_limited', $second->as_error()->get_error_code() );
	}

	/**
	 * Without a configured newsletter provider the route answers null and
	 * writes nothing.
	 */
	public function test_endpoint_noop_without_newsletters() {
		wp_set_current_user( $this->user_id );
		Newspack_Newsletters::$is_service_provider_configured             = false;
		Newspack_Newsletters_Subscription::$contact_lists[ $this->email ] = [ 'list-1' ];

		$response = $this->check_request();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'items' => [ 'is_newsletter_subscriber' => null ] ], $response->get_data() );
		$this->assertFalse( Reader_Data::get_data( $this->user_id, 'is_newsletter_subscriber' ) );
		$this->assertFalse( Reader_Data::get_data( $this->user_id, 'newsletter_subscribed_lists' ) );
	}

	/**
	 * A successful lookup stores both items as server-written values and
	 * returns the stored subscriber flag.
	 */
	public function test_endpoint_writes_marked_value() {
		wp_set_current_user( $this->user_id );
		Newspack_Newsletters_Subscription::$contact_lists[ $this->email ] = [ 'list-1' ];

		$response = $this->check_request();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'items' => [ 'is_newsletter_subscriber' => 'true' ] ], $response->get_data() );
		$this->assertSame( '["list-1"]', Reader_Data::get_data( $this->user_id, 'newsletter_subscribed_lists' ) );
		$this->assertTrue( Reader_Data_Trust::has_valid_mark( $this->user_id, 'is_newsletter_subscriber' ) );
		$this->assertTrue( Reader_Data_Trust::has_valid_mark( $this->user_id, 'newsletter_subscribed_lists' ) );
		$this->assertSame( 0, $this->count_rechecks() );
	}

	/**
	 * Repeated scheduling queues a single recheck, and a lookup that does not
	 * find a subscription queues one too.
	 */
	public function test_recheck_scheduled_once() {
		wp_set_current_user( $this->user_id );

		$this->check_request();
		$this->assertSame( 1, $this->count_rechecks() );

		Reader_Data::schedule_recheck( $this->user_id );
		Reader_Data::schedule_recheck( $this->user_id );
		$this->assertSame( 1, $this->count_rechecks() );

		$next = wp_next_scheduled( Reader_Data::RECHECK_HOOK, [ $this->user_id ] );
		$this->assertGreaterThan( time() + 100, $next );
	}

	/**
	 * The scheduled recheck refreshes the reader's subscription.
	 */
	public function test_recheck_handler_refreshes() {
		Newspack_Newsletters_Subscription::$contact_lists[ $this->email ] = [ 'list-1' ];

		do_action( Reader_Data::RECHECK_HOOK, $this->user_id );

		$this->assertSame( 'true', Reader_Data::get_data( $this->user_id, 'is_newsletter_subscriber' ) );
	}

	/**
	 * An MC4WP signup by a logged-in reader refreshes their subscription.
	 */
	public function test_mc4wp_hook_refreshes_for_logged_in_user() {
		wp_set_current_user( $this->user_id );
		Newspack_Newsletters_Subscription::$contact_lists[ $this->email ] = [ 'list-1' ];

		do_action( 'mc4wp_form_subscribed', null, $this->email, [], [] );

		$this->assertSame( 'true', Reader_Data::get_data( $this->user_id, 'is_newsletter_subscriber' ) );
		$this->assertTrue( Reader_Data_Trust::has_valid_mark( $this->user_id, 'is_newsletter_subscriber' ) );
	}

	/**
	 * An MC4WP signup from a switched session writes nothing.
	 */
	public function test_mc4wp_hook_ignores_switched_session() {
		wp_set_current_user( $this->user_id );
		$admin_id                                    = $this->factory->user->create( [ 'role' => 'administrator' ] );
		$GLOBALS['newspack_test_switched_from_user'] = get_user_by( 'id', $admin_id );
		Newspack_Newsletters_Subscription::$contact_lists[ $this->email ] = [ 'list-1' ];

		do_action( 'mc4wp_form_subscribed', null, $this->email, [], [] );

		$this->assertFalse( Reader_Data::get_data( $this->user_id, 'is_newsletter_subscriber' ) );
	}

	/**
	 * A logged-in reader's submission of a form with a newsletter feed
	 * refreshes their subscription.
	 */
	public function test_gform_hook_refreshes_for_form_with_esp_feed() {
		wp_set_current_user( $this->user_id );
		GFAPI::$feeds[7]['gravityformsmailchimp']                        = [ [ 'id' => 1 ] ];
		Newspack_Newsletters_Subscription::$contact_lists[ $this->email ] = [ 'list-1' ];

		do_action( 'gform_after_submission', [ 'id' => 1 ], [ 'id' => 7 ] );

		$this->assertSame( 'true', Reader_Data::get_data( $this->user_id, 'is_newsletter_subscriber' ) );
	}

	/**
	 * A form without a newsletter feed triggers no check.
	 */
	public function test_gform_hook_ignores_form_without_esp_feed() {
		wp_set_current_user( $this->user_id );
		Newspack_Newsletters_Subscription::$contact_lists[ $this->email ] = [ 'list-1' ];

		do_action( 'gform_after_submission', [ 'id' => 1 ], [ 'id' => 7 ] );

		$this->assertFalse( Reader_Data::get_data( $this->user_id, 'is_newsletter_subscriber' ) );
		$this->assertSame( 0, $this->count_rechecks() );
	}

	/**
	 * A Gravity Forms submission while logged out writes nothing and queues
	 * nothing.
	 */
	public function test_gform_hook_ignores_logged_out() {
		GFAPI::$feeds[7]['gravityformsmailchimp']                        = [ [ 'id' => 1 ] ];
		Newspack_Newsletters_Subscription::$contact_lists[ $this->email ] = [ 'list-1' ];

		do_action( 'gform_after_submission', [ 'id' => 1 ], [ 'id' => 7 ] );

		$this->assertFalse( Reader_Data::get_data( $this->user_id, 'is_newsletter_subscriber' ) );
		$this->assertFalse( wp_next_scheduled( Reader_Data::RECHECK_HOOK, [ 0 ] ) );
	}
}
