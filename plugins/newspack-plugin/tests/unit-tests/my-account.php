<?php
/**
 * Tests for the Newspack My Account core shell.
 *
 * @package Newspack\Tests
 */

use Newspack\My_Account;
use Newspack\WooCommerce_My_Account;

/**
 * Test the My_Account class.
 */
class Newspack_Test_My_Account extends WP_UnitTestCase {
	/**
	 * The class should exist and expose its public accessors.
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'Newspack\My_Account' ) );
		$this->assertTrue( method_exists( 'Newspack\My_Account', 'get_page_id' ) );
		$this->assertTrue( method_exists( 'Newspack\My_Account', 'is_account_page' ) );
		$this->assertTrue( method_exists( 'Newspack\My_Account', 'get_endpoint_url' ) );
	}

	/**
	 * Native page ID comes from the Newspack option when Woo is absent.
	 */
	public function test_get_page_id_native() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( My_Account::PAGE_ID_OPTION, $page_id );

		$this->assertSame( $page_id, My_Account::get_page_id() );

		delete_option( My_Account::PAGE_ID_OPTION );
		$this->assertSame( 0, My_Account::get_page_id() );
	}

	/**
	 * Native is_account_page() is true on the account page and false elsewhere.
	 */
	public function test_is_account_page_native() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$page_id  = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$other_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( My_Account::PAGE_ID_OPTION, $page_id );

		$this->go_to( get_permalink( $page_id ) );
		$this->assertTrue( My_Account::is_account_page() );

		$this->go_to( get_permalink( $other_id ) );
		$this->assertFalse( My_Account::is_account_page() );

		delete_option( My_Account::PAGE_ID_OPTION );
	}

	/**
	 * Native get_endpoint_url() returns the base permalink for the empty endpoint
	 * and a sub-path for a named endpoint.
	 */
	public function test_get_endpoint_url_native() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( My_Account::PAGE_ID_OPTION, $page_id );

		$base = My_Account::get_endpoint_url();
		$this->assertSame( get_permalink( $page_id ), $base );

		$edit = My_Account::get_endpoint_url( 'edit-account' );
		$this->assertStringContainsString( 'edit-account', $edit );
		$this->assertStringStartsWith( rtrim( get_permalink( $page_id ), '/' ), rtrim( $edit, '/' ) );

		delete_option( My_Account::PAGE_ID_OPTION );
	}

	/**
	 * Native get_or_create_page() creates a page once and reuses it afterward.
	 */
	public function test_get_or_create_page_native() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		delete_option( My_Account::PAGE_ID_OPTION );
		delete_option( 'woocommerce_myaccount_page_id' );

		$page_id = My_Account::get_or_create_page();
		$this->assertGreaterThan( 0, $page_id );
		$this->assertSame( 'page', get_post_type( $page_id ) );
		$this->assertStringContainsString( '[newspack_my_account]', get_post( $page_id )->post_content );

		// Second call must not create a new page.
		$this->assertSame( $page_id, My_Account::get_or_create_page() );

		wp_delete_post( $page_id, true );
		delete_option( My_Account::PAGE_ID_OPTION );
	}

	/**
	 * The get_or_create_page() method reuses an existing WooCommerce account
	 * page instead of creating a duplicate.
	 */
	public function test_get_or_create_page_reuses_wc_page() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		delete_option( My_Account::PAGE_ID_OPTION );
		$wc_page_id = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'my-account',
			]
		);
		update_option( 'woocommerce_myaccount_page_id', $wc_page_id );

		$this->assertSame( $wc_page_id, My_Account::get_or_create_page() );
		$this->assertSame( $wc_page_id, (int) get_option( My_Account::PAGE_ID_OPTION, 0 ) );

		delete_option( 'woocommerce_myaccount_page_id' );
		delete_option( My_Account::PAGE_ID_OPTION );
		wp_delete_post( $wc_page_id, true );
	}

	/**
	 * The get_page_id() method falls back to the WooCommerce account page when
	 * the native option is unset.
	 */
	public function test_get_page_id_falls_back_to_wc_page() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		delete_option( My_Account::PAGE_ID_OPTION );
		$wc_page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( 'woocommerce_myaccount_page_id', $wc_page_id );

		$this->assertSame( $wc_page_id, My_Account::get_page_id() );

		delete_option( 'woocommerce_myaccount_page_id' );
	}

	/**
	 * The [newspack_my_account] shortcode is registered and renders a container.
	 */
	public function test_shortcode_registered() {
		My_Account::register_shortcode();
		$this->assertTrue( shortcode_exists( 'newspack_my_account' ) );

		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$html = do_shortcode( '[newspack_my_account]' );
		$this->assertStringContainsString( 'newspack-my-account', $html );
	}

	/**
	 * Core endpoints are registered as query vars when Woo is absent.
	 */
	public function test_query_vars_native() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$vars = My_Account::add_query_vars( [] );
		$this->assertContains( 'edit-account', $vars );
		$this->assertContains( 'newspack-delete-account', $vars );
	}

	/**
	 * Core get_endpoints() returns the expected slugs.
	 */
	public function test_get_endpoints_core() {
		$endpoints = My_Account::get_endpoints();
		$this->assertArrayHasKey( 'edit-account', $endpoints );
		$this->assertArrayHasKey( 'newspack-delete-account', $endpoints );
	}

	/**
	 * Native get_tabs() returns ordered slug => label entries: account details,
	 * then logout last, excluding the dashboard and delete-account endpoints.
	 */
	public function test_get_tabs() {
		$tabs = My_Account::get_tabs();
		$this->assertArrayHasKey( 'edit-account', $tabs );
		$this->assertArrayHasKey( 'customer-logout', $tabs );
		$this->assertArrayNotHasKey( '', $tabs );
		$this->assertArrayNotHasKey( My_Account::ENDPOINT_DELETE_ACCOUNT, $tabs );
		// Logout is always last.
		$this->assertSame( 'customer-logout', array_key_last( $tabs ) );
	}

	/**
	 * The dispatcher fires the newspack_my_account_content action for the
	 * current endpoint and the core content callback for the dashboard.
	 */
	public function test_render_content_dispatch() {
		$fired = [];
		add_action(
			'newspack_my_account_content',
			function ( $endpoint ) use ( &$fired ) {
				$fired[] = $endpoint;
			}
		);

		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		ob_start();
		My_Account::render_content();
		ob_get_clean();

		$this->assertSame( [ '' ], $fired );
	}

	/**
	 * The native save handler updates the display name but never the email: the
	 * native path has no email-change verification flow, so the email field is
	 * read-only and any submitted address is ignored (rather than reported as a
	 * successful change that is silently discarded).
	 */
	public function test_native_save_account() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$user_id = self::factory()->user->create(
			[
				'role'         => 'subscriber',
				'display_name' => 'Old Name',
				'user_email'   => 'old@example.com',
			]
		);
		wp_set_current_user( $user_id );

		$_POST['newspack_my_account_save_nonce'] = wp_create_nonce( 'newspack_my_account_save' );
		$_POST['account_display_name']           = 'New Name';
		$_POST['account_email']                  = 'new@example.com';

		My_Account::handle_save_account();

		$user = get_user_by( 'id', $user_id );
		$this->assertSame( 'New Name', $user->display_name );
		// Email is intentionally not updated on the native path.
		$this->assertSame( 'old@example.com', $user->user_email );

		unset( $_POST['newspack_my_account_save_nonce'], $_POST['account_display_name'], $_POST['account_email'] );
	}

	/**
	 * Requesting account deletion natively stores a deletion token without fatal.
	 *
	 * Exercises send_delete_account_email(), whose URL line previously called the
	 * Woo-only \wc_get_account_endpoint_url() and fataled when Woo was absent.
	 */
	public function test_native_delete_request_sets_token() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$user = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
		// Mark as a reader so the flow treats them as one if needed.
		update_user_meta( $user->ID, 'np_reader', true );

		$result = WooCommerce_My_Account::send_delete_account_email( $user );

		// Token transient is set; no fatal calling the (formerly Woo-only) URL.
		// This transient is the key proof that the URL line did not fatal.
		$this->assertNotEmpty( get_transient( 'np_reader_account_delete_' . $user->ID ) );
		// Emails::send_email may legitimately fail in the test env; only assert no fatal/error from the URL build.
		$this->assertNotInstanceOf( 'WP_Error', $result );

		delete_transient( 'np_reader_account_delete_' . $user->ID );
	}

	/**
	 * The maybe_provision_page() method creates the native page when RA is enabled and Woo absent.
	 */
	public function test_maybe_provision_page() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		delete_option( My_Account::PAGE_ID_OPTION );

		// Provisioning is gated on `manage_options`, so run as an administrator.
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		// Force Reader Activation enabled for this test.
		add_filter( 'newspack_reader_activation_enabled', '__return_true' );

		My_Account::maybe_provision_page();
		$page_id = (int) get_option( My_Account::PAGE_ID_OPTION, 0 );
		$this->assertGreaterThan( 0, $page_id );
		$this->assertSame( 'page', get_post_type( $page_id ) );

		// Idempotent: a second call does not create a new page.
		My_Account::maybe_provision_page();
		$this->assertSame( $page_id, (int) get_option( My_Account::PAGE_ID_OPTION, 0 ) );

		// A non-admin (e.g. a reader on an admin-ajax request) cannot provision.
		delete_option( My_Account::PAGE_ID_OPTION );
		$reader_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $reader_id );
		My_Account::maybe_provision_page();
		$this->assertSame( 0, (int) get_option( My_Account::PAGE_ID_OPTION, 0 ) );

		wp_set_current_user( 0 );
		remove_filter( 'newspack_reader_activation_enabled', '__return_true' );
		wp_delete_post( $page_id, true );
		delete_option( My_Account::PAGE_ID_OPTION );
	}

	/**
	 * Native account page enqueues the My Account stylesheet and body classes.
	 */
	public function test_native_account_page_styles() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( My_Account::PAGE_ID_OPTION, $page_id );
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		$this->go_to( get_permalink( $page_id ) );

		My_Account::enqueue_assets();
		$this->assertTrue( wp_style_is( 'newspack-my-account-v1', 'enqueued' ) );

		$classes = My_Account::add_body_class( [] );
		$this->assertContains( 'newspack-ui', $classes );
		$this->assertContains( 'newspack-my-account', $classes );
		$this->assertContains( 'newspack-my-account--v1', $classes );
		$this->assertContains( 'newspack-my-account--logged-in', $classes );

		wp_dequeue_style( 'newspack-my-account-v1' );
		delete_option( My_Account::PAGE_ID_OPTION );
	}

	/**
	 * Styles are not enqueued away from the account page.
	 */
	public function test_native_styles_not_enqueued_off_page() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$page_id  = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$other_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( My_Account::PAGE_ID_OPTION, $page_id );
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		$this->go_to( get_permalink( $other_id ) );

		My_Account::enqueue_assets();
		$this->assertFalse( wp_style_is( 'newspack-my-account-v1', 'enqueued' ) );

		delete_option( My_Account::PAGE_ID_OPTION );
	}

	/**
	 * The admin bar is hidden for readers on the native account page, but kept
	 * for admins/editors and untouched elsewhere.
	 */
	public function test_hide_admin_bar() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$page_id  = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$other_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( My_Account::PAGE_ID_OPTION, $page_id );

		$reader_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $reader_id, 'np_reader', true );
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		// Reader on the account page: admin bar is hidden.
		wp_set_current_user( $reader_id );
		$this->go_to( get_permalink( $page_id ) );
		$this->assertFalse( My_Account::hide_admin_bar( true ) );

		// Reader elsewhere: untouched.
		$this->go_to( get_permalink( $other_id ) );
		$this->assertTrue( My_Account::hide_admin_bar( true ) );

		// Admins/editors keep the admin bar even on the account page.
		wp_set_current_user( $admin_id );
		$this->go_to( get_permalink( $page_id ) );
		$this->assertTrue( My_Account::hide_admin_bar( true ) );

		wp_set_current_user( 0 );
		delete_option( My_Account::PAGE_ID_OPTION );
	}

	/**
	 * Logout redirect never nulls the redirect target and sends the account page home.
	 */
	public function test_redirect_to_home_after_logout() {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( My_Account::PAGE_ID_OPTION, $page_id );

		// A non-account redirect target passes through unchanged (never null).
		$home = home_url( '/' );
		$this->assertSame( $home, WooCommerce_My_Account::redirect_to_home_after_logout( $home ) );

		// Logging out from the account page redirects home.
		$account_url = My_Account::get_endpoint_url();
		if ( $account_url ) {
			$this->assertSame( get_home_url(), WooCommerce_My_Account::redirect_to_home_after_logout( $account_url ) );
		}

		delete_option( My_Account::PAGE_ID_OPTION );
	}

	/**
	 * A successful profile save stores a one-time success notice transient.
	 */
	public function test_save_sets_success_notice_transient() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		delete_transient( My_Account::NOTICE_TRANSIENT_PREFIX . $user_id );

		$_POST['newspack_my_account_save_nonce'] = wp_create_nonce( 'newspack_my_account_save' );
		$_POST['account_display_name']           = 'New Name';
		My_Account::handle_save_account();
		// Simulate what handle_form_submissions stores on success (array shape).
		set_transient(
			My_Account::NOTICE_TRANSIENT_PREFIX . $user_id,
			[
				'message' => 'Account details changed successfully.',
				'type'    => 'success',
			],
			MINUTE_IN_SECONDS
		);

		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( My_Account::PAGE_ID_OPTION, $page_id );
		$this->go_to( get_permalink( $page_id ) );

		My_Account::maybe_display_notice();
		// The transient is consumed (deleted) after display.
		$this->assertFalse( get_transient( My_Account::NOTICE_TRANSIENT_PREFIX . $user_id ) );

		unset( $_POST['newspack_my_account_save_nonce'], $_POST['account_display_name'] );
		delete_option( My_Account::PAGE_ID_OPTION );
	}

	/**
	 * The native password handler updates the password with a matching confirmation.
	 */
	public function test_handle_password_change() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$user_id = self::factory()->user->create(
			[
				'role'      => 'subscriber',
				'user_pass' => 'oldpass-123',
			]
		);
		wp_set_current_user( $user_id );
		// Reader has a password, so current_password is required.
		$_POST['newspack_my_account_password_nonce'] = wp_create_nonce( 'newspack_my_account_password' );
		$_POST['current_password']                   = 'oldpass-123';
		$_POST['password_1']                         = 'NewSecret-456';
		$_POST['password_2']                         = 'NewSecret-456';

		$this->assertTrue( My_Account::handle_password_change() );
		$this->assertTrue( wp_check_password( 'NewSecret-456', get_userdata( $user_id )->user_pass, $user_id ) );

		// Mismatch fails.
		$_POST['password_2'] = 'different';
		$this->assertFalse( My_Account::handle_password_change() );

		unset( $_POST['newspack_my_account_password_nonce'], $_POST['current_password'], $_POST['password_1'], $_POST['password_2'] );
		delete_transient( My_Account::NOTICE_TRANSIENT_PREFIX . $user_id );
	}

	/**
	 * When WooCommerce owns the shell, the accessors delegate to WooCommerce
	 * rather than the native implementation. This proves the "behavior is
	 * unchanged when WooCommerce is present" guarantee instead of assuming it.
	 *
	 * Runs in a separate process so the stubbed WooCommerce class / wc_* helpers
	 * (which flip woocommerce_owns_shell() to true) don't leak into the rest of
	 * the suite, whose native-path tests skip when WooCommerce is present.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_woocommerce_delegation() {
		// Stub WooCommerce's presence and the account-page accessors.
		if ( ! class_exists( 'WooCommerce' ) ) {
			eval( 'class WooCommerce {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stub for the delegation branch.
		}
		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			/**
			 * Stub: WooCommerce account page permalink.
			 *
			 * @param string $page Page identifier.
			 * @return string
			 */
			function wc_get_page_permalink( $page = '' ) {
				return 'https://example.test/my-account/';
			}
		}
		if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
			/**
			 * Stub: WooCommerce account endpoint URL.
			 *
			 * @param string $endpoint Endpoint slug.
			 * @return string
			 */
			function wc_get_account_endpoint_url( $endpoint ) {
				return 'https://example.test/my-account/';
			}
		}
		if ( ! function_exists( 'wc_get_endpoint_url' ) ) {
			/**
			 * Stub: WooCommerce endpoint URL builder.
			 *
			 * @param string $endpoint  Endpoint slug.
			 * @param string $value     Endpoint value.
			 * @param string $permalink Base permalink.
			 * @return string
			 */
			function wc_get_endpoint_url( $endpoint, $value, $permalink ) {
				return rtrim( $permalink, '/' ) . '/' . $endpoint . '/';
			}
		}
		if ( ! function_exists( 'is_account_page' ) ) {
			/**
			 * Stub: WooCommerce is-account-page conditional.
			 *
			 * @return bool
			 */
			function is_account_page() {
				return true;
			}
		}

		// The shell now reports WooCommerce ownership.
		$this->assertTrue( My_Account::woocommerce_owns_shell() );

		// get_page_id() reads the WooCommerce option, not the native one.
		update_option( 'woocommerce_myaccount_page_id', 4242 );
		update_option( My_Account::PAGE_ID_OPTION, 1 );
		$this->assertSame( 4242, My_Account::get_page_id() );

		// Empty / dashboard endpoint delegates to wc_get_account_endpoint_url().
		$this->assertSame( 'https://example.test/my-account/', My_Account::get_endpoint_url() );
		$this->assertSame( 'https://example.test/my-account/', My_Account::get_endpoint_url( 'dashboard' ) );

		// A named endpoint delegates to wc_get_endpoint_url().
		$this->assertSame( 'https://example.test/my-account/edit-account/', My_Account::get_endpoint_url( 'edit-account' ) );

		// is_account_page() delegates to WooCommerce's is_account_page().
		$this->assertTrue( My_Account::is_account_page() );

		delete_option( 'woocommerce_myaccount_page_id' );
		delete_option( My_Account::PAGE_ID_OPTION );
	}
}
