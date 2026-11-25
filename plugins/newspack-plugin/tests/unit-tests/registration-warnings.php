<?php
/**
 * Tests for the Registration_Warnings class.
 *
 * @package Newspack\Tests
 */

use Newspack\Registration_Warnings;

/**
 * Class Test_Registration_Warnings
 *
 * @group registration-warnings
 */
class Test_Registration_Warnings extends WP_UnitTestCase {
	/**
	 * Roles added by a test, removed in tear_down().
	 *
	 * @var string[]
	 */
	private $added_roles = [];

	/**
	 * Capabilities granted to existing roles during the test, as [ role, capability ] pairs.
	 *
	 * @var array[]
	 */
	private $granted_caps = [];

	/**
	 * Start every test with registration closed, the core default role and reCAPTCHA off.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( 'users_can_register' );
		update_option( 'default_role', 'subscriber' );
		delete_option( 'newspack_recaptcha_use_captcha' );
		delete_option( 'newspack_recaptcha_version' );
		delete_option( 'newspack_recaptcha_credentials' );
	}

	/**
	 * Remove roles added and capabilities granted by the test.
	 */
	public function tear_down() {
		foreach ( $this->added_roles as $role ) {
			remove_role( $role );
		}
		$this->added_roles = [];
		foreach ( $this->granted_caps as list( $role, $cap ) ) {
			get_role( $role )->remove_cap( $cap );
		}
		$this->granted_caps = [];
		wp_dequeue_script( 'newspack-registration-warnings' );
		wp_deregister_script( 'newspack-registration-warnings' );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Sign in as a user with the given role.
	 *
	 * @param string $role Role slug.
	 */
	private function sign_in_as( $role ) {
		wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
	}

	/**
	 * Capture the Settings → General notices.
	 *
	 * @return string
	 */
	private function render_notices() {
		ob_start();
		Registration_Warnings::render_settings_notices();
		return ob_get_clean();
	}

	/**
	 * Add a role for this test only.
	 *
	 * @param string $role         Role slug.
	 * @param string $display_name Role display name.
	 * @param array  $caps         Capabilities.
	 */
	private function add_test_role( $role, $display_name, $caps = [] ) {
		// A role that already exists, such as the plugin's own guest contributor role, stays after the test.
		if ( add_role( $role, $display_name, $caps ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role
			$this->added_roles[] = $role;
		}
	}

	/**
	 * Give an existing role a capability for this test only.
	 *
	 * @param string $role Role slug.
	 * @param string $cap  Capability.
	 */
	private function grant_cap( $role, $cap ) {
		$role_object = get_role( $role );
		if ( ! $role_object->has_cap( $cap ) ) {
			$role_object->add_cap( $cap );
			$this->granted_caps[] = [ $role, $cap ];
		}
	}

	/**
	 * Configure valid reCAPTCHA v3 credentials so can_use_captcha() returns true.
	 */
	private function enable_recaptcha() {
		update_option( 'newspack_recaptcha_use_captcha', true );
		update_option( 'newspack_recaptcha_version', 'v3' );
		update_option(
			'newspack_recaptcha_credentials',
			[
				'v3' => [
					'site_key'    => 'test-site-key',
					'site_secret' => 'test-site-secret',
				],
			]
		);
	}

	/**
	 * Put reCAPTCHA in one of the states the warnings tell apart.
	 *
	 * @param string|false $state False for off, "keys" for set up, "no-keys" for switched on without credentials.
	 */
	private function set_recaptcha( $state ) {
		if ( 'keys' === $state ) {
			$this->enable_recaptcha();
		} elseif ( 'no-keys' === $state ) {
			update_option( 'newspack_recaptcha_use_captcha', true );
		}
	}

	/**
	 * Open registration with the given default role.
	 *
	 * @param string $role Role slug.
	 */
	private function open_registration( $role ) {
		update_option( 'users_can_register', 1 );
		update_option( 'default_role', $role );
	}

	/**
	 * Make the saved default role one that doesn't exist. WordPress resets the
	 * option to Subscriber when the role is saved or removed through its API,
	 * so this stands in for a role deleted some other way, such as a role
	 * editor rewriting the roles option directly.
	 *
	 * @param string $role Missing role slug.
	 */
	private function point_default_role_at_missing_role( $role ) {
		add_filter(
			'pre_option_default_role',
			function () use ( $role ) {
				return $role;
			}
		);
	}

	/**
	 * With the box unchecked, neither warning applies, whatever the role and reCAPTCHA state.
	 */
	public function test_closed_registration_is_quiet() {
		update_option( 'default_role', 'administrator' );
		$this->assertFalse( Registration_Warnings::is_open() );
		$this->assertFalse( Registration_Warnings::has_risky_default_role() );
		$this->assertFalse( Registration_Warnings::is_missing_bot_protection() );
	}

	/**
	 * Core roles that can edit posts.
	 */
	public function data_risky_roles() {
		return [
			[ 'administrator' ],
			[ 'editor' ],
			[ 'author' ],
			[ 'contributor' ],
		];
	}

	/**
	 * Open registration with a role that can edit posts warns.
	 *
	 * @dataProvider data_risky_roles
	 * @param string $role Role slug.
	 */
	public function test_risky_default_roles_warn( $role ) {
		$this->open_registration( $role );
		$this->assertTrue( Registration_Warnings::has_risky_default_role() );
	}

	/**
	 * Roles that cannot edit posts, built the way their owners register them.
	 */
	public function data_safe_roles() {
		return [
			'subscriber'          => [ 'subscriber', null, [] ],
			'customer'            => [ 'customer', 'Customer', [ 'read' => true ] ],
			'network_reader'      => [ 'network_reader', 'Network Reader', [] ],
			'contributor_no_edit' => [ 'contributor_no_edit', 'Guest Contributor', [ 'edit_cap_posts' => true ] ],
		];
	}

	/**
	 * Open registration with a role that cannot edit posts stays quiet.
	 *
	 * @dataProvider data_safe_roles
	 * @param string      $role         Role slug.
	 * @param string|null $display_name Display name to add the role with, or null for a core role.
	 * @param array       $caps         Capabilities.
	 */
	public function test_safe_default_roles_are_quiet( $role, $display_name, $caps ) {
		if ( null !== $display_name ) {
			$this->add_test_role( $role, $display_name, $caps );
		}
		$this->open_registration( $role );
		$this->assertFalse( Registration_Warnings::has_risky_default_role() );
	}

	/**
	 * A default role that no longer exists is treated as safe.
	 */
	public function test_missing_role_is_quiet() {
		$this->open_registration( 'subscriber' );
		$this->point_default_role_at_missing_role( 'gone_role' );
		$this->assertSame( 'gone_role', get_option( 'default_role' ) );
		$this->assertFalse( Registration_Warnings::has_risky_default_role() );
		$this->assertNull( Registration_Warnings::get_role_warning( 'gone_role' ) );
	}

	/**
	 * A custom role with edit_posts gets the generic copy, naming the role.
	 */
	public function test_custom_role_with_edit_posts_uses_generic_copy() {
		$this->add_test_role( 'staff_writer', 'Staff Writer', [ 'edit_posts' => true ] );
		$this->open_registration( 'staff_writer' );
		$this->assertTrue( Registration_Warnings::has_risky_default_role() );

		$warning = Registration_Warnings::get_role_warning( 'staff_writer' );
		$this->assertSame( 'Anyone can register with the "Staff Writer" role', $warning['title'] );
		$this->assertSame( 'This role can write posts. With "Anyone can register" checked, anyone who registers will have this ability, including spammers. Uncheck "Anyone can register", or choose Subscriber unless the users who sign up here need to write posts.', $warning['body'] );
	}

	/**
	 * Capabilities that let a custom role publish or upload files.
	 */
	public function data_custom_publishing_roles() {
		return [
			'publishes' => [ array_fill_keys( [ 'edit_posts', 'publish_posts' ], true ) ],
			'uploads'   => [ array_fill_keys( [ 'edit_posts', 'upload_files' ], true ) ],
		];
	}

	/**
	 * A custom role that can publish or upload files gets the publishing copy.
	 *
	 * @dataProvider data_custom_publishing_roles
	 * @param array $caps Capabilities.
	 */
	public function test_custom_role_that_publishes_or_uploads_uses_publishing_copy( $caps ) {
		$this->add_test_role( 'staff_publisher', 'Staff Publisher', $caps );
		$warning = Registration_Warnings::get_role_warning( 'staff_publisher' );
		$this->assertSame( 'Anyone can register with the "Staff Publisher" role', $warning['title'] );
		$this->assertSame( 'This role can publish posts or upload files. With "Anyone can register" checked, anyone who registers will have these abilities, including spammers. Uncheck "Anyone can register", or choose Subscriber unless the users who sign up here need to publish posts.', $warning['body'] );
	}

	/**
	 * Each core role's title and body, as approved.
	 */
	public function data_core_role_copy() {
		return [
			'contributor'   => [
				'contributor',
				'Anyone can register as a Contributor',
				'Contributors can write posts for review. With "Anyone can register" checked, anyone who registers will have this ability, including spammers. Uncheck "Anyone can register", or choose Subscriber unless the users who sign up here need to write posts.',
			],
			'author'        => [
				'author',
				'Anyone can register as an Author',
				'Authors can publish posts without approval and upload files. With "Anyone can register" checked, anyone who registers will have these abilities, including spammers. Uncheck "Anyone can register", or choose Subscriber unless the users who sign up here need to publish posts.',
			],
			'editor'        => [
				'editor',
				'Anyone can register as an Editor',
				'Editors can publish and change any post. With "Anyone can register" checked, anyone who registers will have these abilities, including spammers. Uncheck "Anyone can register", or choose Subscriber.',
			],
			'administrator' => [
				'administrator',
				'Anyone can register as an Administrator',
				'Administrators can change any setting, add users and install plugins. With "Anyone can register" checked, anyone who registers will have these abilities, including spammers. Uncheck "Anyone can register", or choose Subscriber.',
			],
		];
	}

	/**
	 * Core roles get their own title and body.
	 *
	 * @dataProvider data_core_role_copy
	 * @param string $role  Role slug.
	 * @param string $title Expected title.
	 * @param string $body  Expected body.
	 */
	public function test_core_role_copy( $role, $title, $body ) {
		$warning = Registration_Warnings::get_role_warning( $role );
		$this->assertSame( $title, $warning['title'] );
		$this->assertSame( $body, $warning['body'] );
	}

	/**
	 * A site that lets Contributors publish or upload gets the red warning,
	 * with the publishing copy under the Contributor title.
	 *
	 * @dataProvider data_contributor_publishing_caps
	 * @param string $cap Capability the site gives Contributors.
	 */
	public function test_contributor_that_publishes_or_uploads_is_red( $cap ) {
		$this->grant_cap( 'contributor', $cap );
		$warning = Registration_Warnings::get_role_warning( 'contributor' );
		$this->assertSame( 'error', $warning['severity'] );
		$this->assertSame( 'Anyone can register as a Contributor', $warning['title'] );
		$this->assertSame( 'This role can publish posts or upload files. With "Anyone can register" checked, anyone who registers will have these abilities, including spammers. Uncheck "Anyone can register", or choose Subscriber unless the users who sign up here need to publish posts.', $warning['body'] );
	}

	/**
	 * Capabilities a site can give Contributors that let them publish or upload files.
	 */
	public function data_contributor_publishing_caps() {
		return [
			'publishes' => [ 'publish_posts' ],
			'uploads'   => [ 'upload_files' ],
		];
	}

	/**
	 * Roles, the capabilities to add a custom one with (null for a core role),
	 * and the warning's severity: "error" (red) for Author, Editor, Administrator
	 * and any role that can publish posts or upload files, "warning" (yellow) for
	 * any other role that can edit posts.
	 */
	public function data_role_severities() {
		return [
			'contributor'               => [ 'contributor', null, 'warning' ],
			'author'                    => [ 'author', null, 'error' ],
			'editor'                    => [ 'editor', null, 'error' ],
			'administrator'             => [ 'administrator', null, 'error' ],
			'custom, writes for review' => [ 'staff_writer', [ 'edit_posts' => true ], 'warning' ],
			'custom, publishes'         => [ 'staff_publisher', array_fill_keys( [ 'edit_posts', 'publish_posts' ], true ), 'error' ],
			'custom, uploads'           => [ 'staff_uploader', array_fill_keys( [ 'edit_posts', 'upload_files' ], true ), 'error' ],
		];
	}

	/**
	 * Each risky role's warning carries the severity its color comes from.
	 *
	 * @dataProvider data_role_severities
	 * @param string     $role     Role slug.
	 * @param array|null $caps     Capabilities for a custom role, or null for a core role.
	 * @param string     $severity Expected severity.
	 */
	public function test_role_warning_severity( $role, $caps, $severity ) {
		if ( null !== $caps ) {
			$this->add_test_role( $role, 'Test Role', $caps );
		}
		$this->assertSame( $severity, Registration_Warnings::get_role_warning( $role )['severity'] );
	}

	/**
	 * Only roles that can edit posts are listed as risky.
	 */
	public function test_get_risky_roles_lists_only_edit_posts_roles() {
		$roles = Registration_Warnings::get_risky_roles();
		foreach ( [ 'administrator', 'editor', 'author', 'contributor' ] as $role ) {
			$this->assertArrayHasKey( $role, $roles );
		}
		$this->assertArrayNotHasKey( 'subscriber', $roles );
	}

	/**
	 * The reCAPTCHA state, and whether open registration should warn about bots.
	 * A reCAPTCHA switched on without keys protects nothing, so it still warns.
	 */
	public function data_recaptcha_states() {
		return [
			'off'             => [ false, true ],
			'on with keys'    => [ 'keys', false ],
			'on without keys' => [ 'no-keys', true ],
		];
	}

	/**
	 * Open registration warns about bots unless reCAPTCHA can run.
	 *
	 * @dataProvider data_recaptcha_states
	 * @param string|false $recaptcha reCAPTCHA state.
	 * @param bool         $warns     Whether the bot warning applies.
	 */
	public function test_bot_warning_follows_recaptcha( $recaptcha, $warns ) {
		$this->set_recaptcha( $recaptcha );
		$this->open_registration( 'subscriber' );
		$this->assertSame( $warns, Registration_Warnings::is_missing_bot_protection() );
	}

	/**
	 * Assert that HTML links the Newspack → Settings → Connections path to the
	 * reCAPTCHA settings. Entities are decoded first, since escaping and
	 * wp_kses() write the "&" in the address differently.
	 *
	 * @param string $html HTML to check.
	 */
	private function assert_links_to_recaptcha_settings( $html ) {
		$this->assertStringContainsString(
			'<a href="' . admin_url( 'admin.php?page=newspack-settings&scrollTo=newspack-settings-recaptcha' ) . '">Newspack → Settings → Connections</a>',
			html_entity_decode( $html, ENT_QUOTES )
		);
	}

	/**
	 * The bot warning's copy, as approved, with the settings path linked.
	 */
	public function test_bot_protection_copy() {
		$warning = Registration_Warnings::get_bot_protection_warning();
		$this->assertSame( "Registration isn't protected from bots", $warning['title'] );
		$this->assertSame(
			'Without reCAPTCHA, bots can create accounts using fake email addresses. Too many bounced emails can cause your site to be blocked from sending email. Set up reCAPTCHA in Newspack → Settings → Connections, or uncheck "Anyone can register".',
			html_entity_decode( wp_strip_all_tags( $warning['body_html'] ), ENT_QUOTES )
		);
		$this->assert_links_to_recaptcha_settings( $warning['body_html'] );
	}

	/**
	 * Both checks are registered with Site Health.
	 */
	public function test_site_health_registers_both_tests() {
		$tests = apply_filters( 'site_status_tests', [ 'direct' => [] ] );
		$this->assertArrayHasKey( 'newspack_open_registration_role', $tests['direct'] );
		$this->assertArrayHasKey( 'newspack_open_registration_bot_protection', $tests['direct'] );
	}

	/**
	 * Default role, whether core's own check is registered, and whether ours should be.
	 */
	public function data_role_test_alongside_core() {
		return [
			'editor, core check present'        => [ 'editor', true, false ],
			'administrator, core check present' => [ 'administrator', true, false ],
			'author, core check present'        => [ 'author', true, true ],
			'contributor, core check present'   => [ 'contributor', true, true ],
			'editor, no core check'             => [ 'editor', false, true ],
		];
	}

	/**
	 * Core's check already reports open registration with Editor or Administrator,
	 * so ours stays out of Site Health then and covers the roles core doesn't.
	 *
	 * @dataProvider data_role_test_alongside_core
	 * @param string $role           Default role.
	 * @param bool   $core_check     Whether core's insecure_registration check is registered.
	 * @param bool   $expect_ours    Whether our role check should be registered.
	 */
	public function test_role_test_leaves_editor_and_administrator_to_core( $role, $core_check, $expect_ours ) {
		$this->open_registration( $role );
		$tests = [ 'direct' => [] ];
		if ( $core_check ) {
			$tests['direct']['insecure_registration'] = [
				'label' => 'Open Registration with privileged default role',
				'test'  => 'insecure_registration',
			];
		}
		$tests = Registration_Warnings::add_site_health_tests( $tests );

		$this->assertSame( $expect_ours, isset( $tests['direct']['newspack_open_registration_role'] ) );
		$this->assertArrayHasKey( 'newspack_open_registration_bot_protection', $tests['direct'] );
		if ( $core_check ) {
			$this->assertArrayHasKey( 'insecure_registration', $tests['direct'] );
		}
	}

	/**
	 * Role check states: box state, default role, expected status and label.
	 */
	public function data_role_test_states() {
		return [
			'closed'            => [ false, 'editor', 'good', "WordPress's registration form is closed" ],
			'open, safe role'   => [ true, 'subscriber', 'good', "New accounts get a role that can't edit posts" ],
			'open, contributor' => [ true, 'contributor', 'recommended', 'Open registration gives new accounts a role that can edit posts' ],
			'open, author'      => [ true, 'author', 'critical', 'Open registration gives new accounts a role that can edit posts' ],
			'open, editor'      => [ true, 'editor', 'critical', 'Open registration gives new accounts a role that can edit posts' ],
		];
	}

	/**
	 * The role check reports a problem only while the condition holds: Critical
	 * for a red warning, Recommended for a yellow one.
	 *
	 * @dataProvider data_role_test_states
	 * @param bool   $open   Whether "Anyone can register" is on.
	 * @param string $role   Default role.
	 * @param string $status Expected status.
	 * @param string $label  Expected label.
	 */
	public function test_role_test_states( $open, $role, $status, $label ) {
		update_option( 'users_can_register', $open ? 1 : 0 );
		update_option( 'default_role', $role );
		$result = Registration_Warnings::site_health_role_test();
		$this->assertSame( $status, $result['status'] );
		$this->assertSame( $label, $result['label'] );
		$this->assertSame( 'newspack_open_registration_role', $result['test'] );
		if ( 'good' !== $status ) {
			$this->assertStringContainsString( 'options-general.php', $result['actions'] );
		}
	}

	/**
	 * The Site Health item names the role, custom roles included, the way the
	 * Settings → General warning does.
	 */
	public function test_role_test_names_the_role() {
		$this->add_test_role( 'staff_writer', 'Staff Writer', [ 'edit_posts' => true ] );
		$this->open_registration( 'staff_writer' );
		$description = Registration_Warnings::site_health_role_test()['description'];
		$this->assertStringContainsString( esc_html( 'Anyone can register with the "Staff Writer" role' ), $description );
		$this->assertStringContainsString( 'This role can write posts.', $description );
	}

	/**
	 * While "Anyone can register" is off, each check has its own label and
	 * says the form is closed rather than describing the default role.
	 */
	public function test_closed_checks_say_the_form_is_closed() {
		update_option( 'users_can_register', 0 );
		update_option( 'default_role', 'author' );
		$role = Registration_Warnings::site_health_role_test();
		$bot  = Registration_Warnings::site_health_bot_protection_test();

		$this->assertNotSame( $role['label'], $bot['label'] );
		foreach ( [ $role, $bot ] as $result ) {
			$this->assertSame( 'good', $result['status'] );
			$this->assertStringContainsString( esc_html( '"Anyone can register" is off, so no one can create an account' ), $result['description'] );
			$this->assertStringNotContainsString( esc_html( "can't edit posts" ), $result['description'] );
		}
	}

	/**
	 * Bot protection check states: box state, reCAPTCHA state, expected status and label.
	 */
	public function data_bot_protection_test_states() {
		return [
			'closed'              => [ false, false, 'good', "Bots can't sign up through WordPress's registration form" ],
			'open, reCAPTCHA on'  => [ true, true, 'good', 'Sign-ups are protected by reCAPTCHA' ],
			'open, reCAPTCHA off' => [ true, false, 'recommended', 'Open registration has no bot protection' ],
		];
	}

	/**
	 * The bot protection check reports Recommended only while the condition holds.
	 *
	 * @dataProvider data_bot_protection_test_states
	 * @param bool   $open      Whether "Anyone can register" is on.
	 * @param bool   $recaptcha Whether reCAPTCHA is set up.
	 * @param string $status    Expected status.
	 * @param string $label     Expected label.
	 */
	public function test_bot_protection_test_states( $open, $recaptcha, $status, $label ) {
		update_option( 'users_can_register', $open ? 1 : 0 );
		if ( $recaptcha ) {
			$this->enable_recaptcha();
		}
		$result = Registration_Warnings::site_health_bot_protection_test();
		$this->assertSame( $status, $result['status'] );
		$this->assertSame( $label, $result['label'] );
		$this->assertSame( 'newspack_open_registration_bot_protection', $result['test'] );
		if ( 'recommended' === $status ) {
			$this->assert_links_to_recaptcha_settings( $result['description'] );
			$this->assertStringContainsString( esc_url( admin_url( 'admin.php?page=newspack-settings&scrollTo=newspack-settings-recaptcha' ) ), $result['actions'] );
			$this->assertStringContainsString( 'Set up reCAPTCHA', $result['actions'] );
			$this->assertStringContainsString( 'options-general.php', $result['actions'] );
		}
	}

	/**
	 * Both checks sit under Site Health's Security category.
	 */
	public function test_site_health_badge_is_security() {
		$this->assertSame( 'Security', Registration_Warnings::site_health_role_test()['badge']['label'] );
		$this->assertSame( 'Security', Registration_Warnings::site_health_bot_protection_test()['badge']['label'] );
	}

	/**
	 * The notices print on Settings → General only, and show while their condition holds.
	 */
	public function test_notices_render_only_on_general_settings() {
		$this->sign_in_as( 'administrator' );
		$this->open_registration( 'editor' );

		set_current_screen( 'options-general' );
		$output = $this->render_notices();
		$this->assertStringContainsString( 'id="newspack-registration-warning-bot"', $output );
		$this->assertStringContainsString( 'id="newspack-registration-warning-role"', $output );
		$this->assertStringNotContainsString( 'hidden', $output );
		$this->assertStringContainsString( 'Anyone can register as an Editor', $output );

		set_current_screen( 'dashboard' );
		$this->assertSame( '', $this->render_notices() );
	}

	/**
	 * With the saved settings safe, both notices print hidden so the script can show them live.
	 */
	public function test_notices_hidden_for_saved_safe_state() {
		$this->sign_in_as( 'administrator' );
		set_current_screen( 'options-general' );
		$output = $this->render_notices();
		$this->assertSame( 2, substr_count( $output, ' hidden' ) );
	}

	/**
	 * Users who can't manage options get no notices.
	 */
	public function test_notices_require_manage_options() {
		$this->sign_in_as( 'editor' );
		$this->open_registration( 'editor' );
		set_current_screen( 'options-general' );
		$this->assertSame( '', $this->render_notices() );
	}

	/**
	 * A role name containing markup is printed as text.
	 */
	public function test_notice_text_is_escaped() {
		$this->add_test_role( 'markup_role', '<b>Writer</b>', [ 'edit_posts' => true ] );
		$this->sign_in_as( 'administrator' );
		$this->open_registration( 'markup_role' );
		set_current_screen( 'options-general' );
		$output = $this->render_notices();
		$this->assertStringContainsString( '&lt;b&gt;Writer&lt;/b&gt;', $output );
		$this->assertStringNotContainsString( '<b>Writer</b>', $output );
	}

	/**
	 * The live script loads on Settings → General only.
	 */
	public function test_script_enqueued_only_on_general_settings() {
		$this->sign_in_as( 'administrator' );
		Registration_Warnings::enqueue_settings_script( 'index.php' );
		$this->assertFalse( wp_script_is( 'newspack-registration-warnings', 'enqueued' ) );

		Registration_Warnings::enqueue_settings_script( 'options-general.php' );
		$this->assertTrue( wp_script_is( 'newspack-registration-warnings', 'enqueued' ) );
	}

	/**
	 * Users who can't manage options don't get the script.
	 */
	public function test_script_requires_manage_options() {
		$this->sign_in_as( 'editor' );
		Registration_Warnings::enqueue_settings_script( 'options-general.php' );
		$this->assertFalse( wp_script_is( 'newspack-registration-warnings', 'enqueued' ) );
	}

	/**
	 * Each notice carries the attribute the script finds it by, and the title
	 * and body paragraphs the script rewrites.
	 */
	public function test_notices_carry_what_the_script_reads() {
		$this->sign_in_as( 'administrator' );
		$this->open_registration( 'editor' );
		set_current_screen( 'options-general' );
		$output = $this->render_notices();
		$this->assertMatchesRegularExpression( '#<div [^>]*data-newspack-registration-warning="bot"[^>]*><p><strong>Registration isn&\#039;t protected from bots</strong></p><p>(?:[^<]|<a [^>]+>[^<]+</a>)+</p></div>#', $output );
		$this->assertMatchesRegularExpression( '#<div [^>]*data-newspack-registration-warning="role"[^>]*><p><strong>Anyone can register as an Editor</strong></p><p>[^<]+</p></div>#', $output );
	}

	/**
	 * The bot warning on Settings → General links to the reCAPTCHA settings.
	 */
	public function test_bot_notice_links_to_recaptcha_settings() {
		$this->sign_in_as( 'administrator' );
		$this->open_registration( 'subscriber' );
		set_current_screen( 'options-general' );
		$this->assert_links_to_recaptcha_settings( $this->render_notices() );
	}

	/**
	 * Saved role, and the core notice class its warning prints with.
	 */
	public function data_role_notice_classes() {
		return [
			'contributor' => [ 'contributor', 'notice-warning' ],
			'author'      => [ 'author', 'notice-error' ],
		];
	}

	/**
	 * The role warning prints in its severity's color: yellow for Contributor,
	 * red for Author.
	 *
	 * @dataProvider data_role_notice_classes
	 * @param string $role  Saved default role.
	 * @param string $class Expected notice class.
	 */
	public function test_role_notice_color_follows_the_saved_role( $role, $class ) {
		$this->sign_in_as( 'administrator' );
		$this->open_registration( $role );
		set_current_screen( 'options-general' );
		$this->assertStringContainsString( 'class="notice ' . $class . ' inline notice-alt" data-newspack-registration-warning="role"', $this->render_notices() );
	}

	/**
	 * The script reads its data from a global filled by JSON.parse() on a
	 * string. Whether reCAPTCHA is on stays a real boolean, and a role slug of
	 * "__proto__" stays an ordinary key; an object literal would set the
	 * object's prototype instead, and that role would lose its warning. A role
	 * name holding markup arrives intact, with no raw "<" that could end or
	 * reopen the script element on core that doesn't escape it at print time.
	 */
	public function test_script_receives_its_data() {
		$this->sign_in_as( 'administrator' );
		$this->add_test_role( '__proto__', 'Proto', [ 'edit_posts' => true ] );
		$this->add_test_role( 'markup_writer', '<!--<script>Writer', [ 'edit_posts' => true ] );
		Registration_Warnings::enqueue_settings_script( 'options-general.php' );
		$inline = implode( '', array_filter( (array) wp_scripts()->get_data( 'newspack-registration-warnings', 'before' ) ) );
		$prefix = 'window.newspackRegistrationWarnings = JSON.parse( ';
		$suffix = ' );';
		$this->assertStringStartsWith( $prefix, $inline );
		$this->assertStringEndsWith( $suffix, $inline );
		$this->assertStringNotContainsString( '<', $inline );
		$json = json_decode( substr( $inline, strlen( $prefix ), -strlen( $suffix ) ) );
		$this->assertIsString( $json, 'JSON.parse() is given a string literal.' );
		$data = json_decode( $json, true );
		$this->assertArrayHasKey( '__proto__', $data['riskyRoles'] );
		$this->assertSame( 'Anyone can register with the "<!--<script>Writer" role', $data['riskyRoles']['markup_writer']['title'] );
		$this->assertFalse( $data['hasBotProtection'] );
		$this->assertSame( 'Anyone can register as an Editor', $data['riskyRoles']['editor']['title'] );
		$this->assertSame( 'error', $data['riskyRoles']['editor']['severity'] );
		$this->assertSame( 'warning', $data['riskyRoles']['contributor']['severity'] );
		$this->assertArrayNotHasKey( 'subscriber', $data['riskyRoles'] );
	}

	/**
	 * Box state, default role, and reCAPTCHA: false for off, "keys" for set up,
	 * "no-keys" for switched on without credentials. "custom_writer" is a
	 * custom role that can edit posts; "deleted_role" doesn't exist.
	 */
	public function data_option_states() {
		return [
			'closed, editor, no reCAPTCHA'                => [ false, 'editor', false ],
			'open, editor, no reCAPTCHA'                  => [ true, 'editor', false ],
			'open, author, no reCAPTCHA'                  => [ true, 'author', false ],
			'open, contributor, reCAPTCHA'                => [ true, 'contributor', 'keys' ],
			'open, custom writer role, reCAPTCHA'         => [ true, 'custom_writer', 'keys' ],
			'open, deleted role, reCAPTCHA'               => [ true, 'deleted_role', 'keys' ],
			'open, subscriber, no reCAPTCHA'              => [ true, 'subscriber', false ],
			'open, subscriber, reCAPTCHA on without keys' => [ true, 'subscriber', 'no-keys' ],
			'open, editor, reCAPTCHA'                     => [ true, 'editor', 'keys' ],
			'open, administrator, reCAPTCHA'              => [ true, 'administrator', 'keys' ],
			'open, subscriber, reCAPTCHA'                 => [ true, 'subscriber', 'keys' ],
		];
	}

	/**
	 * The script's data, read with the script's rule, gives the same verdict and
	 * color as this class's Site Health tests. (For a saved Editor or
	 * Administrator, WordPress's own Site Health test reports the same Critical
	 * verdict.)
	 *
	 * @dataProvider data_option_states
	 * @param bool         $open      Whether "Anyone can register" is on.
	 * @param string       $role      Default role.
	 * @param string|false $recaptcha reCAPTCHA state.
	 */
	public function test_script_data_matches_site_health( $open, $role, $recaptcha ) {
		// WordPress saves an unknown default role as Subscriber, so the custom
		// role has to exist first.
		if ( 'custom_writer' === $role ) {
			$this->add_test_role( $role, 'Writer', [ 'edit_posts' => true ] );
		}
		update_option( 'users_can_register', $open ? 1 : 0 );
		update_option( 'default_role', $role );
		if ( 'deleted_role' === $role ) {
			$this->point_default_role_at_missing_role( $role );
		}
		$this->assertSame( $role, get_option( 'default_role' ) );
		$this->set_recaptcha( $recaptcha );
		$script_data = Registration_Warnings::get_script_data();

		$script_shows_role = $open && isset( $script_data['riskyRoles'][ $role ] );
		$script_shows_bot  = $open && ! $script_data['hasBotProtection'];

		$role_status = Registration_Warnings::site_health_role_test()['status'];
		$this->assertSame( 'good' !== $role_status, $script_shows_role );
		if ( $script_shows_role ) {
			$this->assertSame( 'warning' === $script_data['riskyRoles'][ $role ]['severity'] ? 'recommended' : 'critical', $role_status );
		}
		$this->assertSame( 'recommended' === Registration_Warnings::site_health_bot_protection_test()['status'], $script_shows_bot );
	}
}
