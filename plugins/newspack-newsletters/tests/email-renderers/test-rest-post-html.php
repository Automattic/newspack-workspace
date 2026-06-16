<?php
/**
 * Class REST post-html Endpoint Test
 *
 * @package Newspack_Newsletters
 */

/**
 * Tests for the `post-html` REST route that renders a newsletter to final
 * email HTML via the WC engine.
 */
class Test_REST_Post_Html extends WP_UnitTestCase {
	/**
	 * The full route path under the plugin's REST namespace.
	 *
	 * @var string
	 */
	const ROUTE = '/' . \Newspack_Newsletters::API_NAMESPACE . '/post-html';

	/**
	 * Enable the WC renderer flag and ensure the REST routes are registered.
	 *
	 * The test bootstrap fires `init` once already; here we boot the WC editor
	 * so render_wc() can run and fire `rest_api_init` (the established pattern in
	 * this plugin's REST tests) to register the plugin's routes into the server.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		\Newspack\Newsletters\Email_Renderers\Editor_Bootstrap::init();
		do_action( 'rest_api_init' );
	}

	/**
	 * Remove the WC renderer flag.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		parent::tear_down();
	}

	/**
	 * Create a newsletter CPT post carrying a single core paragraph block.
	 *
	 * @param string $body Paragraph body text.
	 * @return int Created post ID.
	 */
	private function create_newsletter_with_paragraph( $body ) {
		return self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Test newsletter',
				'post_content' => '<!-- wp:paragraph --><p>' . $body . '</p><!-- /wp:paragraph -->',
			]
		);
	}

	/**
	 * An authorized request returns 200 with email-safe HTML containing the body.
	 *
	 * The WC engine wraps content in tables for email-client compatibility, so a
	 * successful render both echoes the body text and emits at least one table.
	 *
	 * @return void
	 */
	public function test_post_html_route_returns_rendered_html() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post_id = $this->create_newsletter_with_paragraph( 'Hello from the WC endpoint' );

		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'post_id', $post_id );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'html', $data );
		$this->assertStringContainsString( 'Hello from the WC endpoint', $data['html'] );
		$this->assertStringContainsString( '<table', $data['html'] );
	}

	/**
	 * A request for a non-existent post returns a 404.
	 *
	 * @return void
	 */
	public function test_post_html_route_404_for_missing_post() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'post_id', 99999999 );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * A request from an unauthorized (logged-out) user is rejected, confirming
	 * the route is gated by api_authoring_permissions_check.
	 *
	 * @return void
	 */
	public function test_post_html_route_requires_authorization() {
		wp_set_current_user( 0 );
		$post_id = $this->create_newsletter_with_paragraph( 'Should be gated' );

		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'post_id', $post_id );
		$response = rest_do_request( $request );

		$this->assertNotSame( 200, $response->get_status() );
		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}
}
