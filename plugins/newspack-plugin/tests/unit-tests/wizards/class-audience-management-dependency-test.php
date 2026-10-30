<?php
/**
 * Tests for the Audience Management prerequisite shared by the content gate wizards.
 *
 * NPPD-1846 — Audience Management is a hard prerequisite for gating. The admin
 * screens refuse to offer gate creation without it; these tests cover the REST
 * guard behind those screens, so a stale browser tab cannot POST a gate into
 * existence that no reader could ever satisfy.
 *
 * Note on the disabled state: this used to be unsimulatable, because
 * Reader_Activation::is_enabled() short-circuited to true under IS_TEST_ENV
 * before applying its `newspack_reader_activation_enabled` filter. That default
 * now applies before the filter rather than instead of it, so the disabled path
 * is reachable from tests: `add_filter( 'newspack_reader_activation_enabled',
 * '__return_false' )` works, as {@see Gating_Inertness_Test} relies on.
 *
 * The subclass doubles below predate that and override the single delegating
 * method rather than driving the real one, which makes their disabled
 * assertions weaker than they need to be — they would hold against a stubbed
 * method body. Worth retiring in favour of the filter next time this file is
 * touched.
 *
 * @package Newspack\Tests
 */

use Newspack\Audience_Content_Gates;
use Newspack\Premium_Newsletters_Wizard;

/**
 * Tests for the Audience_Management_Dependency trait as consumed by both wizards.
 */
class Audience_Management_Dependency_Test extends WP_UnitTestCase {

	/**
	 * The content gate feature is flag-gated, and register_api_endpoints() returns
	 * early without it. A constant cannot be unset once defined, which is fine —
	 * every test here wants the feature on.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Reset the current user, and the REST server that the route-wiring test
	 * registers routes on, so neither leaks into the rest of the process.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * Both wizards that surface the content gate editor, each with a factory for the
	 * same wizard with Audience Management forced off.
	 *
	 * The doubles are anonymous classes built on demand rather than named subclasses,
	 * so this file declares exactly one class as the coding standard requires.
	 *
	 * @return array<string, array{0: string, 1: callable, 2: string}>
	 */
	public function wizard_provider() {
		return [
			'Access Control'      => [
				Audience_Content_Gates::class,
				function () {
					return new class() extends Audience_Content_Gates {
						/**
						 * Report Audience Management as not set up.
						 *
						 * @return bool
						 */
						public function has_audience_management(): bool {
							return false;
						}
					};
				},
				'newspack-audience-access-control',
			],
			'Premium Newsletters' => [
				Premium_Newsletters_Wizard::class,
				function () {
					return new class() extends Premium_Newsletters_Wizard {
						/**
						 * Report Audience Management as not set up.
						 *
						 * @return bool
						 */
						public function has_audience_management(): bool {
							return false;
						}
					};
				},
				'newspack-premium-newsletters',
			],
		];
	}

	/**
	 * The routes that bring new gating into existence must use the guarded
	 * permission callback, and the corrective routes must not.
	 *
	 * This is the assertion the shared trait cannot make for itself: reverting a
	 * single `permission_callback` token back to `api_permissions_check` removes
	 * the entire server-side guard while leaving every other test green.
	 *
	 * @dataProvider wizard_provider
	 *
	 * @param string   $wizard_class      The wizard under test.
	 * @param callable $unused_am_off     Unused here; supplied by the shared provider.
	 * @param string   $slug              The wizard's REST slug.
	 */
	public function test_creation_routes_use_the_guarded_permission_callback( $wizard_class, $unused_am_off, $slug ) {
		$wizard = new $wizard_class();
		// Registered through the action rather than by calling the method directly:
		// register_rest_route() emits a doing-it-wrong notice off `rest_api_init`.
		// The wizard is hooked explicitly here because its own constructor hook does
		// not fire for Premium_Newsletters_Wizard in the unit env, whose constructor
		// bails while NEWSPACK_NEWSLETTERS_PLUGIN_FILE is undefined.
		add_action( 'rest_api_init', [ $wizard, 'register_api_endpoints' ] );
		do_action( 'rest_api_init', rest_get_server() );
		remove_action( 'rest_api_init', [ $wizard, 'register_api_endpoints' ] );

		$registered      = rest_get_server()->get_routes();
		$guarded_methods = [];

		foreach ( $registered as $route => $handlers ) {
			if ( 0 !== strpos( $route, '/newspack/v1/wizard/' . $slug ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				$callback = $handler['permission_callback'][1] ?? null;
				$methods  = implode( ',', array_keys( array_filter( $handler['methods'] ) ) );
				$guarded_methods[ $route . ' [' . $methods . ']' ] = $callback;
			}
		}

		$this->assertNotEmpty( $guarded_methods, 'Expected the wizard to register REST routes.' );

		// Gate creation and duplication are the two routes that bring new gating
		// into existence, so both must carry the Audience Management guard.
		$create_route    = '/newspack/v1/wizard/' . $slug . ' [POST]';
		$duplicate_route = '/newspack/v1/wizard/' . $slug . '/(?P<id>\d+)/duplicate [POST]';
		$this->assertSame( 'api_permissions_check_audience_management', $guarded_methods[ $create_route ] ?? null );
		$this->assertSame( 'api_permissions_check_audience_management', $guarded_methods[ $duplicate_route ] ?? null );

		// Deletion stays on the plain capability check on purpose: blocking it would
		// strand a pre-existing gate's live restrictions with no way to lift them.
		$delete_route = '/newspack/v1/wizard/' . $slug . '/(?P<id>\d+) [DELETE]';
		$this->assertSame( 'api_permissions_check', $guarded_methods[ $delete_route ] ?? null );
	}

	/**
	 * With Audience Management on, an administrator may create a gate. This runs
	 * against the real Reader_Activation rather than a subclass.
	 *
	 * @dataProvider wizard_provider
	 *
	 * @param string $wizard_class The wizard under test.
	 */
	public function test_creation_permitted_with_audience_management( $wizard_class ) {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$wizard = new $wizard_class();

		$this->assertTrue( $wizard->has_audience_management() );
		$this->assertTrue(
			$wizard->api_permissions_check_audience_management( new WP_REST_Request() ),
			'An administrator should be able to create a gate when Audience Management is set up.'
		);
	}

	/**
	 * With Audience Management off, gate creation is refused even for an
	 * administrator who passes the capability check.
	 *
	 * @dataProvider wizard_provider
	 *
	 * @param string   $wizard_class            The wizard under test.
	 * @param callable $build_wizard_without_am Builds the same wizard with Audience Management off.
	 */
	public function test_creation_blocked_without_audience_management( $wizard_class, $build_wizard_without_am ) {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$wizard = $build_wizard_without_am();

		$result = $wizard->api_permissions_check_audience_management( new WP_REST_Request() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'newspack_audience_management_required', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The capability check runs first. Asserted on the both-fail case — an
	 * unprivileged user on a site that also lacks the prerequisite — because that
	 * is the only scenario where the ordering is observable: whichever check runs
	 * first determines the error code. Getting this backwards would report a
	 * capability problem as a site-configuration problem.
	 *
	 * @dataProvider wizard_provider
	 *
	 * @param string   $wizard_class            The wizard under test.
	 * @param callable $build_wizard_without_am Builds the same wizard with Audience Management off.
	 */
	public function test_capability_check_precedes_prerequisite( $wizard_class, $build_wizard_without_am ) {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'subscriber' ] ) );
		$wizard = $build_wizard_without_am();

		$result = $wizard->api_permissions_check_audience_management( new WP_REST_Request() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'newspack_rest_forbidden', $result->get_error_code() );
	}

	/**
	 * A truthy non-true verdict from the capability check means "permitted" to core,
	 * so it must still fall through to the prerequisite check. Comparing against
	 * `true` alone would hand that verdict straight back to core as "allowed" and
	 * skip the prerequisite entirely — the wrong direction for a guard to fail, and
	 * invisible without this test.
	 */
	public function test_truthy_capability_verdict_still_reaches_the_prerequisite() {
		// A capability check returning a truthy non-true value, as a custom
		// permission callback legitimately may.
		$wizard_with_truthy_capability_check = new class() extends Audience_Content_Gates {
			/**
			 * Report Audience Management as not set up.
			 *
			 * @return bool
			 */
			public function has_audience_management(): bool {
				return false;
			}

			/**
			 * Return a truthy non-true verdict, which core reads as permitted.
			 *
			 * @param \WP_REST_Request $request API request object.
			 *
			 * @return int
			 */
			public function api_permissions_check( $request ) {
				return 1;
			}
		};

		$result = $wizard_with_truthy_capability_check->api_permissions_check_audience_management( new WP_REST_Request() );
		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'A truthy capability verdict must still be subject to the Audience Management check.'
		);
		$this->assertSame( 'newspack_audience_management_required', $result->get_error_code() );
	}

	/**
	 * The localized config carries the prerequisite state to the admin screen,
	 * which renders its blocked state from these two keys — so a rename or an
	 * empty URL silently un-blocks the UI or strands the admin with a dead button.
	 *
	 * @dataProvider wizard_provider
	 *
	 * @param string   $wizard_class            The wizard under test.
	 * @param callable $build_wizard_without_am Builds the same wizard with Audience Management off.
	 */
	public function test_script_data_reports_prerequisite_state( $wizard_class, $build_wizard_without_am ) {
		$enabled = ( new $wizard_class() )->get_audience_management_script_data();
		$this->assertTrue( $enabled['audience_management_enabled'] );
		$this->assertStringContainsString(
			'page=newspack-audience',
			$enabled['audience_management_url'],
			'The prerequisite state links the admin to the Audience Management setup flow.'
		);

		$disabled = $build_wizard_without_am()->get_audience_management_script_data();
		// Strictly false, not merely falsy: this value is localized to the browser,
		// where wp_localize_script() would stringify an integer 0 to the truthy '0'.
		$this->assertFalse( $disabled['audience_management_enabled'] );
	}

	/**
	 * The Subscriptions screen carries the trait's keys into its own localized
	 * config, which is what its blocked state renders from.
	 *
	 * Its JS spec builds `newspackAudienceSubscriptions` itself, so nothing on
	 * that side would notice the wizard dropping the trait or shadowing its keys —
	 * both suites would stay green while the screen rendered unblocked.
	 *
	 * `enqueue_scripts_and_styles()` gates on `filter_input( INPUT_GET, … )`,
	 * which reads the SAPI's request data and cannot be faked from a test, so the
	 * merge direction is pinned by reading the source. The rest is structural.
	 */
	public function test_subscriptions_wizard_carries_the_prerequisite_into_its_config() {
		$subscriptions_wizard = new \Newspack\Audience_Subscriptions();

		$this->assertContains(
			\Newspack\Wizards\Traits\Audience_Management_Dependency::class,
			class_uses( $subscriptions_wizard ),
			'The Subscriptions screen depends on Audience Management, so it uses the shared trait.'
		);

		$script_data = $subscriptions_wizard->get_audience_management_script_data();
		$this->assertArrayHasKey( 'audience_management_enabled', $script_data );
		$this->assertArrayHasKey( 'audience_management_url', $script_data );

		// array_merge, not `+`: the trait has to win a key collision, or a key added
		// to the wizard's own config could shadow the prerequisite and unblock the
		// screen. The two operators differ only in that direction.
		$reflected_enqueue = new \ReflectionMethod( $subscriptions_wizard, 'enqueue_scripts_and_styles' );
		$wizard_source     = file_get_contents( $reflected_enqueue->getFileName() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		$this->assertMatchesRegularExpression(
			'/array_merge\(\s*\$data,\s*\$this->get_audience_management_script_data\(\)\s*\)/',
			$wizard_source,
			'The trait wins a key collision, so the prerequisite cannot be shadowed.'
		);
	}
}
