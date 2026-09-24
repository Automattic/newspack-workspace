<?php
/**
 * Class Bootstrap Flag Gate Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Editor_Bootstrap;

/**
 * Guards that the newsletters CPT is opted into the WooCommerce Email Editor
 * package only when the WC renderer flag is on, so a flag-off site keeps the
 * legacy (MJML) front-end behavior for public newsletters.
 *
 * Regression (#564): the package boots unconditionally, and its opt-in used to be
 * unconditional too. Once the CPT is opted in, the package's `single_template`
 * filter (Email_Editor::load_email_preview_template) takes over the front end of a
 * *public* newsletter (one set for both email and web) — serving the package's bare
 * email-preview template (no theme header, footer or sidebar) instead of the theme's
 * standard single template. Gating the opt-in on `Feature_Flag::is_enabled()` (in
 * Editor_Bootstrap::add_post_type) closes that front-end path when the flag is off.
 */
class Test_Bootstrap_Flag_Gate extends WP_UnitTestCase {

	/**
	 * The `add_post_type` filter is hooked inside init(); ensure the package is
	 * booted so `woocommerce_email_editor_post_types` reflects the opt-in.
	 */
	public function set_up() {
		parent::set_up();
		Editor_Bootstrap::init();
	}

	/**
	 * The newsletters CPT is present in `woocommerce_email_editor_post_types` only
	 * when the flag is on. The filter reads the flag lazily, so toggling it flips
	 * the opt-in without re-booting.
	 */
	public function test_cpt_opted_in_only_when_flag_on() {
		// Flag off (default): the CPT must not be opted in.
		$this->assertNotContains(
			\Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
			$this->opted_in_post_type_names(),
			'The newsletters CPT must not be opted into the WC email editor when the flag is off.'
		);

		// Flag on: the CPT is opted in, which is what engages the package's editor
		// and (for public newsletters) its front-end single_template takeover.
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		$this->assertContains(
			\Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
			$this->opted_in_post_type_names(),
			'The newsletters CPT must be opted into the WC email editor when the flag is on.'
		);
		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
	}

	/**
	 * The package's front-end `single_template` takeover engages only when the flag is
	 * on. This asserts the exact thing the regression broke — the template resolved for a
	 * public newsletter — which the CPT-opt-in and block-visibility tests do not: a future
	 * package version that keyed its takeover off something other than
	 * `woocommerce_email_editor_post_types` would slip past them.
	 *
	 * `load_email_preview_template()` only registers a nested closure and returns a path
	 * (render_html() is not called at filter time), so the flag-on branch stays cheap.
	 */
	public function test_single_template_taken_over_only_when_flag_on() {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'publish',
				'meta_input'  => [ 'is_public' => 1 ],
			]
		);
		// The package resolves the current post from the global; save/restore it.
		global $post;
		$previous_post = $post;
		$post          = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$theme_template = '/theme/single.php';

		// Flag off (default): the theme's single template is returned unchanged.
		$this->assertSame(
			$theme_template,
			apply_filters( 'single_template', $theme_template ),
			'Flag off: a public newsletter must keep the theme single template.'
		);

		// Flag on: the package's email-preview template takes over.
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		$resolved = apply_filters( 'single_template', $theme_template );
		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		$this->assertStringContainsString(
			'single-email-post-template.php',
			$resolved,
			'Flag on: the package email-preview template must take over the front end.'
		);

		$post = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * On the web front end (no active email render), `remove_visibility_hidden_block()`
	 * hides `email`-only blocks and keeps `web`-only and unmarked blocks.
	 *
	 * This is a characterization test of `remove_visibility_hidden_block()`, which the
	 * gate does not touch (the filter ran under the package template too, so email-only
	 * blocks were never the regression) — but it had no coverage anywhere before this PR,
	 * so it is worth pinning. The gate's actual behavior is asserted in
	 * test_single_template_taken_over_only_when_flag_on().
	 */
	public function test_email_only_blocks_hidden_on_web_front_end() {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'publish',
			]
		);
		// remove_visibility_hidden_block() reads the CPT from the global post. Save
		// and restore it so the override doesn't leak into later tests.
		global $post;
		$previous_post = $post;
		$post          = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$email_block = [ 'attrs' => [ 'newsletterVisibility' => 'email' ] ];
		$web_block   = [ 'attrs' => [ 'newsletterVisibility' => 'web' ] ];
		$plain_block = [ 'attrs' => [] ];

		$this->assertSame(
			'',
			\Newspack_Newsletters::remove_visibility_hidden_block( 'EMAIL ONLY', $email_block ),
			'Email-only blocks must be hidden on the web front end.'
		);
		$this->assertSame(
			'WEB ONLY',
			\Newspack_Newsletters::remove_visibility_hidden_block( 'WEB ONLY', $web_block ),
			'Web-only blocks must render on the web front end.'
		);
		$this->assertSame(
			'UNMARKED',
			\Newspack_Newsletters::remove_visibility_hidden_block( 'UNMARKED', $plain_block ),
			'Unmarked blocks must render on the web front end.'
		);

		$post = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * The names opted into the package's email post types via the filter.
	 *
	 * @return string[]
	 */
	private function opted_in_post_type_names(): array {
		$post_types = apply_filters( 'woocommerce_email_editor_post_types', [] );
		return is_array( $post_types ) ? array_column( $post_types, 'name' ) : [];
	}
}
