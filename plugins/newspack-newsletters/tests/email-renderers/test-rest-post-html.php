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
	 * Enable the WC renderer flag and register the plugin's REST routes on a fresh server instance.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Reset the REST server and remove the WC renderer flag.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
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
	 * An authorized request returns 200 with email-safe HTML (at least one table) containing the body text.
	 *
	 * @return void
	 */
	public function test_post_html_route_returns_rendered_html() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post_id = $this->create_newsletter_with_paragraph( 'Hello from the WC endpoint' );

		$request = new WP_REST_Request( 'GET', self::ROUTE );
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

		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_param( 'post_id', 99999999 );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * A request for a non-newsletter post type returns 404 — the endpoint only renders the newsletter CPT.
	 *
	 * @return void
	 */
	public function test_post_html_route_404_for_non_newsletter_post() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$page_id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Not a newsletter</p><!-- /wp:paragraph -->',
			]
		);

		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_param( 'post_id', $page_id );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * When the WC engine returns '' (render failure), the endpoint surfaces 500 rather than a misleading
	 * 200 with empty HTML. Failure is simulated by throwing in the theme.json filter.
	 *
	 * @return void
	 */
	public function test_post_html_route_500_when_render_fails() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post_id = $this->create_newsletter_with_paragraph( 'Will fail to render' );

		$thrower = static function () {
			throw new \RuntimeException( 'Simulated render failure' );
		};
		add_filter( 'woocommerce_email_editor_theme_json', $thrower, 99 );

		// try/finally so the throwing filter is always removed, even if the
		// request errors — otherwise it would leak into subsequent tests.
		try {
			$request = new WP_REST_Request( 'GET', self::ROUTE );
			$request->set_param( 'post_id', $post_id );
			$response = rest_do_request( $request );
		} finally {
			remove_filter( 'woocommerce_email_editor_theme_json', $thrower, 99 );
		}

		$this->assertSame( 500, $response->get_status() );
	}

	/**
	 * A request from an unauthorized user is rejected (401/403) — the route is gated by api_authoring_permissions_check.
	 *
	 * @return void
	 */
	public function test_post_html_route_requires_authorization() {
		wp_set_current_user( 0 );
		$post_id = $this->create_newsletter_with_paragraph( 'Should be gated' );

		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_param( 'post_id', $post_id );
		$response = rest_do_request( $request );

		$this->assertNotSame( 200, $response->get_status() );
		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}

	/**
	 * An authenticated but under-privileged user (a contributor, who can edit their
	 * own posts but lacks `edit_others_posts`) is rejected. This pins the endpoint's
	 * real authorization boundary — the logged-out case alone doesn't exercise it.
	 *
	 * @return void
	 */
	public function test_post_html_route_rejects_under_privileged_user() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
		$post_id = $this->create_newsletter_with_paragraph( 'Should be gated for contributors' );

		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_param( 'post_id', $post_id );
		$response = rest_do_request( $request );

		$this->assertNotSame( 200, $response->get_status() );
		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}
}
