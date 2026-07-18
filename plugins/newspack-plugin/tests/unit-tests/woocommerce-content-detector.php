<?php
/**
 * Tests for WooCommerce_Content_Detector.
 *
 * @package Newspack
 */

use Newspack\WooCommerce_Content_Detector;

/**
 * Test WooCommerce content detection.
 */
class Newspack_Test_WooCommerce_Content_Detector extends WP_UnitTestCase {

	/**
	 * The `products` shortcode callback registered before a test (if any),
	 * captured so the class restores the global shortcode registry as it found it.
	 * Widget/option mutations don't need snapshotting — WP_UnitTestCase wraps each
	 * test in a DB transaction that is rolled back on tearDown — but the shortcode
	 * registry ($shortcode_tags) is not DB-backed, so it is restored explicitly.
	 *
	 * @var callable|null
	 */
	private $prior_products_shortcode = null;

	/**
	 * Reset the detector's per-request memo and snapshot the shortcode registry.
	 */
	public function setUp(): void {
		parent::setUp();
		WooCommerce_Content_Detector::reset_memo();
		$this->prior_products_shortcode = $GLOBALS['shortcode_tags']['products'] ?? null;
	}

	/**
	 * Reset the memo and restore the `products` shortcode to its pre-test state.
	 */
	public function tearDown(): void {
		WooCommerce_Content_Detector::reset_memo();
		remove_shortcode( 'products' );
		if ( null !== $this->prior_products_shortcode ) {
			add_shortcode( 'products', $this->prior_products_shortcode );
		}
		$this->prior_products_shortcode = null;
		parent::tearDown();
	}

	/**
	 * A queried page containing a woocommerce/* block is detected,
	 * including when the block is nested inside another block.
	 */
	public function test_detects_wc_block_in_queried_post() {
		$page = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:woocommerce/product-category /--></div><!-- /wp:group -->',
			]
		);
		$this->go_to( get_permalink( $page ) );
		WooCommerce_Content_Detector::reset_memo();
		$this->assertTrue( WooCommerce_Content_Detector::current_request_has_woocommerce_content() );
	}

	/**
	 * A queried page containing a registered WooCommerce shortcode is detected.
	 */
	public function test_detects_wc_shortcode_in_queried_post() {
		add_shortcode( 'products', '__return_empty_string' );
		$page = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<!-- wp:paragraph --><p>[products limit="4"]</p><!-- /wp:paragraph -->',
			]
		);
		$this->go_to( get_permalink( $page ) );
		WooCommerce_Content_Detector::reset_memo();
		$this->assertTrue( WooCommerce_Content_Detector::current_request_has_woocommerce_content() );
	}

	/**
	 * A queried page with no WooCommerce content is not detected.
	 */
	public function test_clean_queried_post_is_not_detected() {
		$page = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<!-- wp:paragraph --><p>Just words.</p><!-- /wp:paragraph -->',
			]
		);
		$this->go_to( get_permalink( $page ) );
		WooCommerce_Content_Detector::reset_memo();
		$this->assertFalse( WooCommerce_Content_Detector::current_request_has_woocommerce_content() );
	}

	/**
	 * A singular custom post type is scanned the same way (post-type-agnostic).
	 */
	public function test_detects_wc_block_in_singular_cpt() {
		register_post_type( 'np_test_cpt', [ 'public' => true ] );
		$post = self::factory()->post->create(
			[
				'post_type'    => 'np_test_cpt',
				'post_content' => '<!-- wp:woocommerce/product-category /-->',
			]
		);
		$this->go_to( get_permalink( $post ) );
		WooCommerce_Content_Detector::reset_memo();
		$this->assertTrue( WooCommerce_Content_Detector::current_request_has_woocommerce_content() );
		unregister_post_type( 'np_test_cpt' );
	}

	/**
	 * A WooCommerce block in a widget assigned to an ACTIVE sidebar is detected.
	 */
	public function test_detects_wc_block_in_active_block_widget() {
		$clean = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<p>clean</p>',
			]
		);
		$this->go_to( get_permalink( $clean ) );
		update_option(
			'widget_block',
			[
				2 => [ 'content' => '<!-- wp:paragraph --><p>nope</p><!-- /wp:paragraph -->' ],
				3 => [ 'content' => '<!-- wp:woocommerce/product-category /-->' ],
			]
		);
		wp_set_sidebars_widgets(
			[
				'sidebar-1'           => [ 'block-3' ],
				'wp_inactive_widgets' => [],
			]
		);
		WooCommerce_Content_Detector::reset_memo();
		$this->assertTrue( WooCommerce_Content_Detector::current_request_has_woocommerce_content() );
	}

	/**
	 * The SAME WooCommerce widget, present only in wp_inactive_widgets, is NOT
	 * detected (locks in the active-only scope — orphaned widgets must not veto
	 * the strip site-wide).
	 */
	public function test_inactive_block_widget_is_not_detected() {
		$clean = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<p>clean</p>',
			]
		);
		$this->go_to( get_permalink( $clean ) );
		update_option(
			'widget_block',
			[ 3 => [ 'content' => '<!-- wp:woocommerce/product-category /-->' ] ]
		);
		wp_set_sidebars_widgets(
			[
				'sidebar-1'           => [],
				'wp_inactive_widgets' => [ 'block-3' ],
			]
		);
		WooCommerce_Content_Detector::reset_memo();
		$this->assertFalse( WooCommerce_Content_Detector::current_request_has_woocommerce_content() );
	}

	/**
	 * A WooCommerce block inside a synced pattern (core/block) referenced by the
	 * queried page is detected — the same failure mode as the reported incident.
	 */
	public function test_detects_wc_block_in_synced_pattern() {
		$pattern = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_content' => '<!-- wp:woocommerce/product-category /-->',
			]
		);
		$page = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<!-- wp:block {"ref":' . $pattern . '} /-->',
			]
		);
		$this->go_to( get_permalink( $page ) );
		WooCommerce_Content_Detector::reset_memo();
		$this->assertTrue( WooCommerce_Content_Detector::current_request_has_woocommerce_content() );
	}

	/**
	 * Synced patterns nested two levels deep are detected (recursion).
	 */
	public function test_detects_wc_block_in_nested_synced_pattern() {
		$inner = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_content' => '<!-- wp:woocommerce/product-category /-->',
			]
		);
		$outer = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_content' => '<!-- wp:block {"ref":' . $inner . '} /-->',
			]
		);
		$page = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<!-- wp:block {"ref":' . $outer . '} /-->',
			]
		);
		$this->go_to( get_permalink( $page ) );
		WooCommerce_Content_Detector::reset_memo();
		$this->assertTrue( WooCommerce_Content_Detector::current_request_has_woocommerce_content() );
	}

	/**
	 * A WooCommerce block in the resolved FSE template content is detected.
	 */
	public function test_detects_wc_block_in_fse_template() {
		// twentytwentyfour is a block theme bundled with the WP test scaffold;
		// scan_fse_template gates on wp_is_block_theme(), so any block theme works.
		switch_theme( 'twentytwentyfour' );
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			switch_theme( WP_DEFAULT_THEME );
			$this->markTestSkipped( 'No block theme available in this environment.' );
		}
		$clean = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<p>clean</p>',
			]
		);
		$this->go_to( get_permalink( $clean ) );
		$GLOBALS['_wp_current_template_content'] = '<!-- wp:woocommerce/product-category /-->';
		WooCommerce_Content_Detector::reset_memo();
		$result = WooCommerce_Content_Detector::current_request_has_woocommerce_content();
		unset( $GLOBALS['_wp_current_template_content'] );
		switch_theme( WP_DEFAULT_THEME );
		$this->assertTrue( $result );
	}

	/**
	 * An empty template-content global is a clean miss for the FSE source, not
	 * an error.
	 */
	public function test_empty_fse_template_global_is_not_detected() {
		// twentytwentyfour is a block theme bundled with the WP test scaffold;
		// scan_fse_template gates on wp_is_block_theme(), so any block theme works.
		switch_theme( 'twentytwentyfour' );
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			switch_theme( WP_DEFAULT_THEME );
			$this->markTestSkipped( 'No block theme available in this environment.' );
		}
		$clean = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<p>clean</p>',
			]
		);
		$this->go_to( get_permalink( $clean ) );
		unset( $GLOBALS['_wp_current_template_content'] );
		WooCommerce_Content_Detector::reset_memo();
		$result = WooCommerce_Content_Detector::current_request_has_woocommerce_content();
		switch_theme( WP_DEFAULT_THEME );
		$this->assertFalse( $result );
	}

	/**
	 * A cyclic synced-pattern reference with no WooCommerce content terminates
	 * (cycle guard) and returns false rather than looping forever.
	 */
	public function test_cyclic_synced_patterns_terminate() {
		$a = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_content' => 'A',
			]
		);
		$b = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_content' => '<!-- wp:block {"ref":' . $a . '} /-->',
			]
		);
		$this->assertNotEmpty(
			wp_update_post(
				[
					'ID'           => $a,
					'post_content' => '<!-- wp:block {"ref":' . $b . '} /-->',
				]
			),
			'Setup: post A must be updated to reference B so a real cycle exists.'
		);
		$page = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<!-- wp:block {"ref":' . $a . '} /-->',
			]
		);
		$this->go_to( get_permalink( $page ) );
		WooCommerce_Content_Detector::reset_memo();
		$this->assertFalse( WooCommerce_Content_Detector::current_request_has_woocommerce_content() );
	}

	/**
	 * A non-WP_Post queried object (e.g. a term archive) is handled safely by
	 * scan_queried_post and, with no other WooCommerce content, is not detected.
	 */
	public function test_non_wp_post_queried_object_is_not_detected() {
		$cat  = self::factory()->category->create( [ 'name' => 'np-test-cat' ] );
		$post = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_content' => '<p>plain</p>',
			]
		);
		wp_set_post_categories( $post, [ $cat ] );
		$this->go_to( get_category_link( $cat ) );
		WooCommerce_Content_Detector::reset_memo();
		// get_queried_object() is a WP_Term here, not a WP_Post.
		$this->assertFalse( WooCommerce_Content_Detector::current_request_has_woocommerce_content() );
	}

	/**
	 * If a source throws, detection fails open (returns true) and logs via
	 * newspack_log so a persistent failure is observable.
	 */
	public function test_fails_open_and_logs_on_error() {
		$clean = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<p>clean</p>',
			]
		);
		$this->go_to( get_permalink( $clean ) );
		// Ensure the widget source is reached, then make its option read throw.
		wp_set_sidebars_widgets(
			[
				'sidebar-1'           => [ 'block-2' ],
				'wp_inactive_widgets' => [],
			]
		);
		// Intentionally throws (never returns) to exercise the detector's fail-open path.
		$throwing_filter = function () { // phpcs:ignore WordPressVIPMinimum.Hooks.AlwaysReturnInFilter.MissingReturnStatement
			throw new \RuntimeException( 'boom' );
		};
		add_filter( 'option_widget_block', $throwing_filter );
		$logged_code = null;
		add_action(
			'newspack_log',
			function ( $code ) use ( &$logged_code ) {
				$logged_code = $code;
			},
			10,
			1
		);
		WooCommerce_Content_Detector::reset_memo();
		try {
			$this->assertTrue( WooCommerce_Content_Detector::current_request_has_woocommerce_content() );
			$this->assertSame( 'newspack_perfmatters_wc_detection_error', $logged_code );
		} finally {
			// Remove the throwing filter so it can't make later tests order-dependent,
			// even if an assertion above fails.
			remove_filter( 'option_widget_block', $throwing_filter );
		}
	}

	/**
	 * A WooCommerce block inside a template part — referenced via core/template-part
	 * in the FSE template global — is detected by following the part reference into
	 * the separately-stored wp_template_part post.
	 *
	 * This exercises the resolve_template_part() → get_block_template() branch: the
	 * global contains only a template-part reference (no inline WC block), so a true
	 * result can only come from resolving the reference into the part's content.
	 */
	public function test_detects_wc_block_via_template_part_resolution() {
		switch_theme( 'twentytwentyfour' );
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			switch_theme( WP_DEFAULT_THEME );
			$this->markTestSkipped( 'No block theme available in this environment.' );
		}

		// Create a wp_template_part post whose content contains a WooCommerce block.
		// get_block_template() queries by post_name + wp_theme taxonomy term name.
		$theme   = get_stylesheet();
		$slug    = 'np-test-wc-part';
		$part_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template_part',
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_content' => '<!-- wp:woocommerce/product-category /-->',
				'post_title'   => 'NP Test WC Part',
			]
		);
		// Assign the active theme's wp_theme taxonomy term so get_block_template()
		// can find this part via the "<theme>//<slug>" ID it constructs.
		wp_set_object_terms( $part_id, $theme, 'wp_theme' );

		// Seed the FSE template global with ONLY a template-part reference — no
		// inline WooCommerce block.  A true result must come from following the ref.
		$GLOBALS['_wp_current_template_content'] = sprintf(
			'<!-- wp:template-part {"slug":"%s","theme":"%s"} /-->',
			$slug,
			$theme
		);

		$clean = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<p>clean</p>',
			]
		);
		$this->go_to( get_permalink( $clean ) );
		WooCommerce_Content_Detector::reset_memo();
		$result = WooCommerce_Content_Detector::current_request_has_woocommerce_content();

		// Cleanup before assertion so the theme switch runs even on failure.
		unset( $GLOBALS['_wp_current_template_content'] );
		switch_theme( WP_DEFAULT_THEME );

		$this->assertTrue( $result );
	}

	/**
	 * The result is memoized: a second call does not re-run the sources. Asserted
	 * via a spy on the widget_block option read (which the widget source performs
	 * once on a clean page) — the count must not increase on the second call.
	 */
	public function test_result_is_memoized() {
		$clean = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<p>clean</p>',
			]
		);
		$this->go_to( get_permalink( $clean ) );
		wp_set_sidebars_widgets(
			[
				'sidebar-1'           => [ 'block-2' ],
				'wp_inactive_widgets' => [],
			]
		);
		$reads = 0;
		add_filter(
			'option_widget_block',
			function ( $value ) use ( &$reads ) {
				$reads++;
				return $value;
			}
		);
		WooCommerce_Content_Detector::reset_memo();
		WooCommerce_Content_Detector::current_request_has_woocommerce_content();
		$after_first = $reads;
		WooCommerce_Content_Detector::current_request_has_woocommerce_content();
		$this->assertSame( $after_first, $reads, 'Second call must not re-read options (memoized).' );
		$this->assertGreaterThan( 0, $after_first, 'Sanity: the widget source ran on the first call.' );
	}
}
