<?php // phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing
/**
 * Tests for WooCommerce_Email_Style_Sync.
 *
 * Test isolation:
 *
 *   - WC presence: flipped via the `newspack_wc_emails_available` filter
 *     rather than a `class WC_Emails {}` shim. A global class shim would
 *     couple this test class to suite ordering with any future code
 *     that branches on `class_exists( 'WC_Emails' )`.
 *
 *   - Hook detach: `Emails::maybe_update_email_templates` is hooked on
 *     `update_option_theme_mods_{theme}` and calls into a
 *     Newspack_Newsletters method that isn't present here, so
 *     `set_theme_mod( 'primary_color_hex', ... )` would explode.
 *     set_up() detaches the handler; tear_down() explicitly re-attaches
 *     it to keep this test class from leaking the detach into any
 *     subsequent test in the same PHPUnit process.
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_Email_Style_Sync;

class Newspack_Test_WooCommerce_Email_Style_Sync extends WP_UnitTestCase {

	/**
	 * The action hook that fires when theme mods change.
	 *
	 * @var string
	 */
	private $theme_mods_hook;

	private function create_fake_logo_attachment_id(): int {
		return self::factory()->attachment->create_object(
			'fake-logo.png',
			0,
			[
				'post_mime_type' => 'image/png',
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
			]
		);
	}

	/**
	 * Reset state between tests so option / theme-mod writes don't leak.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( WooCommerce_Email_Style_Sync::FIRST_RUN_DONE_OPTION );
		delete_option( WooCommerce_Email_Style_Sync::LAST_SYNCED_BASE_COLOR_OPTION );
		delete_option( 'woocommerce_email_base_color' );
		delete_option( 'woocommerce_email_header_image' );
		remove_theme_mod( 'primary_color_hex' );
		remove_theme_mod( 'custom_logo' );

		// Enable the WC-presence gate without shimming a global class.
		add_filter( 'newspack_wc_emails_available', '__return_true' );

		// Detach Emails::maybe_update_email_templates so set_theme_mod
		// doesn't trip an unrelated Newspack_Newsletters call. See file
		// docblock for context; tear_down() re-attaches.
		$this->theme_mods_hook = 'update_option_theme_mods_' . ( wp_get_theme()->parent() ? get_stylesheet() : get_template() );
		remove_action( $this->theme_mods_hook, [ \Newspack\Emails::class, 'maybe_update_email_templates' ], 10 );
	}

	/**
	 * Explicit teardown — restore both option/theme-mod state AND the
	 * hook + filter we touched in set_up(), so nothing leaks to later
	 * tests in the same PHPUnit process.
	 */
	public function tear_down() {
		delete_option( WooCommerce_Email_Style_Sync::FIRST_RUN_DONE_OPTION );
		delete_option( WooCommerce_Email_Style_Sync::LAST_SYNCED_BASE_COLOR_OPTION );
		delete_option( 'woocommerce_email_base_color' );
		delete_option( 'woocommerce_email_header_image' );
		remove_theme_mod( 'primary_color_hex' );
		remove_theme_mod( 'custom_logo' );

		// Narrow removal of the specific callbacks this class registers,
		// not remove_all_filters — that would nuke any sibling caller's
		// registration on the same hook.
		remove_filter( 'newspack_wc_email_style_sync_enabled', '__return_false' );
		remove_filter( 'newspack_wc_emails_available', '__return_true' );

		// Restore the hook we removed in set_up() so subsequent tests
		// run against the same hook table as the rest of the suite.
		add_action( $this->theme_mods_hook, [ \Newspack\Emails::class, 'maybe_update_email_templates' ], 10, 2 );

		parent::tear_down();
	}

	public function test_sync_styles_writes_base_color_and_backfills_empty_header_image() {
		set_theme_mod( 'primary_color_hex', '#abcdef' );

		// Logo exists and the header_image option is empty (not customized).
		// NPPD-1537: sync_styles() now backfills the header image in this
		// case, so a logo uploaded AFTER first-run still reaches classic WC
		// emails (previously the header image was first-run-only and a later
		// logo upload never propagated).
		$attachment_id = $this->create_fake_logo_attachment_id();
		set_theme_mod( 'custom_logo', $attachment_id );

		WooCommerce_Email_Style_Sync::sync_styles();

		$this->assertSame( '#abcdef', get_option( 'woocommerce_email_base_color' ) );
		$this->assertSame(
			wp_get_attachment_url( $attachment_id ),
			get_option( 'woocommerce_email_header_image' ),
			'sync_styles() must backfill an empty header_image once a logo exists.'
		);
	}

	/**
	 * Backfill is empty-only: sync_styles() fills an empty header image but
	 * must never overwrite a non-empty one (a publisher upload or our prior
	 * sync).
	 */
	public function test_sync_styles_does_not_overwrite_existing_header_image() {
		update_option( 'woocommerce_email_header_image', 'https://example.test/publisher-logo.png' );
		set_theme_mod( 'primary_color_hex', '#abcdef' );
		set_theme_mod( 'custom_logo', $this->create_fake_logo_attachment_id() );

		WooCommerce_Email_Style_Sync::sync_styles();

		$this->assertSame(
			'https://example.test/publisher-logo.png',
			get_option( 'woocommerce_email_header_image' ),
			'A non-empty header_image is a customization — sync_styles() must leave it alone.'
		);
	}

	public function test_first_run_writes_header_image_when_logo_set() {
		set_theme_mod( 'primary_color_hex', '#abcdef' );
		$attachment_id = $this->create_fake_logo_attachment_id();
		set_theme_mod( 'custom_logo', $attachment_id );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		$expected_url = wp_get_attachment_url( $attachment_id );
		$this->assertNotEmpty( $expected_url, 'Test setup invariant: attachment URL must resolve.' );
		$this->assertSame( $expected_url, get_option( 'woocommerce_email_header_image' ) );
		$this->assertSame( '#abcdef', get_option( 'woocommerce_email_base_color' ) );
	}

	public function test_maybe_sync_on_first_run_writes_when_no_customization() {
		set_theme_mod( 'primary_color_hex', '#112233' );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		$this->assertSame( '#112233', get_option( 'woocommerce_email_base_color' ) );
		$this->assertTrue(
			(bool) get_option( WooCommerce_Email_Style_Sync::FIRST_RUN_DONE_OPTION )
		);
	}

	public function test_maybe_sync_on_first_run_skips_when_customized() {
		// Publisher set their own value (`#deadbe` doesn't match any known
		// WC default — neither the hardcoded historicals nor any value
		// EmailColors would return). Value-equality check correctly
		// identifies this as a real customization, so the sync skips.
		update_option( 'woocommerce_email_base_color', '#deadbe' );
		set_theme_mod( 'primary_color_hex', '#112233' );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		// The pre-existing value is preserved.
		$this->assertSame( '#deadbe', get_option( 'woocommerce_email_base_color' ) );
		// First-run is still marked done so we don't re-evaluate forever.
		$this->assertTrue(
			(bool) get_option( WooCommerce_Email_Style_Sync::FIRST_RUN_DONE_OPTION )
		);
	}

	public function test_maybe_sync_on_first_run_is_idempotent() {
		set_theme_mod( 'primary_color_hex', '#111111' );
		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();
		$this->assertSame( '#111111', get_option( 'woocommerce_email_base_color' ) );

		// Simulate theme color changing AFTER first-run, then re-invoke
		// the first-run gate. Because the flag is set, the first-run
		// path must short-circuit — only the customize_save_after /
		// after_switch_theme hooks propagate ongoing theme changes.
		set_theme_mod( 'primary_color_hex', '#222222' );
		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		$this->assertSame( '#111111', get_option( 'woocommerce_email_base_color' ) );
	}

	public function test_filter_disables_sync_entirely() {
		add_filter( 'newspack_wc_email_style_sync_enabled', '__return_false' );
		set_theme_mod( 'primary_color_hex', '#abcdef' );

		// Neither entry point should write anything when the filter is off.
		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();
		WooCommerce_Email_Style_Sync::sync_styles();

		// Use assertSame against false sentinel rather than assertFalse(
		// get_option(...) ): on a CI matrix where WC's installer has run,
		// these options would exist as empty-string rows and assertFalse
		// would fail on `''`. assertSame( false, ... ) survives that
		// (an empty-string row would be a real signal something wrote).
		$this->assertSame( false, get_option( 'woocommerce_email_base_color', false ) );
		$this->assertSame( false, get_option( 'woocommerce_email_header_image', false ) );
		$this->assertSame( false, get_option( WooCommerce_Email_Style_Sync::FIRST_RUN_DONE_OPTION, false ) );
	}

	/**
	 * Regression lock for NPPD-1537 Katie-reported bug.
	 *
	 * WC's settings-page save persists every registered field on submit,
	 * even unchanged ones — landing WC's current default (`#8526ff` on
	 * sites with email_improvements active) in the option row. The old
	 * row-presence guard treated that as publisher customization and
	 * silently bypassed the sync. Per-option semantics fix: stored value
	 * matches WC default → not customized → sync writes the Newspack
	 * brand color.
	 */
	public function test_first_run_writes_base_color_when_stored_value_matches_wc_default() {
		// `#8526ff` is in the hardcoded historical WC defaults list, so
		// no filter setup needed — the customization check recognizes it
		// as a WC-wrote-this value regardless of whether the @internal
		// EmailColors class is available in the test runtime.
		update_option( 'woocommerce_email_base_color', '#8526ff' );
		set_theme_mod( 'primary_color_hex', '#003da5' );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		$this->assertSame(
			'#003da5',
			get_option( 'woocommerce_email_base_color' ),
			'Stored value matches a known WC default → not customized → sync should write the Newspack brand color.'
		);
	}

	/**
	 * Real publisher customization is still protected. Stored value
	 * differs from every known WC default → treat as customized → skip
	 * the write.
	 */
	public function test_first_run_skips_base_color_when_stored_value_differs_from_wc_default() {
		// Publisher's deliberate customization — `#deadbe` is not in any
		// of the hardcoded historicals (`#7f54b3`, `#720eec`, `#8526ff`)
		// and EmailColors is unavailable in test runtime, so the check
		// correctly identifies this as customization.
		update_option( 'woocommerce_email_base_color', '#deadbe' );
		set_theme_mod( 'primary_color_hex', '#003da5' );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		$this->assertSame(
			'#deadbe',
			get_option( 'woocommerce_email_base_color' ),
			'Stored value differs from every known WC default → customized → must not overwrite.'
		);
	}

	/**
	 * Row-presence semantics on the header image are preserved. WC
	 * doesn't write a default header image, so any value in the row is
	 * either ours (from a previous sync iteration) or a real publisher
	 * upload — either way, leave it alone.
	 */
	public function test_first_run_skips_when_header_image_already_set() {
		update_option( 'woocommerce_email_header_image', 'https://example.test/existing-logo.png' );
		set_theme_mod( 'custom_logo', $this->create_fake_logo_attachment_id() );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		$this->assertSame(
			'https://example.test/existing-logo.png',
			get_option( 'woocommerce_email_header_image' ),
			'Header image row already populated → must not overwrite.'
		);
	}

	/**
	 * Per-option semantics: the two options are evaluated independently,
	 * so a customized `header_image` does NOT block writing `base_color`
	 * (the old row-presence guard would have skipped BOTH if either row
	 * existed). This locks in the per-option behavior.
	 */
	public function test_first_run_skips_header_image_but_still_writes_base_color() {
		// header_image populated (publisher uploaded their logo), but
		// base_color is at WC default `#8526ff` (i.e. WC settings-save
		// wrote it). The hardcoded historicals catch this value.
		update_option( 'woocommerce_email_header_image', 'https://example.test/existing-logo.png' );
		update_option( 'woocommerce_email_base_color', '#8526ff' );
		set_theme_mod( 'primary_color_hex', '#003da5' );
		set_theme_mod( 'custom_logo', $this->create_fake_logo_attachment_id() );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		$this->assertSame(
			'https://example.test/existing-logo.png',
			get_option( 'woocommerce_email_header_image' ),
			'Header image stays — row-presence semantics apply per-option.'
		);
		$this->assertSame(
			'#003da5',
			get_option( 'woocommerce_email_base_color' ),
			'Base color gets the Newspack primary — header_image customization does not block the color sync.'
		);
	}

	/**
	 * Empty-string row is treated as "no customization" (same as row
	 * absent). Some WC code paths register options with `''` defaults
	 * and persist that on first settings save; treating that as
	 * customization would silently bypass the brand-color sync.
	 */
	public function test_first_run_writes_base_color_when_row_is_empty_string() {
		update_option( 'woocommerce_email_base_color', '' );
		set_theme_mod( 'primary_color_hex', '#003da5' );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		$this->assertSame(
			'#003da5',
			get_option( 'woocommerce_email_base_color' ),
			'Empty-string row → not customization → sync writes the Newspack brand color.'
		);
	}

	/**
	 * Hardcoded historical WC defaults (`#7f54b3` pre-9.6.1, `#720eec`
	 * 9.6.1+ legacy) are recognized as "WC wrote this" even when WC's
	 *
	 * @internal EmailColors class isn't available. This is the safety
	 * net for older WC installs.
	 */
	public function test_first_run_recognizes_hardcoded_historical_wc_defaults() {
		foreach ( [ '#7f54b3', '#720eec' ] as $historical_default ) {
			update_option( 'woocommerce_email_base_color', $historical_default );
			delete_option( WooCommerce_Email_Style_Sync::FIRST_RUN_DONE_OPTION );
			delete_option( WooCommerce_Email_Style_Sync::LAST_SYNCED_BASE_COLOR_OPTION );
			set_theme_mod( 'primary_color_hex', '#003da5' );

			WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

			$this->assertSame(
				'#003da5',
				get_option( 'woocommerce_email_base_color' ),
				sprintf( 'Historical WC default %s must be recognized → sync writes Newspack brand color.', $historical_default )
			);
		}
	}

	/**
	 * Provenance: `sync_base_color()` records the value it wrote in
	 * `LAST_SYNCED_BASE_COLOR_OPTION`. Subsequent `sync_styles()`
	 * invocations compare against this marker to detect publisher edits.
	 */
	public function test_first_run_records_provenance_marker_when_writing() {
		set_theme_mod( 'primary_color_hex', '#003da5' );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		$this->assertSame(
			'#003da5',
			get_option( WooCommerce_Email_Style_Sync::LAST_SYNCED_BASE_COLOR_OPTION ),
			'After a successful first-run write, the provenance marker must record what we wrote.'
		);
	}

	/**
	 * NPPD-1537 regression: when first-run SKIPS due to a publisher
	 * customization, it must NOT seed the provenance marker with the
	 * publisher's value. Seeding it would make the next sync_styles() see
	 * `stored === marker` and clobber the customization on an unrelated
	 * Customizer save. With no marker, sync_styles()'s no-marker branch
	 * re-runs the customization check and preserves the publisher color.
	 */
	public function test_first_run_skip_does_not_seed_marker_and_sync_styles_preserves_custom_color() {
		update_option( 'woocommerce_email_base_color', '#deadbe' );
		set_theme_mod( 'primary_color_hex', '#003da5' );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		$this->assertSame(
			'#deadbe',
			get_option( 'woocommerce_email_base_color' ),
			'Publisher customization must be preserved on first-run.'
		);
		$this->assertFalse(
			get_option( WooCommerce_Email_Style_Sync::LAST_SYNCED_BASE_COLOR_OPTION, false ),
			'First-run skip must NOT seed the provenance marker with the publisher value.'
		);

		// A later Customizer save must still preserve the customization —
		// the no-marker branch defers to has_base_color_customization().
		set_theme_mod( 'primary_color_hex', '#abcdef' );
		WooCommerce_Email_Style_Sync::sync_styles();

		$this->assertSame(
			'#deadbe',
			get_option( 'woocommerce_email_base_color' ),
			'sync_styles() must preserve the publisher customization (no marker → customization check skips).'
		);
	}

	/**
	 * NPPD-1537 regression: a publisher base-color customization suppresses
	 * the base-color write but must NOT prevent the header-image backfill.
	 * Scenario: custom base color (no marker), no logo at first run, logo
	 * uploaded later. The later sync_styles() must preserve the custom color
	 * AND backfill the now-available logo into the empty header_image —
	 * previously an early `return` on the base-color skip stranded it.
	 */
	public function test_sync_styles_backfills_header_image_even_when_base_color_is_customized() {
		// Publisher-customized base color, no provenance marker (first-run
		// skipped it), and an empty header_image row.
		update_option( 'woocommerce_email_base_color', '#deadbe' );
		$this->assertFalse(
			get_option( WooCommerce_Email_Style_Sync::LAST_SYNCED_BASE_COLOR_OPTION, false ),
			'Precondition: no provenance marker.'
		);

		// A logo is uploaded after first-run.
		$attachment_id = $this->create_fake_logo_attachment_id();
		set_theme_mod( 'custom_logo', $attachment_id );
		set_theme_mod( 'primary_color_hex', '#abcdef' );

		WooCommerce_Email_Style_Sync::sync_styles();

		$this->assertSame(
			'#deadbe',
			get_option( 'woocommerce_email_base_color' ),
			'Publisher base-color customization must be preserved (skip, not clobber).'
		);
		$this->assertSame(
			wp_get_attachment_url( $attachment_id ),
			get_option( 'woocommerce_email_header_image' ),
			'Header-image backfill must still run even though the base-color write was skipped.'
		);
	}

	/**
	 * NPPD-1537: the provenance marker must NOT be recorded when the
	 * `woocommerce_email_base_color` write is vetoed by a
	 * `pre_update_option_*` filter — otherwise provenance would claim we
	 * wrote a value the option never stored.
	 */
	public function test_sync_base_color_does_not_record_marker_when_write_is_rejected() {
		// `#8526ff` is a known WC default → not a customization → first-run
		// attempts the write. The veto then blocks it.
		update_option( 'woocommerce_email_base_color', '#8526ff' );
		set_theme_mod( 'primary_color_hex', '#003da5' );

		$veto = static function ( $value, $old_value ) {
			return $old_value;
		};
		add_filter( 'pre_update_option_woocommerce_email_base_color', $veto, 10, 2 );

		WooCommerce_Email_Style_Sync::maybe_sync_on_first_run();

		remove_filter( 'pre_update_option_woocommerce_email_base_color', $veto, 10 );

		$this->assertSame(
			'#8526ff',
			get_option( 'woocommerce_email_base_color' ),
			'Sanity: the write was vetoed, so the option keeps its prior value.'
		);
		$this->assertFalse(
			get_option( WooCommerce_Email_Style_Sync::LAST_SYNCED_BASE_COLOR_OPTION, false ),
			'Provenance marker must not be recorded when the base_color write was rejected.'
		);
	}

	/**
	 * Ongoing path: `sync_styles()` skips when the publisher has edited
	 * `woocommerce_email_base_color` via WC > Settings > Emails after our
	 * last write. The stored value no longer matches the provenance
	 * marker; respect the publisher's customization.
	 */
	public function test_sync_styles_skips_when_stored_differs_from_provenance_marker() {
		// Simulate prior sync: we wrote `#aaaaaa` and recorded it.
		update_option( 'woocommerce_email_base_color', '#aaaaaa' );
		update_option( WooCommerce_Email_Style_Sync::LAST_SYNCED_BASE_COLOR_OPTION, '#aaaaaa' );
		// Publisher then edits to their own value via WC > Settings.
		update_option( 'woocommerce_email_base_color', '#cafe00' );
		// Theme changes after that publisher edit.
		set_theme_mod( 'primary_color_hex', '#bbbbbb' );

		WooCommerce_Email_Style_Sync::sync_styles();

		$this->assertSame(
			'#cafe00',
			get_option( 'woocommerce_email_base_color' ),
			'Stored value differs from the provenance marker → publisher edited via WC > Settings → sync_styles() must skip.'
		);
		$this->assertSame(
			'#aaaaaa',
			get_option( WooCommerce_Email_Style_Sync::LAST_SYNCED_BASE_COLOR_OPTION ),
			'Provenance marker must not be updated on the skip path.'
		);
	}

	/**
	 * Ongoing path: `sync_styles()` proceeds when the stored value still
	 * matches the provenance marker — the publisher hasn't touched it
	 * since our last write, so the new theme color is safe to apply.
	 */
	public function test_sync_styles_proceeds_when_stored_matches_provenance_marker() {
		update_option( 'woocommerce_email_base_color', '#aaaaaa' );
		update_option( WooCommerce_Email_Style_Sync::LAST_SYNCED_BASE_COLOR_OPTION, '#aaaaaa' );
		set_theme_mod( 'primary_color_hex', '#bbbbbb' );

		WooCommerce_Email_Style_Sync::sync_styles();

		$this->assertSame(
			'#bbbbbb',
			get_option( 'woocommerce_email_base_color' ),
			'Stored matches marker → publisher untouched → sync_styles() writes the new theme color.'
		);
		$this->assertSame(
			'#bbbbbb',
			get_option( WooCommerce_Email_Style_Sync::LAST_SYNCED_BASE_COLOR_OPTION ),
			'Provenance marker updates to the new written value.'
		);
	}
}
