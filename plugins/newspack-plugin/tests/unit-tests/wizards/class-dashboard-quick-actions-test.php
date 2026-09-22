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
	 * Admin menu globals as they were before the test registered anything.
	 *
	 * @var array
	 */
	private $menu_globals = [];

	/**
	 * Whether the newsletter post type was already registered by something else.
	 *
	 * @var bool
	 */
	private $had_newsletter_post_type = false;

	/**
	 * Set up an administrator and a dashboard instance.
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		foreach ( [ 'menu', 'submenu', 'admin_page_hooks', '_registered_pages', '_parent_pages', '_wp_real_parent_file', '_wp_submenu_nopriv' ] as $key ) {
			$this->menu_globals[ $key ] = isset( $GLOBALS[ $key ] ) ? $GLOBALS[ $key ] : null;
		}
		$this->had_newsletter_post_type = post_type_exists( 'newspack_nl_cpt' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->dashboard = new Newspack_Dashboard();
	}

	/**
	 * Put the newsletter post type and the admin menu back as they were found, so
	 * neither leaks into another test.
	 */
	public function tear_down() {
		if ( ! $this->had_newsletter_post_type && post_type_exists( 'newspack_nl_cpt' ) ) {
			unregister_post_type( 'newspack_nl_cpt' );
		}
		foreach ( $this->menu_globals as $key => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $key ] );
			} else {
				$GLOBALS[ $key ] = $value;
			}
		}

		parent::tear_down();
	}

	/**
	 * Stand in for newspack-newsletters, which the test suite does not load. The
	 * real plugin registers the type with an editor UI, which is what the quick
	 * action needs.
	 *
	 * @param array $args Overrides for the registration arguments.
	 */
	private function register_newsletter_post_type( $args = [] ) {
		register_post_type( 'newspack_nl_cpt', array_merge( [ 'show_ui' => true ], $args ) );
	}

	/**
	 * Stand in for newspack-manager registering the Insights page, through the same
	 * call it makes, so the test exercises `menu_page_url()` rather than the global
	 * it happens to read. The slug is written out rather than read from
	 * `Newspack_Dashboard::INSIGHTS_PAGE_SLUG` on purpose: it is newspack-manager's
	 * to choose, so a rename on this side has to fail here.
	 */
	private function register_insights_page() {
		add_menu_page( 'Insights', 'Insights', 'manage_options', 'newspack-insights', '__return_null' );
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
	 * A post type registered without an editor UI cannot be opened by `post-new.php`,
	 * so offering it would hand the user an "Invalid post type." screen.
	 */
	public function test_newsletter_action_is_withheld_without_an_editor_ui() {
		$this->register_newsletter_post_type( [ 'show_ui' => false ] );

		$actions = $this->get_quick_actions();

		$this->assertArrayNotHasKey( 'Draft a Newsletter', $actions );
		$this->assertArrayHasKey( 'Create a Page', $actions );
	}

	/**
	 * A subscriber can open no editor at all, so every card that leads to one is
	 * withheld and only the external report is left.
	 */
	public function test_editor_actions_are_withheld_without_the_capability() {
		$this->register_newsletter_post_type();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$actions = $this->get_quick_actions();

		$this->assertArrayNotHasKey( 'Start a New Post', $actions );
		$this->assertArrayNotHasKey( 'Draft a Newsletter', $actions );
		$this->assertArrayNotHasKey( 'Create a Page', $actions );
		$this->assertCount( 1, $actions );
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
		$this->assertSame( admin_url( 'admin.php?page=newspack-insights' ), $actions['Explore Insights']['href'] );
	}

	/**
	 * Under a parent carrying a query string, `menu_page_url()` returns an
	 * entity-encoded ampersand, and `wp_localize_script()` leaves values nested
	 * inside `quickActions` alone. The href still has to reach the browser usable.
	 */
	public function test_insights_href_survives_a_parent_with_a_query_string() {
		add_submenu_page( 'edit.php?post_type=page', 'Insights', 'Insights', 'manage_options', 'newspack-insights', '__return_null' );

		$actions = $this->get_quick_actions();

		$this->assertArrayHasKey( 'Explore Insights', $actions );
		$this->assertSame(
			admin_url( 'edit.php?post_type=page&page=newspack-insights' ),
			$actions['Explore Insights']['href']
		);
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
