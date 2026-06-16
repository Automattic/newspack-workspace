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
}
