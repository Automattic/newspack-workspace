<?php
/**
 * Tests for the setup wizard's homepage handling.
 *
 * @package Newspack\Tests
 */

/**
 * Setup wizard homepage test case.
 *
 * @group setup-wizard
 * @covers \Newspack\Setup_Wizard
 */
class Setup_Wizard_Homepage_Test extends WP_UnitTestCase {

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
		delete_option( '_newspack_starter_content_approach' );
		update_option( 'show_on_front', 'posts' );
		delete_option( 'page_on_front' );
		set_theme_mod( 'header_color_hex', '#003da5' );
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
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
	 * The homepage route builds the layout page when asked for the layout only.
	 */
	public function test_layout_only_builds_the_layout_front_page() {
		$response = $this->request( 'POST', '/starter-content/homepage', [ 'layout_only' => true ] );
		$this->assertSame( 200, $response->get_status() );
		$front_id = (int) get_option( 'page_on_front' );
		$this->assertGreaterThan( 0, $front_id );
		$this->assertSame( 'page', get_option( 'show_on_front' ) );
		$this->assertStringContainsString( 'newspack-blocks/homepage-articles', get_post( $front_id )->post_content );
	}

	/**
	 * A layout_only value that is not a boolean is rejected.
	 */
	public function test_layout_only_rejects_a_non_boolean() {
		$response = $this->request( 'POST', '/starter-content/homepage', [ 'layout_only' => 'nope' ] );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, (int) get_option( 'page_on_front' ) );
	}

	/**
	 * Save the Design step with a homepage layout while setup is incomplete.
	 *
	 * @return WP_REST_Response
	 */
	private function save_design() {
		return $this->request(
			'POST',
			'/theme',
			[
				'theme'      => '',
				'theme_mods' => [ 'homepage_pattern_index' => 1 ],
			]
		);
	}

	/**
	 * A front page the wizard did not build keeps its content when the design is saved.
	 */
	public function test_design_save_leaves_an_existing_front_page_alone() {
		delete_option( NEWSPACK_SETUP_COMPLETE );
		$front = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<!-- wp:paragraph --><p>Our own homepage</p><!-- /wp:paragraph -->',
			]
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );

		$this->save_design();
		$this->assertSame( '<!-- wp:paragraph --><p>Our own homepage</p><!-- /wp:paragraph -->', get_post( $front )->post_content );
	}

	/**
	 * The layout page the wizard built takes the layout picked on the Design step.
	 */
	public function test_design_save_applies_the_layout_to_the_wizard_homepage() {
		delete_option( NEWSPACK_SETUP_COMPLETE );
		$page_id = \Newspack\Starter_Content::create_layout_homepage();

		$this->save_design();
		$this->assertSame( \Newspack\Starter_Content::get_homepage_pattern( 1 )['content'], get_post( $page_id )->post_content );
	}
}
