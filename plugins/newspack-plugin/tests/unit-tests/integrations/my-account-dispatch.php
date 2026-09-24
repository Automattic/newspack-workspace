<?php
/**
 * Tests integration My Account dispatch without WooCommerce.
 *
 * @package Newspack\Tests
 */

use Newspack\My_Account;
use Newspack\Reader_Activation\Integrations;

/**
 * Test integration My Account dispatch without WooCommerce.
 *
 * Exercises the native (WooCommerce-absent) dispatch path:
 * Integrations::filter_native_my_account_endpoints() and
 * Integrations::render_native_my_account_content().
 *
 * @group reader-activation
 */
class Newspack_Test_Integration_My_Account_Dispatch extends WP_UnitTestCase {
	/**
	 * Set up: clean integration state so each test starts from a known map.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Integrations::OPTION_NAME );
		delete_option( Integrations::MY_ACCOUNT_ENDPOINTS_OPTION );
		$this->reset_integrations();
		$this->reset_my_account_endpoints();
		Sample_Integration::reset();
	}

	/**
	 * Tear down: recover core integrations for subsequent tests.
	 */
	public function tear_down() {
		Integrations::register_integrations();
		parent::tear_down();
	}

	/**
	 * Reset integrations registry via reflection.
	 */
	private function reset_integrations() {
		$reflection = new \ReflectionClass( Integrations::class );
		$property   = $reflection->getProperty( 'integrations' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * Reset the private $my_account_endpoints map between tests.
	 */
	private function reset_my_account_endpoints() {
		$reflection = new \ReflectionClass( Integrations::class );
		$property   = $reflection->getProperty( 'my_account_endpoints' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * Register an active Sample_Integration with the given ID and menu item.
	 *
	 * @param string     $id   Integration ID.
	 * @param array|null $item Menu item declaration or null.
	 * @return Sample_Integration
	 */
	private function register_active_integration_with_menu( $id, $item ) {
		$integration                       = new Sample_Integration( $id, ucfirst( $id ) );
		$integration->my_account_menu_item = $item;
		Integrations::register( $integration );
		Integrations::enable( $id );
		return $integration;
	}

	/**
	 * Integration-declared endpoints appear in My_Account::get_endpoints()
	 * via the newspack_my_account_endpoints filter.
	 */
	public function test_integration_endpoint_contributed() {
		add_filter(
			'newspack_my_account_endpoints',
			function ( $endpoints ) {
				$endpoints['newsletters'] = 'Newsletters';
				return $endpoints;
			}
		);
		$this->assertArrayHasKey( 'newsletters', My_Account::get_endpoints() );
	}

	/**
	 * The filter_native_my_account_endpoints() method contributes the integration's
	 * slug => label to the endpoint map.
	 */
	public function test_filter_native_endpoints_includes_integration_slug() {
		$this->register_active_integration_with_menu(
			'newsletters',
			[
				'slug'  => 'newsletters',
				'label' => 'Newsletters',
			]
		);

		$endpoints = Integrations::filter_native_my_account_endpoints( [] );

		$this->assertArrayHasKey( 'newsletters', $endpoints );
		$this->assertSame( 'Newsletters', $endpoints['newsletters'] );
	}

	/**
	 * The dashboard (empty endpoint) is a no-op: it must produce no output and
	 * must not invoke any integration's render method.
	 */
	public function test_render_native_content_dashboard_is_noop() {
		$integration = $this->register_active_integration_with_menu(
			'newsletters',
			[
				'slug'  => 'newsletters',
				'label' => 'Newsletters',
			]
		);
		Integrations::register_my_account_endpoints();

		ob_start();
		Integrations::render_native_my_account_content( '' );
		$output = ob_get_clean();

		$this->assertSame( '', $output, 'Dashboard should produce no output.' );
		$this->assertSame( [], $integration->my_account_render_calls, 'Dashboard should not dispatch to an integration.' );
	}

	/**
	 * A core endpoint not owned by any integration is a no-op: no output and no
	 * integration render call.
	 */
	public function test_render_native_content_core_endpoint_is_noop() {
		$integration = $this->register_active_integration_with_menu(
			'newsletters',
			[
				'slug'  => 'newsletters',
				'label' => 'Newsletters',
			]
		);
		Integrations::register_my_account_endpoints();

		ob_start();
		Integrations::render_native_my_account_content( 'edit-account' );
		$output = ob_get_clean();

		$this->assertSame( '', $output, 'Core endpoint should produce no output.' );
		$this->assertSame( [], $integration->my_account_render_calls, 'Core endpoint should not dispatch to an integration.' );
	}

	/**
	 * When an integration owns the current slug, the dispatch invokes that
	 * integration's render_my_account_page().
	 */
	public function test_render_native_content_dispatches_to_owning_integration() {
		$integration = $this->register_active_integration_with_menu(
			'newsletters',
			[
				'slug'  => 'newsletters',
				'label' => 'Newsletters',
			]
		);
		Integrations::register_my_account_endpoints();

		Integrations::render_native_my_account_content( 'newsletters' );

		$this->assertSame(
			[ '' ],
			$integration->my_account_render_calls,
			'Owning integration render_my_account_page() should be invoked once.'
		);
	}
}
