<?php
/**
 * Tests for the setup wizard install state and initial check.
 *
 * @package Newspack\Tests
 */

use Newspack\Setup_Wizard;

/**
 * Setup wizard install state test case.
 *
 * @group setup-wizard
 * @covers \Newspack\Setup_Wizard
 */
class Setup_Wizard_Install_State_Test extends WP_UnitTestCase {

	/**
	 * REST route prefix of the wizard.
	 *
	 * @var string
	 */
	const ROUTE = '/newspack/v1/wizard/newspack-setup-wizard';

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( Setup_Wizard::INSTALL_STATE_OPTION );
		// Without the Newspack Theme there is no primary color to seed the header color from.
		set_theme_mod( 'header_color_hex', '#003da5' );
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		delete_option( Setup_Wizard::INSTALL_STATE_OPTION );
		remove_theme_mod( 'header_color_hex' );
		parent::tear_down();
	}

	/**
	 * Dispatch a request to the wizard.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path after the wizard prefix.
	 * @param array  $params Body params.
	 * @return WP_REST_Response
	 */
	private function request( $method, $path, $params = [] ) {
		$request = new WP_REST_Request( $method, self::ROUTE . $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Completing setup forgets which store currency the identity import set.
	 */
	public function test_complete_forgets_the_imported_currency() {
		update_option( Newspack\Starter_Content::IMPORTED_CURRENCY_OPTION, 'EUR' );
		$this->request( 'POST', '/complete' );
		$this->assertFalse( get_option( Newspack\Starter_Content::IMPORTED_CURRENCY_OPTION ) );
	}

	/**
	 * Install state round trips through the initial check.
	 */
	public function test_install_state_round_trips() {
		$this->request(
			'POST',
			'/install-state',
			[
				'approach' => 'import',
				'complete' => true,
			] 
		);
		$this->assertSame(
			[
				'approach' => 'import',
				'complete' => true,
			],
			$this->request( 'GET', '/initial-check' )->get_data()['install_state']
		);
	}

	/**
	 * The initial check links to the static front page when there is one.
	 */
	public function test_initial_check_returns_the_homepage_edit_url() {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );
		$this->assertSame(
			get_edit_post_link( $page_id, 'raw' ),
			$this->request( 'GET', '/initial-check' )->get_data()['homepage_edit_url']
		);
	}

	/**
	 * The initial check has no homepage link without a static front page.
	 */
	public function test_initial_check_homepage_edit_url_is_null_without_a_front_page() {
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', 0 );
		$this->assertNull( $this->request( 'GET', '/initial-check' )->get_data()['homepage_edit_url'] );

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', 999999 );
		$this->assertNull( $this->request( 'GET', '/initial-check' )->get_data()['homepage_edit_url'] );
	}

	/**
	 * Done links to Display Ads only when Newspack Ads is active, which it is not in the test suite.
	 */
	public function test_initial_check_leaves_out_ads_without_newspack_ads() {
		$next_steps = $this->request( 'GET', '/initial-check' )->get_data()['next_steps'];
		$this->assertFalse( class_exists( 'Newspack_Ads\Core' ) );
		$this->assertNull( $next_steps['ads'] );
		$this->assertSame( admin_url( 'admin.php?page=newspack-audience' ), $next_steps['reader_revenue'] );
	}

	/**
	 * Install state defaults to not started.
	 */
	public function test_install_state_defaults_to_not_started() {
		$this->assertSame(
			[
				'approach' => null,
				'complete' => false,
			],
			$this->request( 'GET', '/initial-check' )->get_data()['install_state']
		);
	}

	/**
	 * A site that finished setup before the install state existed counts as installed.
	 */
	public function test_install_state_is_complete_for_a_site_that_finished_setup_before() {
		update_option( NEWSPACK_SETUP_COMPLETE, true );
		$this->assertSame(
			[
				'approach' => null,
				'complete' => true,
			],
			$this->request( 'GET', '/initial-check' )->get_data()['install_state']
		);
	}

	/**
	 * An unknown approach is rejected.
	 */
	public function test_install_state_rejects_an_unknown_approach() {
		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/install-state',
				[
					'approach' => 'nope',
					'complete' => true,
				] 
			)->get_status() 
		);
	}

	/**
	 * A complete flag that is not a boolean is rejected and nothing is stored.
	 */
	public function test_install_state_rejects_a_non_boolean_complete() {
		$response = $this->request(
			'POST',
			'/install-state',
			[
				'approach' => 'generated',
				'complete' => 'nope',
			]
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( get_option( Setup_Wizard::INSTALL_STATE_OPTION ) );
	}

	/**
	 * Homepage patterns carry no donate or subscribe block.
	 */
	public function test_homepage_patterns_carry_no_donate_or_subscribe_block() {
		update_option( 'newspack_service_enabled_reader-revenue', true );
		$patterns = $this->request( 'GET', '/theme' )->get_data()['homepage_patterns'];
		$this->assertNotEmpty( $patterns );
		foreach ( $patterns as $pattern ) {
			$this->assertStringNotContainsString( 'newspack-blocks/donate', $pattern['content'] );
			$this->assertStringNotContainsString( 'jetpack/mailchimp', $pattern['content'] );
		}
	}
}
