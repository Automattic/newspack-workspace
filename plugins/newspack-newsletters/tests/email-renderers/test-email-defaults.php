<?php
/**
 * Class Test_Email_Defaults
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Email_Defaults;
use Newspack\Newsletters\Email_Renderers\Feature_Flag;

/**
 * Tests for Email_Defaults — the Newspack fallback button radius injected at the default origin.
 *
 * The `wp_theme_json_data_default` filter is GLOBAL (fires for every theme.json resolution), so the
 * guard must ensure zero effect outside the newsletter email editor with the WC renderer flag on.
 * Theme-origin radius wins after the normal WP merge order (default < theme < user).
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
		\Newspack\Newsletters\Email_Renderers\Fonts::reset_memo();

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
	 * Build a theme.json data object outside the WP_Theme_JSON_Data hierarchy,
	 * standing in for Gutenberg's WP_Theme_JSON_Data_Gutenberg.
	 *
	 * @return \Foreign_Theme_JSON_Data
	 */
	private function make_foreign_default_data() {
		require_once __DIR__ . '/fixtures/class-foreign-theme-json-data.php';
		return new \Foreign_Theme_JSON_Data( [ 'version' => 3 ], 'default' );
	}

	/**
	 * Pull styles.elements.button.border.radius out of a theme.json data object.
	 *
	 * Not type-hinted: the filter also receives Gutenberg's sibling class.
	 *
	 * @param object $data Theme.json data.
	 * @return string|null Radius value or null if absent.
	 */
	private function get_button_radius( $data ) {
		$raw = $data->get_data();
		return $raw['styles']['elements']['button']['border']['radius'] ?? null;
	}

	// -------------------------------------------------------------------------
	// Guard: flag OFF.
	// -------------------------------------------------------------------------

	/**
	 * Flag OFF → the callback is a no-op even in an email-editor context; the global filter must
	 * never alter default theme.json outside its intended scope.
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
	 * Flag ON but not an email-editor request → the callback is a no-op; the filter fires on every
	 * page load so the request-context guard is essential.
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
	 * Flag ON + email-editor request → injects DEFAULT_BUTTON_BORDER_RADIUS at the default origin.
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
	 * Pull a font-family out of a theme.json data object.
	 *
	 * Not type-hinted: the filter also receives Gutenberg's sibling class.
	 *
	 * @param object $data Theme.json data.
	 * @param string $side 'body' or 'header'.
	 * @return string|null Font family value or null if absent.
	 */
	private function get_font( $data, string $side ) {
		$raw = $data->get_data();
		if ( 'header' === $side ) {
			return $raw['styles']['elements']['heading']['typography']['fontFamily'] ?? null;
		}
		return $raw['styles']['typography']['fontFamily'] ?? null;
	}

	// -------------------------------------------------------------------------
	// Font injection guards + behaviour.
	// -------------------------------------------------------------------------

	/**
	 * Flag OFF → font injection is a no-op (MJML path unchanged).
	 */
	public function test_fonts_no_op_when_flag_is_off() {
		$this->simulate_email_editor_request();

		$data   = $this->make_empty_default_data();
		$result = Email_Defaults::inject_fonts( $data );

		$this->assertNull( $this->get_font( $result, 'body' ), 'Body font must not inject when flag is off.' );
		$this->assertNull( $this->get_font( $result, 'header' ), 'Header font must not inject when flag is off.' );
	}

	/**
	 * Flag ON but not an email-editor request → font injection is a no-op.
	 */
	public function test_fonts_no_op_when_not_email_editor_request() {
		update_option( Feature_Flag::OPTION, '1' );
		global $pagenow;
		$pagenow = 'index.php';

		$data   = $this->make_empty_default_data();
		$result = Email_Defaults::inject_fonts( $data );

		$this->assertNull( $this->get_font( $result, 'body' ) );
		$this->assertNull( $this->get_font( $result, 'header' ) );
	}

	/**
	 * Flag ON + email-editor request → resolved body/header fonts are injected at the default origin
	 * so global/theme fonts can still override them.
	 */
	public function test_fonts_injected_when_flag_on_and_email_editor() {
		update_option( Feature_Flag::OPTION, '1' );
		$this->simulate_email_editor_request();

		$data   = $this->make_empty_default_data();
		$result = Email_Defaults::inject_fonts( $data );

		$expected = \Newspack\Newsletters\Email_Renderers\Fonts::resolve( get_post( self::$newsletter_post_id ) );

		$this->assertSame( $expected['body'], $this->get_font( $result, 'body' ) );
		$this->assertSame( $expected['header'], $this->get_font( $result, 'header' ) );
	}

	/**
	 * A theme-origin font wins over the Newspack default-origin font (default < theme in WP merge order).
	 */
	public function test_theme_origin_font_wins_over_default() {
		update_option( Feature_Flag::OPTION, '1' );
		$this->simulate_email_editor_request();

		$default_data  = $this->make_empty_default_data();
		$injected_data = Email_Defaults::inject_fonts( $default_data );
		$default_theme = new \WP_Theme_JSON( $injected_data->get_data(), 'default' );

		$theme_json = new \WP_Theme_JSON(
			[
				'version' => 3,
				'styles'  => [ 'typography' => [ 'fontFamily' => 'ThemeBody, sans-serif' ] ],
			],
			'theme'
		);

		$default_theme->merge( $theme_json );

		$raw    = $default_theme->get_raw_data();
		$result = $raw['styles']['typography']['fontFamily'] ?? null;

		$this->assertSame(
			'ThemeBody, sans-serif',
			$result,
			'A theme-origin body font must override the Newspack default-origin font after merge.'
		);
	}

	// -------------------------------------------------------------------------
	// Gutenberg compatibility: the filter argument is not always core's class.
	// -------------------------------------------------------------------------

	/**
	 * With the Gutenberg plugin active the `wp_theme_json_data_*` filters receive
	 * `WP_Theme_JSON_Data_Gutenberg`, a sibling of core's `WP_Theme_JSON_Data` rather
	 * than a subclass. A `WP_Theme_JSON_Data` type declaration therefore fatals with a
	 * TypeError on every theme.json resolution — front end included — before the
	 * callback's own guards can run.
	 */
	public function test_button_radius_accepts_foreign_theme_json_data_when_flag_off() {
		// Flag off, so this is the state of every site that has not opted in.
		$data   = $this->make_foreign_default_data();
		$result = Email_Defaults::inject_button_border_radius( $data );

		$this->assertSame( $data, $result, 'The incoming object must be returned untouched when the flag is off.' );
		$this->assertNull( $this->get_button_radius( $result ), 'No radius must be injected when the flag is off.' );
	}

	/**
	 * Flag ON + email-editor request → the radius is injected into a non-core data object too.
	 */
	public function test_button_radius_injects_into_foreign_theme_json_data() {
		update_option( Feature_Flag::OPTION, '1' );
		$this->simulate_email_editor_request();

		$result = Email_Defaults::inject_button_border_radius( $this->make_foreign_default_data() );

		$this->assertSame(
			Email_Defaults::DEFAULT_BUTTON_BORDER_RADIUS,
			$this->get_button_radius( $result ),
			'The fallback radius must be injected regardless of which theme.json data class is passed.'
		);
	}

	/**
	 * The font callback carries the same type declaration and the same fatal.
	 */
	public function test_fonts_accept_foreign_theme_json_data_when_flag_off() {
		$data   = $this->make_foreign_default_data();
		$result = Email_Defaults::inject_fonts( $data );

		$this->assertSame( $data, $result, 'The incoming object must be returned untouched when the flag is off.' );
		$this->assertNull( $this->get_font( $result, 'body' ), 'No font must be injected when the flag is off.' );
	}

	/**
	 * Flag ON + email-editor request → fonts are injected into a non-core data object too.
	 */
	public function test_fonts_inject_into_foreign_theme_json_data() {
		update_option( Feature_Flag::OPTION, '1' );
		$this->simulate_email_editor_request();

		$result   = Email_Defaults::inject_fonts( $this->make_foreign_default_data() );
		$expected = \Newspack\Newsletters\Email_Renderers\Fonts::resolve( get_post( self::$newsletter_post_id ) );

		$this->assertSame( $expected['body'], $this->get_font( $result, 'body' ) );
		$this->assertSame( $expected['header'], $this->get_font( $result, 'header' ) );
	}

	/**
	 * A theme-origin button radius wins over the Newspack default-origin value, simulating the
	 * normal WP merge order (default < theme).
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
