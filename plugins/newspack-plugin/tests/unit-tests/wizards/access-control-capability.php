<?php
/**
 * Tests who may use the Access Control admin screens.
 *
 * @package Newspack\Tests\Wizards
 */

namespace Newspack\Tests\Wizards;

use Newspack\Audience_Content_Gates;
use Newspack\Capabilities;
use Newspack\Institution;
use Newspack\Content_Gate\IP_Access_Rule;
use Newspack\Premium_Newsletters_Wizard;
use Newspack\Subscribers_Wizard;

/**
 * Store staff manage members, plans and their settings, as they could under
 * WooCommerce Memberships, so Access Control admits them wherever it admits
 * administrators. Administrators keep access on a site without WooCommerce,
 * where nobody holds `manage_woocommerce`. A surface left on `manage_options`
 * breaks a screen for store staff halfway through: the page loads and one
 * request fails.
 *
 * @group wizards
 * @group Access_Control_Capability
 */
class Test_Access_Control_Capability extends \WP_UnitTestCase {

	/**
	 * Route prefixes of the admin screens under test.
	 */
	const ROUTE_PREFIXES = [
		'/newspack/v1/wizard/newspack-subscribers',
		'/newspack/v1/wizard/newspack-audience-access-control',
		'/newspack/v1/wizard/newspack-premium-newsletters',
		'/newspack/v1/institutional-access/',
	];

	/**
	 * Label => user ID of each role under test.
	 *
	 * @var array<string, int>
	 */
	private array $users;

	/**
	 * Enable the content gates flag and load the WooCommerce mocks, which some
	 * routes' availability checks read.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Create the users and register every route on a fresh server. The wizards
	 * are only constructed where their admin is available, so their routes are
	 * registered directly.
	 */
	public function set_up() {
		parent::set_up();

		// A subscriber given only `manage_woocommerce`, like a custom store role.
		$store_staff = $this->factory->user->create_and_get( [ 'role' => 'subscriber' ] );
		$store_staff->add_cap( 'manage_woocommerce' );
		$this->users = [
			'administrator' => $this->factory->user->create( [ 'role' => 'administrator' ] ),
			'store staff'   => $store_staff->ID,
			'editor'        => $this->factory->user->create( [ 'role' => 'editor' ] ),
		];
		// The mocks don't install WooCommerce, so the administrator stands for one
		// on a site without it.
		$this->assertFalse( user_can( $this->users['administrator'], 'manage_woocommerce' ) );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		( new Subscribers_Wizard() )->register_api_endpoints();
		( new Audience_Content_Gates() )->register_api_endpoints();
		( new Premium_Newsletters_Wizard() )->register_api_endpoints();
		IP_Access_Rule::register_rest_route();
	}

	/**
	 * Reset the server and the menus.
	 */
	public function tear_down() {
		global $wp_rest_server, $menu, $submenu, $_wp_submenu_nopriv;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$menu               = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$submenu            = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_wp_submenu_nopriv = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Labels of the users for whom the callback returns true.
	 *
	 * @param callable $callback Called once per user, as that user.
	 *
	 * @return string[]
	 */
	private function users_for_whom( callable $callback ): array {
		$allowed = [];
		foreach ( $this->users as $label => $user_id ) {
			wp_set_current_user( $user_id );
			if ( true === $callback() ) {
				$allowed[] = $label;
			}
		}
		return $allowed;
	}

	/**
	 * Every route behind the screens admits administrators and store staff, and
	 * refuses an editor.
	 *
	 * Calls each handler's registered check rather than dispatching requests, so
	 * no route callback, which would need the full WooCommerce stack, runs.
	 */
	public function test_store_staff_can_use_every_admin_route() {
		$allowed = [];
		foreach ( rest_get_server()->get_routes() as $route => $route_handlers ) {
			$prefix_matches = array_filter( self::ROUTE_PREFIXES, fn( $prefix ) => str_starts_with( $route, $prefix ) );
			if ( empty( $prefix_matches ) ) {
				continue;
			}
			foreach ( $route_handlers as $route_handler ) {
				// The institutional access check readers' browsers call.
				if ( '__return_true' === $route_handler['permission_callback'] ) {
					continue;
				}
				$label             = $route . ' ' . implode( ',', array_keys( $route_handler['methods'] ) );
				$allowed[ $label ] = $this->users_for_whom(
					fn() => true === call_user_func( $route_handler['permission_callback'], new \WP_REST_Request() )
				);
			}
		}

		// One route from each class that registers routes, so a screen whose routes
		// failed to register can't pass by being absent.
		foreach ( [ 'newspack-subscribers/groups', 'newspack-audience-access-control/settings', 'newspack-premium-newsletters', 'institutional-access/ip-allowlist' ] as $route_suffix ) {
			$this->assertNotEmpty( preg_grep( '#' . preg_quote( $route_suffix, '#' ) . ' #', array_keys( $allowed ) ), $route_suffix );
		}
		foreach ( $allowed as $route => $labels ) {
			$this->assertSame( [ 'administrator', 'store staff' ], $labels, $route );
		}
	}

	/**
	 * The menu entries open for the same users, so store staff see the Audience
	 * menu with the screens they can use in it.
	 */
	public function test_store_staff_can_open_every_admin_page() {
		global $submenu;
		$wizards = [
			'newspack-subscribers'             => new Subscribers_Wizard(),
			'newspack-audience-access-control' => new Audience_Content_Gates(),
		];
		foreach ( $wizards as $slug => $wizard ) {
			// Core leaves out an entry the current user can't open.
			wp_set_current_user( $this->users['administrator'] );
			$wizard->add_page();
			$entry = current( array_filter( $submenu['newspack-audience'] ?? [], fn( $item ) => $slug === $item[2] ) );
			$this->assertNotEmpty( $entry, $slug );
			$this->assertSame( [ 'administrator', 'store staff' ], $this->users_for_whom( fn() => current_user_can( $entry[1] ) ), $slug );
		}
	}

	/**
	 * Institutions are created, edited and read in full from the Access control
	 * screen, through the post type's own capabilities.
	 */
	public function test_store_staff_can_manage_institutions() {
		$post_type   = get_post_type_object( Institution::POST_TYPE );
		$institution = $this->factory->post->create( [ 'post_type' => Institution::POST_TYPE ] );

		$this->assertSame( [ 'administrator', 'store staff' ], $this->users_for_whom( fn() => current_user_can( $post_type->cap->create_posts ) ) );
		$this->assertSame( [ 'administrator', 'store staff' ], $this->users_for_whom( fn() => current_user_can( 'edit_post', $institution ) ) );
		$this->assertSame( [ 'administrator', 'store staff' ], $this->users_for_whom( fn() => current_user_can( 'delete_post', $institution ) ) );
		$this->assertSame( [ 'administrator', 'store staff' ], $this->users_for_whom( fn() => 'stored rules' === Institution::redact_meta_for_unauthorized( 'stored rules' ) ) );
	}

	/**
	 * A role editor can withhold Access Control from store staff: a value stored
	 * on the role wins over the grant.
	 */
	public function test_stored_denial_withholds_access_control() {
		$store_staff = get_userdata( $this->users['store staff'] );
		$store_staff->add_cap( Capabilities::MANAGE_ACCESS_CONTROL, false );

		$this->assertSame( [ 'administrator' ], $this->users_for_whom( fn() => current_user_can( Capabilities::MANAGE_ACCESS_CONTROL ) ) );
	}
}
