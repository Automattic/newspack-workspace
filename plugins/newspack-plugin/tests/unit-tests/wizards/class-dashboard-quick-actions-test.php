<?php
/**
 * Tests for the dashboard's quick actions, which vary with what the site has
 * available: the newsletter editor, the Insights page, or neither.
 *
 * @package Newspack\Tests
 */

use Newspack\Newspack_Dashboard;

/**
 * Dashboard quick actions test case.
 *
 * @group dashboard
 * @covers \Newspack\Newspack_Dashboard
 */
class Dashboard_Quick_Actions_Test extends WP_UnitTestCase {

	/**
	 * The wizard under test.
	 *
	 * @var Newspack_Dashboard
	 */
	private $dashboard;

	/**
	 * Set up an administrator and a dashboard instance.
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->dashboard = new Newspack_Dashboard();
	}

	/**
	 * Leave the newsletter post type and the Insights menu entry as they were
	 * found, so neither leaks into another test.
	 */
	public function tear_down() {
		if ( post_type_exists( 'newspack_nl_cpt' ) ) {
			unregister_post_type( 'newspack_nl_cpt' );
		}
		unset( $GLOBALS['_parent_pages']['newspack-insights'] );

		parent::tear_down();
	}

	/**
	 * Stand in for newspack-newsletters, which the test suite does not load.
	 */
	private function register_newsletter_post_type() {
		register_post_type( 'newspack_nl_cpt', [ 'public' => false ] );
	}

	/**
	 * Stand in for newspack-manager registering the Insights page. `menu_page_url()`
	 * reads this global, so populating it is what "the page exists" means here.
	 */
	private function register_insights_page() {
		$GLOBALS['_parent_pages']['newspack-insights'] = false;
	}

	/**
	 * Quick actions, keyed by title.
	 *
	 * @return array
	 */
	private function get_quick_actions() {
		$data = $this->dashboard->get_local_data();
		return array_column( $data['quickActions'], null, 'title' );
	}

	/**
	 * Composing a post is always offered.
	 */
	public function test_new_post_action_is_always_present() {
		$actions = $this->get_quick_actions();

		$this->assertArrayHasKey( 'Start a New Post', $actions );
		$this->assertSame( 'post', $actions['Start a New Post']['icon'] );
	}

	/**
	 * With the newsletter post type available, the second action opens its editor.
	 */
	public function test_newsletter_action_when_post_type_is_registered() {
		$this->register_newsletter_post_type();

		$actions = $this->get_quick_actions();

		$this->assertArrayHasKey( 'Draft a Newsletter', $actions );
		$this->assertArrayNotHasKey( 'Create a Page', $actions );
		$this->assertStringContainsString( 'post_type=newspack_nl_cpt', $actions['Draft a Newsletter']['href'] );
	}

	/**
	 * Without the newsletter post type, the slot falls back to creating a page.
	 */
	public function test_page_action_when_newsletter_post_type_is_absent() {
		$actions = $this->get_quick_actions();

		$this->assertArrayHasKey( 'Create a Page', $actions );
		$this->assertArrayNotHasKey( 'Draft a Newsletter', $actions );
		$this->assertStringContainsString( 'post_type=page', $actions['Create a Page']['href'] );
	}

	/**
	 * A contributor cannot create newsletters, so the fallback applies to them even
	 * when the post type is registered.
	 */
	public function test_newsletter_action_is_withheld_without_the_capability() {
		$this->register_newsletter_post_type();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$actions = $this->get_quick_actions();

		$this->assertArrayNotHasKey( 'Draft a Newsletter', $actions );
	}

	/**
	 * With the Insights page registered, the third action points at it.
	 */
	public function test_insights_action_when_the_page_is_registered() {
		$this->register_insights_page();

		$actions = $this->get_quick_actions();

		$this->assertArrayHasKey( 'Explore Insights', $actions );
		$this->assertArrayNotHasKey( 'Open Data Dashboard', $actions );
		$this->assertSame( 'chartReport', $actions['Explore Insights']['icon'] );
		$this->assertStringContainsString( 'page=newspack-insights', $actions['Explore Insights']['href'] );
	}

	/**
	 * Without it, the third action falls back to the external report.
	 */
	public function test_data_dashboard_action_when_insights_is_absent() {
		$actions = $this->get_quick_actions();

		$this->assertArrayHasKey( 'Open Data Dashboard', $actions );
		$this->assertArrayNotHasKey( 'Explore Insights', $actions );
		$this->assertSame( 'chartBar', $actions['Open Data Dashboard']['icon'] );
		$this->assertStringStartsWith( 'https://lookerstudio.google.com/', $actions['Open Data Dashboard']['href'] );
	}
}
