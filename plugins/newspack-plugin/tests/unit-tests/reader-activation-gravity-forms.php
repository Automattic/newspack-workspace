<?php
/**
 * Tests the Gravity Forms integration: the block toggle, its requirement, and
 * what it keeps apart from Form Capture.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Integrations\Form_Capture;
use Newspack\Reader_Activation\Integrations\Gravity_Forms;
use Newspack\Reader_Registration;

if ( ! class_exists( 'GFForms' ) ) {
	require_once dirname( __DIR__ ) . '/mocks/gravityforms-mock.php';
}

/**
 * Test the Gravity Forms integration.
 *
 * @group form-capture
 */
class Test_Gravity_Forms_Capture extends WP_UnitTestCase {

	/**
	 * Whether a test replaced the `plugins` cache to fake Gravity Forms' state.
	 *
	 * @var bool
	 */
	private $plugins_cache_dirty = false;

	/**
	 * The `active_plugins` option before a test faked Gravity Forms' state.
	 *
	 * @var array|null
	 */
	private $original_active_plugins = null;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		update_option( Reader_Activation::OPTIONS_PREFIX . 'enabled', true );
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		Integrations::disable( Gravity_Forms::ID );
		delete_option( Reader_Activation::OPTIONS_PREFIX . 'enabled' );
		remove_all_filters( 'newspack_reader_activation_enabled' );
		if ( $this->plugins_cache_dirty ) {
			\wp_cache_delete( 'plugins', 'plugins' );
			$this->plugins_cache_dirty = false;
		}
		if ( null !== $this->original_active_plugins ) {
			\update_option( 'active_plugins', $this->original_active_plugins );
			$this->original_active_plugins = null;
		}
		\Newspack\Plugin_Manager::reset_managed_plugin_status_cache();
		parent::tear_down();
	}

	/**
	 * Gravity Forms is there on every site, while Form Capture, for
	 * forms built with other tools, registers behind its flag or where the site
	 * already enabled it, so an upgrade leaves that site capturing.
	 *
	 * @dataProvider data_form_capture_registration
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param bool $flag    Whether the site defines the flag.
	 * @param bool $enabled Whether the site already enabled Form Capture.
	 */
	public function test_form_capture_registers_behind_its_flag_or_where_enabled( $flag, $enabled ) {
		$this->assertInstanceOf( Gravity_Forms::class, Integrations::get_integration( Gravity_Forms::ID ) );
		$this->assertNull( Integrations::get_integration( Form_Capture::ID ), 'Absent on a site with neither.' );

		if ( $flag ) {
			define( 'NEWSPACK_INBOUND_FORM_CAPTURE_ENABLED', true );
		}
		if ( $enabled ) {
			update_option( Integrations::OPTION_NAME, [ Form_Capture::ID ] );
		}
		Integrations::register_integrations();
		$this->assertInstanceOf( Form_Capture::class, Integrations::get_integration( Form_Capture::ID ) );
	}

	/**
	 * The two ways Form Capture registers.
	 *
	 * @return array[]
	 */
	public function data_form_capture_registration() {
		return [
			'behind the flag'                   => [ true, false ],
			'where the site already enabled it' => [ false, true ],
		];
	}

	/**
	 * Fake Gravity Forms' install state the way Plugin_Manager reads it: the
	 * `plugins` cache decides installed, the `active_plugins` option decides
	 * active. Mirrors the ESP tests' newsletters stub.
	 *
	 * @param string $status One of 'active', 'inactive', 'uninstalled'.
	 */
	private function stub_gravity_forms_status( $status ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_file = 'gravityforms/gravityforms.php';
		if ( null === $this->original_active_plugins ) {
			$this->original_active_plugins = \get_option( 'active_plugins', [] );
		}
		$plugins = \get_plugins();
		if ( 'uninstalled' === $status ) {
			unset( $plugins[ $plugin_file ] );
		} else {
			$plugins[ $plugin_file ] = [
				'Name'    => 'Gravity Forms',
				'Version' => '3.1.0',
			];
		}
		\wp_cache_set( 'plugins', [ '' => $plugins ], 'plugins' );
		$this->plugins_cache_dirty = true;
		\update_option( 'active_plugins', 'active' === $status ? [ $plugin_file ] : [] );
		\Newspack\Plugin_Manager::reset_managed_plugin_status_cache();
	}

	/**
	 * Gravity Forms is the way in, so the card names it as a requirement in
	 * every install state the Integrations UI distinguishes: absent
	 * ("Requires Gravity Forms"), installed but inactive (Activate), active.
	 */
	public function test_requires_gravity_forms() {
		$this->stub_gravity_forms_status( 'uninstalled' );
		$required = ( new Gravity_Forms() )->get_required_plugins();
		$this->assertCount( 1, $required );
		$this->assertSame( 'gravityforms', $required[0]['slug'] );
		$this->assertSame( 'Gravity Forms', $required[0]['name'] );
		$this->assertFalse( $required[0]['is_active'] );
		$this->assertFalse( $required[0]['is_installed'] );

		$this->stub_gravity_forms_status( 'inactive' );
		$required = ( new Gravity_Forms() )->get_required_plugins();
		$this->assertFalse( $required[0]['is_active'] );
		$this->assertTrue( $required[0]['is_installed'] );

		$this->stub_gravity_forms_status( 'active' );
		$required = ( new Gravity_Forms() )->get_required_plugins();
		$this->assertTrue( $required[0]['is_active'] );
		$this->assertTrue( $required[0]['is_installed'] );
	}

	/**
	 * The how-to travels in the integrations payload for the card's How it
	 * works guide, its last step linking the help page that covers forms
	 * placed without the block. The integration declares no settings, so the
	 * card offers no settings page.
	 */
	public function test_guide_in_payload_and_no_settings() {
		$integration = Integrations::get_integration( Gravity_Forms::ID );
		$guide       = $integration->get_guide();
		$this->assertCount( 3, $guide );
		foreach ( $guide as $step ) {
			$this->assertNotEmpty( $step['title'] );
			$this->assertNotEmpty( $step['description'] );
		}
		$last_step = end( $guide );
		$this->assertNotEmpty( $last_step['link']['label'] );
		$this->assertStringStartsWith( 'https://help.newspack.com/', $last_step['link']['url'] );

		$payload = Integrations::get_all_integration_settings()[ Gravity_Forms::ID ];
		$this->assertSame( 'Gravity Forms', $payload['name'] );
		$this->assertSame( $guide, $payload['guide'] );
		$this->assertSame( [], $payload['settings'] );
	}

	/**
	 * The block toggle reaches the page as the marker class: the render filter
	 * adds it to the form tag only when the attribute is set and leaves every
	 * other placement untouched, whether or not the integration is enabled.
	 * The attribute is also registered with Gravity Forms, whose block preview
	 * validates attributes against the server schema and would otherwise
	 * refuse every toggled block.
	 */
	public function test_block_attribute_marks_the_form() {
		$integration = new Gravity_Forms();
		$integration->register_handlers();
		$this->assertSame( 10, has_filter( 'gform_form_block_attributes', [ $integration, 'register_block_attribute' ] ) );
		$this->assertSame( 20, has_filter( 'render_block_gravityforms/form', [ $integration, 'mark_captured_block_form' ] ), 'Runs after GF\'s own render filter at 10.' );

		$attributes = $integration->register_block_attribute( [ 'formId' => [ 'type' => 'string' ] ] );
		$this->assertSame( [ 'type' => 'string' ], $attributes['formId'], 'GF\'s own attributes pass through.' );
		$this->assertSame( 'boolean', $attributes[ Gravity_Forms::BLOCK_ATTRIBUTE ]['type'] );
		$this->assertFalse( $attributes[ Gravity_Forms::BLOCK_ATTRIBUTE ]['default'] );

		$html = "<div class='gform_wrapper gravity-theme'><form method='post' id='gform_1' class='signup' action='/' data-formid='1' novalidate><input type='email' name='input_1'></form></div>";
		$on   = [
			'blockName' => 'gravityforms/form',
			'attrs'     => [
				'formId'                       => '1',
				Gravity_Forms::BLOCK_ATTRIBUTE => true,
			],
		];
		$off  = [
			'blockName' => 'gravityforms/form',
			'attrs'     => [ 'formId' => '1' ],
		];

		$this->assertFalse( Integrations::is_enabled( Gravity_Forms::ID ), 'Marking must not depend on the enabled state.' );
		$marked = $integration->mark_captured_block_form( $html, $on );
		$this->assertSame( $marked, apply_filters( 'render_block_gravityforms/form', $html, $on ), 'The hook passes both arguments through.' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Gravity Forms' block name.
		$this->assertMatchesRegularExpression( '/<form[^>]*class=["\'][^"\']*\bnewspack-form-capture\b/', $marked, 'The form tag carries the marker.' );
		$this->assertStringContainsString( 'signup', $marked, 'Existing classes are kept.' );
		$this->assertSame( 1, substr_count( $marked, Gravity_Forms::MARKER_CLASS ), 'Only the form tag is marked, not the wrapper.' );

		$this->assertSame( $html, $integration->mark_captured_block_form( $html, $off ), 'An untoggled placement is untouched.' );
		$this->assertSame( $html, $integration->mark_captured_block_form( $html, [ 'attrs' => [ Gravity_Forms::BLOCK_ATTRIBUTE => false ] ] ) );
		$this->assertSame( '<p>No form here.</p>', $integration->mark_captured_block_form( '<p>No form here.</p>', $on ), 'Content without a form tag is returned as is.' );

		$bare = "<form method='post' id='gform_2' data-formid='2'></form>";
		$this->assertMatchesRegularExpression( '/<form[^>]*class=["\']newspack-form-capture["\']/', $integration->mark_captured_block_form( $bare, $on ), 'A form with no class attribute gets one.' );
	}

	/**
	 * The editor extension loads wherever the Gravity Forms block can be
	 * placed, whatever the state of the integration or of Reader Activation:
	 * the block toggle is how publishers find the feature, and it stays
	 * visible and saves while either is off. `active` tells the panel whether
	 * a toggled form registers anyone yet, and is off while Reader Activation
	 * is off.
	 */
	public function test_editor_script_loads_for_gravity_forms_editors() {
		$integration = new Gravity_Forms();
		$integration->register_handlers();
		$this->assertSame( 10, has_action( 'enqueue_block_editor_assets', [ $integration, 'enqueue_editor_assets' ] ) );

		$integration->enqueue_editor_assets();
		$this->assertTrue( wp_script_is( Gravity_Forms::EDITOR_SCRIPT_HANDLE, 'enqueued' ), 'Loads while the integration is disabled.' );
		$data = wp_scripts()->get_data( Gravity_Forms::EDITOR_SCRIPT_HANDLE, 'data' );
		// wp_localize_script() casts scalars to strings, so the editor reads
		// truthiness ("" or "1") rather than a boolean.
		$this->assertStringContainsString( '"active":""', $data );
		$this->assertStringContainsString( 'page=newspack-audience-integrations', $data );

		wp_dequeue_script( Gravity_Forms::EDITOR_SCRIPT_HANDLE );
		wp_deregister_script( Gravity_Forms::EDITOR_SCRIPT_HANDLE );
		Integrations::enable( Gravity_Forms::ID );
		$integration->enqueue_editor_assets();
		$this->assertStringContainsString( '"active":"1"', wp_scripts()->get_data( Gravity_Forms::EDITOR_SCRIPT_HANDLE, 'data' ) );

		wp_dequeue_script( Gravity_Forms::EDITOR_SCRIPT_HANDLE );
		wp_deregister_script( Gravity_Forms::EDITOR_SCRIPT_HANDLE );
		add_filter( 'newspack_reader_activation_enabled', '__return_false' );
		$integration->enqueue_editor_assets();
		$this->assertTrue( wp_script_is( Gravity_Forms::EDITOR_SCRIPT_HANDLE, 'enqueued' ), 'Must load while Reader Activation is off, so the toggle can still be set.' );
		$this->assertStringContainsString( '"active":""', wp_scripts()->get_data( Gravity_Forms::EDITOR_SCRIPT_HANDLE, 'data' ), 'Nothing registers readers with Reader Activation off.' );
	}

	/**
	 * Gravity Forms is checked at runtime too. The card requires it, and with
	 * it uninstalled the card offers no Disable, so a site that removed
	 * Gravity Forms after enabling must not keep registering readers behind
	 * that card.
	 */
	public function test_capture_stays_off_without_gravity_forms() {
		$integration = new class() extends Gravity_Forms {
			/**
			 * Stand in for a site without Gravity Forms: the suite loads its stub class.
			 *
			 * @return bool
			 */
			protected function is_gravity_forms_active() {
				return false;
			}
		};
		foreach ( [ Gravity_Forms::SCRIPT_HANDLE, Gravity_Forms::EDITOR_SCRIPT_HANDLE ] as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
		Integrations::enable( Gravity_Forms::ID );

		$this->assertFalse( $integration->supports_frontend_registration(), 'No key, endpoint or capture script without Gravity Forms.' );
		$integration->enqueue_scripts();
		$this->assertFalse( wp_script_is( Gravity_Forms::SCRIPT_HANDLE, 'enqueued' ), 'The capture script must not load.' );
		$integration->enqueue_editor_assets();
		$this->assertFalse( wp_script_is( Gravity_Forms::EDITOR_SCRIPT_HANDLE, 'enqueued' ), 'There is no block to extend.' );
		$this->assertTrue( Integrations::is_enabled( Gravity_Forms::ID ), 'The integration stays enabled, so capture resumes once Gravity Forms is back.' );
	}

	/**
	 * Registrations from Gravity Forms are the integration's own: their method
	 * and rate-limit bucket differ from Form Capture's, and the magic
	 * link suppression they get follows this integration's switch alone, so
	 * disabling one integration leaves the other's captures as they were.
	 */
	public function test_registrations_are_its_own() {
		$this->assertSame( 'integration-registration-gravity-forms', Gravity_Forms::get_registration_method() );

		$bucket = Reader_Registration::get_rate_limit_bucket_for( Gravity_Forms::ID );
		$this->assertSame( 'registration_gravity-forms', $bucket );
		$this->assertSame( Gravity_Forms::RATE_LIMIT_DEFAULT, apply_filters( 'newspack_frontend_registration_rate_limit', 10, '203.0.113.9', $bucket ) );

		$user                   = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
		$gravity_forms_metadata = [ 'registration_method' => Gravity_Forms::get_registration_method() ];
		$form_capture_metadata  = [ 'registration_method' => Form_Capture::get_registration_method() ];

		Integrations::enable( Gravity_Forms::ID );
		$this->assertFalse( apply_filters( 'newspack_reader_activation_send_magic_link_on_reregistration', true, $user, $gravity_forms_metadata ) );
		$this->assertTrue( apply_filters( 'newspack_reader_activation_send_magic_link_on_reregistration', true, $user, $form_capture_metadata ), 'Not a Gravity Forms registration.' );

		Integrations::disable( Gravity_Forms::ID );
		$this->assertTrue( apply_filters( 'newspack_reader_activation_send_magic_link_on_reregistration', true, $user, $gravity_forms_metadata ), 'Suppression stops with the integration.' );
	}

	/**
	 * Form Capture captured Gravity Forms forms before the split, and
	 * those forms belong to this integration now, so a site upgrading with it
	 * enabled and Gravity Forms active gets this integration enabled. Once: a
	 * later Disable sticks.
	 */
	public function test_upgrade_enables_it_where_form_capture_captured_its_forms() {
		delete_option( Gravity_Forms::UPGRADE_OPTION );
		update_option( Integrations::OPTION_NAME, [ Form_Capture::ID ] );

		Integrations::register_integrations();
		$this->assertTrue( Integrations::is_enabled( Gravity_Forms::ID ) );

		Integrations::disable( Gravity_Forms::ID );
		Integrations::register_integrations();
		$this->assertFalse( Integrations::is_enabled( Gravity_Forms::ID ), 'A later Disable sticks.' );
	}

	/**
	 * The upgrade leaves every other site alone: without Gravity Forms active
	 * there were no Gravity Forms forms to capture, and a site that enables
	 * Form Capture after the upgrade picks its integrations itself.
	 */
	public function test_upgrade_leaves_other_sites_alone() {
		$without_gravity_forms = new class() extends Gravity_Forms {
			/**
			 * Stand in for a site without Gravity Forms: the suite loads its stub class.
			 *
			 * @return bool
			 */
			protected function is_gravity_forms_active() {
				return false;
			}
		};
		delete_option( Gravity_Forms::UPGRADE_OPTION );
		update_option( Integrations::OPTION_NAME, [ Form_Capture::ID ] );
		$without_gravity_forms->maybe_enable_on_upgrade();
		$this->assertFalse( Integrations::is_enabled( Gravity_Forms::ID ), 'Not without Gravity Forms.' );

		$gravity_forms = Integrations::get_integration( Gravity_Forms::ID );
		delete_option( Gravity_Forms::UPGRADE_OPTION );
		update_option( Integrations::OPTION_NAME, [] );
		$gravity_forms->maybe_enable_on_upgrade();
		$this->assertFalse( Integrations::is_enabled( Gravity_Forms::ID ), 'Not without Form Capture.' );

		update_option( Integrations::OPTION_NAME, [ Form_Capture::ID ] );
		$gravity_forms->maybe_enable_on_upgrade();
		$this->assertFalse( Integrations::is_enabled( Gravity_Forms::ID ), 'Not for Form Capture enabled after the upgrade.' );
	}
}
