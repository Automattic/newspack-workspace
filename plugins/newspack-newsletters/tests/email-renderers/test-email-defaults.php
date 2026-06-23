<?php
/**
 * Class Test_Email_Defaults
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Email_Defaults;
use Newspack\Newsletters\Email_Renderers\Feature_Flag;

/**
 * Tests for Email_Defaults — the Newspack fallback button radius at the default origin.
 *
 * The `wp_theme_json_data_default` filter is GLOBAL: it fires for every theme.json
 * resolution on the site. The guard must ensure zero effect outside the newsletter
 * email editor with the WC renderer flag on. These tests verify:
 *
 * - Flag OFF → callback is a no-op.
 * - Flag ON + not an email-editor request → callback is a no-op.
 * - Flag ON + email-editor request → injects DEFAULT_BUTTON_BORDER_RADIUS at default origin.
 * - Theme-origin radius wins after the normal WP merge order (default < theme < user).
 */
class Test_Email_Defaults extends WP_UnitTestCase {

	/**
	 * Newsletter post ID created once for the suite.
	 *
	 * @var int
	 */
	private static $newsletter_post_id;

	/**
	 * Saved $pagenow before each test.
	 *
	 * @var string|null
	 */
	private $saved_pagenow;

	/**
	 * Saved $_GET before each test.
	 *
	 * @var array
	 */
	private $saved_get;

	/**
	 * Create a newsletter post used across tests.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		self::$newsletter_post_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'draft',
				'post_title'  => 'Button-radius test newsletter',
			]
		);
	}

	/**
	 * Save global state before each test.
	 */
	public function set_up() {
		parent::set_up();
		$this->saved_pagenow = $GLOBALS['pagenow'] ?? null;
		$this->saved_get     = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET                = []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Restore global state after each test.
	 */
	public function tear_down() {
		delete_option( Feature_Flag::OPTION );

		if ( null === $this->saved_pagenow ) {
			unset( $GLOBALS['pagenow'] );
		} else {
			$GLOBALS['pagenow'] = $this->saved_pagenow;
		}
		$_GET = $this->saved_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Simulate an email-editor request for the newsletter post.
	 */
	private function simulate_email_editor_request() {
		global $pagenow;
		$pagenow      = 'post.php';
		$_GET['post'] = self::$newsletter_post_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Build a minimal WP_Theme_JSON_Data instance with no button styles.
	 *
	 * @return \WP_Theme_JSON_Data
	 */
	private function make_empty_default_data(): \WP_Theme_JSON_Data {
		return new \WP_Theme_JSON_Data( [ 'version' => 3 ], 'default' );
	}

	/**
	 * Pull styles.elements.button.border.radius out of a WP_Theme_JSON_Data.
	 *
	 * @param \WP_Theme_JSON_Data $data Theme.json data.
	 * @return string|null Radius value or null if absent.
	 */
	private function get_button_radius( \WP_Theme_JSON_Data $data ) {
		$raw = $data->get_data();
		return $raw['styles']['elements']['button']['border']['radius'] ?? null;
	}

	// -------------------------------------------------------------------------
	// Guard: flag OFF.
	// -------------------------------------------------------------------------

	/**
	 * With the WC renderer flag OFF the callback must be a no-op even when called
	 * in a simulated email-editor request context. This is the most critical guard:
	 * the filter is global and must never alter default theme.json outside its
	 * intended scope.
	 */
	public function test_no_op_when_flag_is_off() {
		// Flag is off by default (no option set).
		$this->simulate_email_editor_request();

		$data   = $this->make_empty_default_data();
		$result = Email_Defaults::inject_button_border_radius( $data );

		$this->assertNull(
			$this->get_button_radius( $result ),
			'inject_button_border_radius() must not inject when the WC renderer flag is off.'
		);
	}

	// -------------------------------------------------------------------------
	// Guard: flag ON, not an email-editor request.
	// -------------------------------------------------------------------------

	/**
	 * With the flag ON but NOT an email-editor request the callback must be a no-op.
	 *
	 * This covers the global-filter scenario: the filter fires for every page load,
	 * so the request-context guard is essential for the front-end.
	 */
	public function test_no_op_when_not_email_editor_request() {
		update_option( Feature_Flag::OPTION, '1' );

		// $pagenow is not 'post.php' / 'post-new.php' → not an email-editor request.
		global $pagenow;
		$pagenow = 'index.php';

		$data   = $this->make_empty_default_data();
		$result = Email_Defaults::inject_button_border_radius( $data );

		$this->assertNull(
			$this->get_button_radius( $result ),
			'inject_button_border_radius() must not inject when the request is not the email editor.'
		);
	}

	/**
	 * With the flag ON but editing a REGULAR post (not a newsletter CPT) the
	 * callback must be a no-op. Guards against false positives when someone opens
	 * the block editor for a standard post while the flag is on.
	 */
	public function test_no_op_when_editing_non_newsletter_post() {
		update_option( Feature_Flag::OPTION, '1' );

		$regular_post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		global $pagenow;
		$pagenow      = 'post.php';
		$_GET['post'] = $regular_post_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$data   = $this->make_empty_default_data();
		$result = Email_Defaults::inject_button_border_radius( $data );

		$this->assertNull(
			$this->get_button_radius( $result ),
			'inject_button_border_radius() must not inject when editing a non-newsletter post.'
		);
	}

	// -------------------------------------------------------------------------
	// Injection: flag ON + email-editor request.
	// -------------------------------------------------------------------------

	/**
	 * With the flag ON and a proper email-editor request the callback must inject
	 * DEFAULT_BUTTON_BORDER_RADIUS into styles.elements.button.border.radius.
	 */
	public function test_injects_button_radius_when_flag_on_and_email_editor() {
		update_option( Feature_Flag::OPTION, '1' );
		$this->simulate_email_editor_request();

		$data   = $this->make_empty_default_data();
		$result = Email_Defaults::inject_button_border_radius( $data );

		$this->assertSame(
			Email_Defaults::DEFAULT_BUTTON_BORDER_RADIUS,
			$this->get_button_radius( $result ),
			'inject_button_border_radius() must inject the fallback radius at the default origin.'
		);
	}

	/**
	 * The constant value must be '4px' (Task 8 depends on this exact value).
	 */
	public function test_default_button_border_radius_constant_value() {
		$this->assertSame(
			'4px',
			Email_Defaults::DEFAULT_BUTTON_BORDER_RADIUS,
			'DEFAULT_BUTTON_BORDER_RADIUS must be exactly "4px".'
		);
	}

	// -------------------------------------------------------------------------
	// Merge order: theme-origin radius wins over default-origin.
	// -------------------------------------------------------------------------

	/**
	 * A theme-origin button radius must win over the Newspack default-origin value.
	 *
	 * WP_Theme_JSON merges origins in ascending order (default < theme < user).
	 * We simulate this by:
	 *  1. Starting with a default-origin WP_Theme_JSON_Data after our callback ran.
	 *  2. Building a WP_Theme_JSON from it.
	 *  3. Merging a theme-origin WP_Theme_JSON on top.
	 *  4. Asserting the theme value wins.
	 */
	public function test_theme_origin_radius_wins_over_default() {
		update_option( Feature_Flag::OPTION, '1' );
		$this->simulate_email_editor_request();

		// Step 1: run callback → injects 4px at default origin.
		$default_data   = $this->make_empty_default_data();
		$injected_data  = Email_Defaults::inject_button_border_radius( $default_data );
		$default_theme  = new \WP_Theme_JSON( $injected_data->get_data(), 'default' );

		// Step 2: build a theme-origin JSON with a different radius.
		$theme_radius  = '8px';
		$theme_json    = new \WP_Theme_JSON(
			[
				'version' => 3,
				'styles'  => [
					'elements' => [
						'button' => [
							'border' => [
								'radius' => $theme_radius,
							],
						],
					],
				],
			],
			'theme'
		);

		// Step 3: merge theme on top of default (normal WP resolution order).
		$default_theme->merge( $theme_json );

		$raw    = $default_theme->get_raw_data();
		$result = $raw['styles']['elements']['button']['border']['radius'] ?? null;

		$this->assertSame(
			$theme_radius,
			$result,
			'A theme-origin button radius must override the Newspack default-origin fallback after merge.'
		);
	}
}
