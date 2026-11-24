<?php
/**
 * Warnings for WordPress's "Anyone can register" setting.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Warns when "Anyone can register" is on without its two safeguards: a New
 * User Default Role that can't edit posts, and bot protection on the sign-up
 * form. Settings → General and Site Health both read the checks here, so the
 * two screens always agree.
 *
 * Nothing here changes the settings: some sites need open registration, so the
 * publisher decides, with the risk spelled out.
 */
final class Registration_Warnings {
	/**
	 * Handle of the script that keeps the Settings → General warnings in step with the form.
	 */
	const SCRIPT_HANDLE = 'newspack-registration-warnings';

	/**
	 * Hook the warnings in.
	 */
	public static function init() {
		add_filter( 'site_status_tests', [ __CLASS__, 'add_site_health_tests' ] );
		add_action( 'admin_notices', [ __CLASS__, 'render_settings_notices' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_settings_script' ] );
	}

	/**
	 * Print both warnings on Settings → General, each hidden unless the saved
	 * settings call for it. The script moves them under their rows and shows
	 * or hides them as the form changes; without it they stay here, at the top.
	 */
	public static function render_settings_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'options-general' !== $screen->id || ! self::can_see_settings_warnings() ) {
			return;
		}
		self::print_notice( 'bot', 'notice-warning', self::get_bot_protection_warning(), self::is_missing_bot_protection() );
		$role_warning = self::get_role_warning( (string) get_option( 'default_role' ) ) ?? [
			'title' => '',
			'body'  => '',
		];
		self::print_notice( 'role', 'notice-error', $role_warning, self::has_risky_default_role() );
	}

	/**
	 * Load the script that updates the warnings before the form is saved.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue_settings_script( $hook_suffix ) {
		if ( 'options-general.php' !== $hook_suffix || ! self::can_see_settings_warnings() ) {
			return;
		}
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			Newspack::plugin_url() . '/dist/other-scripts/registration-warnings.js',
			[ 'wp-a11y' ],
			Newspack::asset_version( 'other-scripts/registration-warnings' ),
			true
		);
		// JSON keeps the booleans; wp_localize_script() would turn them into strings.
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.newspackRegistrationWarnings = ' . wp_json_encode( self::get_script_data() ) . ';',
			'before'
		);
	}

	/**
	 * What the script needs to decide, worked out here so the browser holds no
	 * rules of its own: whether reCAPTCHA is on, and the warning for each role
	 * that can edit posts.
	 *
	 * @return array{hasBotProtection: bool, riskyRoles: array}
	 */
	public static function get_script_data() {
		return [
			'hasBotProtection' => self::has_bot_protection(),
			'riskyRoles'       => self::get_risky_roles(),
		];
	}

	/**
	 * Whether the current user should see the Settings → General warnings.
	 *
	 * @return bool
	 */
	private static function can_see_settings_warnings() {
		return ! is_multisite() && current_user_can( 'manage_options' );
	}

	/**
	 * Print one warning in core's own notice style.
	 *
	 * @param string $type    "bot" or "role".
	 * @param string $class   Core notice class.
	 * @param array  $warning Title and body.
	 * @param bool   $visible Whether it shows before the script runs.
	 */
	private static function print_notice( $type, $class, $warning, $visible ) {
		printf(
			'<div id="newspack-registration-warning-%1$s" class="notice %2$s inline notice-alt" data-newspack-registration-warning="%1$s"%3$s><p><strong>%4$s</strong></p><p>%5$s</p></div>',
			esc_attr( $type ),
			esc_attr( $class ),
			$visible ? '' : ' hidden',
			esc_html( $warning['title'] ),
			esc_html( $warning['body'] )
		);
	}

	/**
	 * Add both checks to Tools → Site Health.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public static function add_site_health_tests( $tests ) {
		// Multisite controls sign-ups with a network setting these checks don't read.
		if ( is_multisite() ) {
			return $tests;
		}
		if ( ! self::is_reported_by_core( $tests ) ) {
			$tests['direct']['newspack_open_registration_role'] = [
				'label' => __( 'New User Default Role with open registration', 'newspack-plugin' ),
				'test'  => [ __CLASS__, 'site_health_role_test' ],
			];
		}
		$tests['direct']['newspack_open_registration_bot_protection'] = [
			'label' => __( 'Bot protection with open registration', 'newspack-plugin' ),
			'test'  => [ __CLASS__, 'site_health_bot_protection_test' ],
		];
		return $tests;
	}

	/**
	 * Whether WordPress's own Site Health check already reports the saved
	 * default role. Since WordPress 7.0, core's `insecure_registration` check
	 * flags open registration with Editor or Administrator; ours then stays out
	 * of Site Health so the same problem isn't listed twice, and covers the
	 * roles core doesn't, such as Author and Contributor.
	 *
	 * @param array $tests Site Health tests.
	 * @return bool
	 */
	private static function is_reported_by_core( $tests ) {
		return isset( $tests['direct']['insecure_registration'] )
			&& in_array( get_option( 'default_role' ), [ 'editor', 'administrator' ], true );
	}

	/**
	 * Site Health check: Critical while anyone can sign up with a role that can edit posts.
	 *
	 * @return array Site Health test result.
	 */
	public static function site_health_role_test() {
		$is_open = self::is_open();
		$result  = [
			'label'       => $is_open ? __( "New accounts get a role that can't edit posts", 'newspack-plugin' ) : __( 'No one can sign up with the New User Default Role', 'newspack-plugin' ),
			'status'      => 'good',
			'badge'       => self::get_site_health_badge(),
			'description' => $is_open ? '<p>' . esc_html__( "Accounts created through WordPress's registration form can't edit posts.", 'newspack-plugin' ) . '</p>' : self::get_closed_description(),
			'actions'     => '',
			'test'        => 'newspack_open_registration_role',
		];
		if ( ! self::has_risky_default_role() ) {
			return $result;
		}
		// has_risky_default_role() ran the same check, so the role has a warning.
		$warning               = self::get_role_warning( (string) get_option( 'default_role' ) );
		$result['label']       = __( 'Open registration gives new accounts a role that can edit posts', 'newspack-plugin' );
		$result['status']      = 'critical';
		$result['description'] = '<p><strong>' . esc_html( $warning['title'] ) . '</strong></p><p>' . esc_html( $warning['body'] ) . '</p>';
		$result['actions']     = self::get_site_health_link( admin_url( 'options-general.php' ), __( 'Change the New User Default Role', 'newspack-plugin' ) );
		return $result;
	}

	/**
	 * Site Health check: Recommended while anyone can sign up through a form with no bot protection.
	 *
	 * @return array Site Health test result.
	 */
	public static function site_health_bot_protection_test() {
		$is_open = self::is_open();
		$result  = [
			'label'       => $is_open ? __( 'Sign-ups are protected by reCAPTCHA', 'newspack-plugin' ) : __( "Bots can't sign up through the registration form", 'newspack-plugin' ),
			'status'      => 'good',
			'badge'       => self::get_site_health_badge(),
			'description' => $is_open ? '<p>' . esc_html__( "Bots can't create accounts through WordPress's registration form.", 'newspack-plugin' ) . '</p>' : self::get_closed_description(),
			'actions'     => '',
			'test'        => 'newspack_open_registration_bot_protection',
		];
		if ( ! self::is_missing_bot_protection() ) {
			return $result;
		}
		$result['label']       = __( 'Open registration has no bot protection', 'newspack-plugin' );
		$result['status']      = 'recommended';
		$result['description'] = '<p>' . esc_html( self::get_bot_protection_warning()['body'] ) . '</p>';
		$result['actions']     = self::get_site_health_link( admin_url( 'admin.php?page=newspack-settings&scrollTo=newspack-settings-recaptcha' ), __( 'Set up reCAPTCHA', 'newspack-plugin' ) )
			. self::get_site_health_link( admin_url( 'options-general.php' ), __( 'Go to General Settings', 'newspack-plugin' ) );
		return $result;
	}

	/**
	 * Site Health description for both checks while registration is closed.
	 *
	 * @return string
	 */
	private static function get_closed_description() {
		/* translators: "Anyone can register" is WordPress's label for the Membership checkbox; use WordPress's translation of it. */
		return '<p>' . esc_html__( '"Anyone can register" is off, so no one can create an account through WordPress\'s registration form.', 'newspack-plugin' ) . '</p>';
	}

	/**
	 * Site Health category badge for both checks.
	 *
	 * @return array{label: string, color: string}
	 */
	private static function get_site_health_badge() {
		return [
			'label' => __( 'Security', 'newspack-plugin' ),
			'color' => 'blue',
		];
	}

	/**
	 * A Site Health action link.
	 *
	 * @param string $url  Link target.
	 * @param string $text Link text.
	 * @return string
	 */
	private static function get_site_health_link( $url, $text ) {
		return sprintf( '<p><a href="%s">%s</a></p>', esc_url( $url ), esc_html( $text ) );
	}

	/**
	 * Whether WordPress's own registration form is open to anyone.
	 *
	 * Multisite controls registration with a network setting instead, which
	 * this class doesn't cover.
	 *
	 * @return bool
	 */
	public static function is_open() {
		return ! is_multisite() && (bool) get_option( 'users_can_register' );
	}

	/**
	 * Whether a role can edit posts. A role that no longer exists can't.
	 *
	 * @param string $role Role slug.
	 * @return bool
	 */
	public static function role_can_edit_posts( $role ) {
		$role_object = get_role( (string) $role );
		return $role_object ? $role_object->has_cap( 'edit_posts' ) : false;
	}

	/**
	 * Whether the registration form has bot protection.
	 *
	 * Mirrors the check that decides whether reCAPTCHA guards WordPress's
	 * registration form, so this never reports protection the form lacks.
	 *
	 * @return bool
	 */
	public static function has_bot_protection() {
		return Recaptcha::can_use_captcha();
	}

	/**
	 * Whether anyone can sign up with a role that can edit posts.
	 *
	 * @return bool
	 */
	public static function has_risky_default_role() {
		return self::is_open() && self::role_can_edit_posts( get_option( 'default_role' ) );
	}

	/**
	 * Whether anyone can sign up through a form with no bot protection.
	 *
	 * @return bool
	 */
	public static function is_missing_bot_protection() {
		return self::is_open() && ! self::has_bot_protection();
	}

	/**
	 * The red warning for every role that can edit posts, keyed by role slug.
	 *
	 * @return array<string, array{title: string, body: string}>
	 */
	public static function get_risky_roles() {
		$risky_roles = [];
		foreach ( array_keys( wp_roles()->roles ) as $role ) {
			$warning = self::get_role_warning( $role );
			if ( $warning ) {
				$risky_roles[ $role ] = $warning;
			}
		}
		return $risky_roles;
	}

	/**
	 * The red warning for one role, or null when the role can't edit posts.
	 *
	 * @param string $role Role slug.
	 * @return array{title: string, body: string}|null
	 */
	public static function get_role_warning( $role ) {
		if ( ! self::role_can_edit_posts( $role ) ) {
			return null;
		}
		$role_names   = wp_roles()->role_names;
		$display_name = isset( $role_names[ $role ] ) ? translate_user_role( $role_names[ $role ] ) : $role;

		return [
			'title' => self::get_role_title( $role, $display_name ),
			'body'  => self::get_role_sentence( $role ) . ' ' . sprintf(
				/* translators: %s: the Subscriber role's name. "Anyone can register" is WordPress's label for the Membership checkbox; use WordPress's translation of it. */
				__( 'With "Anyone can register" on, anyone on the internet can create an account with this role. Choose %s unless the people who sign up here need to write.', 'newspack-plugin' ),
				translate_user_role( 'Subscriber' )
			),
		];
	}

	/**
	 * The amber warning for a sign-up form with no bot protection.
	 *
	 * @return array{title: string, body: string}
	 */
	public static function get_bot_protection_warning() {
		return [
			'title' => __( 'Sign-ups have no bot protection', 'newspack-plugin' ),
			/* translators: "Anyone can register" is WordPress's label for the Membership checkbox; use WordPress's translation of it. */
			'body'  => __( 'reCAPTCHA isn\'t set up, so bots can create accounts, and each new account sends an email. A burst of bot sign-ups can get the site blocked from sending email. Set up reCAPTCHA in Newspack → Settings → Connections, or uncheck "Anyone can register".', 'newspack-plugin' ),
		];
	}

	/**
	 * The red warning's title. Core roles get a full string each, so "a" and
	 * "an" are right in every language.
	 *
	 * @param string $role         Role slug.
	 * @param string $display_name Translated role name.
	 * @return string
	 */
	private static function get_role_title( $role, $display_name ) {
		switch ( $role ) {
			case 'administrator':
				return __( 'Anyone can sign up as an Administrator', 'newspack-plugin' );
			case 'editor':
				return __( 'Anyone can sign up as an Editor', 'newspack-plugin' );
			case 'author':
				return __( 'Anyone can sign up as an Author', 'newspack-plugin' );
			case 'contributor':
				return __( 'Anyone can sign up as a Contributor', 'newspack-plugin' );
			default:
				/* translators: %s: role name, such as "Staff Writer". */
				return sprintf( __( 'Anyone can sign up with the "%s" role', 'newspack-plugin' ), $display_name );
		}
	}

	/**
	 * What a role lets its holder do, in one sentence.
	 *
	 * @param string $role Role slug.
	 * @return string
	 */
	private static function get_role_sentence( $role ) {
		switch ( $role ) {
			case 'administrator':
				return __( 'Administrators can change any setting, add users and install plugins.', 'newspack-plugin' );
			case 'editor':
				return __( 'Editors can publish and change any post.', 'newspack-plugin' );
			case 'author':
				return __( 'Authors can publish their own posts and upload files.', 'newspack-plugin' );
			case 'contributor':
				return __( 'Contributors can write posts for review.', 'newspack-plugin' );
			default:
				return __( 'This role can write posts.', 'newspack-plugin' );
		}
	}
}
Registration_Warnings::init();
