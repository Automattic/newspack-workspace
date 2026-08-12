<?php
/**
 * Class Editor Bootstrap Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Editor_Bootstrap;

/**
 * Editor Bootstrap Test.
 *
 * Verifies that booting the WooCommerce Email Editor package registers the
 * wrapping block template for the newsletters CPT.
 */
class Test_Editor_Bootstrap extends WP_UnitTestCase {
	/**
	 * The wrapping block template is registered under the package's
	 * "{plugin_uri}//{slug}" identifier once the editor is bootstrapped.
	 */
	public function test_wrapping_template_is_registered() {
		$template_id = Editor_Bootstrap::TEMPLATE_NAMESPACE . '//' . Editor_Bootstrap::TEMPLATE_SLUG;
		$template    = get_block_template( $template_id );
		$this->assertNotNull( $template, 'Expected the Newspack newsletter wrapping template to be registered.' );
		$this->assertSame( Editor_Bootstrap::TEMPLATE_SLUG, $template->slug, 'Registered template slug should match the bootstrap slug.' );
	}

	/**
	 * The registered template opts the newsletters CPT in via its post_types.
	 */
	public function test_template_targets_newsletters_cpt() {
		$template_id = Editor_Bootstrap::TEMPLATE_NAMESPACE . '//' . Editor_Bootstrap::TEMPLATE_SLUG;
		$template    = get_block_template( $template_id );
		$this->assertNotNull( $template );
		$this->assertContains(
			\Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
			$template->post_types,
			'The wrapping template should be associated with the newsletters CPT.'
		);
	}

	/**
	 * The newsletter CPT remains public with its canonical labels after the package
	 * re-registers opted-in CPTs with email defaults — the `init:11` re-assertion must
	 * win over the package's `init:10` clobber.
	 *
	 * The re-assertion (and the package's clobber) only run when the flag is on, so force
	 * it on and drive the sequence directly: without the flag the package registers
	 * nothing at `init:10`, and this would pass trivially against the untouched Newspack
	 * registration rather than proving the guard works.
	 */
	public function test_canonical_cpt_args_remain_authoritative() {
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );

		// Simulate the package's init:10 pass clobbering the CPT with email defaults
		// (public => false), then Newspack's flag-gated init:11 re-assertion restoring it.
		register_post_type( \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT, [ 'public' => false ] );
		Editor_Bootstrap::reassert_cpt_when_enabled();

		$post_type = get_post_type_object( \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT );
		$this->assertNotNull( $post_type, 'Newsletters CPT should be registered.' );
		$this->assertTrue( (bool) $post_type->public, 'Newsletters CPT should remain public after the package re-registers it.' );
		$expected_label = _x( 'Newsletters', 'post type general name', 'newspack-newsletters' );
		$this->assertSame( $expected_label, $post_type->labels->name, 'Newsletters CPT labels should remain authoritative after the package re-registers it.' );

		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
	}
}
