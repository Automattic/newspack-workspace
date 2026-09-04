<?php
/**
 * Tests the Perfmatters integration (NPPM-2934).
 *
 * @package Newspack\Tests
 */

use Newspack\Perfmatters;
use Newspack\WooCommerce_Content_Detector;

require_once __DIR__ . '/../mocks/newspack-popups-model-mock.php';

/**
 * Tests for Newspack's default Perfmatters configuration.
 *
 * @group perfmatters
 */
class Newspack_Test_Perfmatters extends WP_UnitTestCase {
	/**
	 * Newspack forces RUCSS on, so its defaults must exclude the Complianz
	 * stylesheets: the consent banner markup is injected client-side, its
	 * selectors never reach the server-generated used-CSS snapshot, and the
	 * delayed original stylesheet leaves the banner unstyled until user
	 * interaction or the delay timeout.
	 */
	public function test_complianz_stylesheets_excluded_from_rucss_defaults() {
		$options = Perfmatters::set_defaults( [] );

		$this->assertTrue( $options['assets']['remove_unused_css'] );
		$this->assertContains(
			'plugins/complianz-gdpr',
			$options['assets']['rucss_excluded_stylesheets'],
			'Complianz plugin-dir CSS (free + premium, prefix-matched) must be excluded from RUCSS.'
		);
		$this->assertContains(
			'uploads/complianz',
			$options['assets']['rucss_excluded_stylesheets'],
			'Complianz generated banner CSS in uploads must be excluded from RUCSS.'
		);
	}

	/**
	 * The backstop filter enforces the same exclusions even when a publisher's
	 * saved settings override the defaults, and preserves publisher entries.
	 */
	public function test_complianz_stylesheets_excluded_via_backstop_filter() {
		$this->assertNotFalse(
			has_filter( 'perfmatters_rucss_excluded_stylesheets', [ Perfmatters::class, 'add_rucss_excluded_stylesheets' ] ),
			'The backstop filter must be registered so exclusions apply even when saved settings override the defaults.'
		);

		$exclusions = Perfmatters::add_rucss_excluded_stylesheets( [ 'example-publisher-entry' ] );

		$this->assertContains( 'plugins/complianz-gdpr', $exclusions );
		$this->assertContains( 'uploads/complianz', $exclusions );
		$this->assertContains( 'example-publisher-entry', $exclusions );
	}

	/**
	 * Reset the detector memo before each test.
	 */
	public function set_up() {
		parent::set_up();
		WooCommerce_Content_Detector::reset_memo();
	}

	/**
	 * Reset the above-header flag and the detector memo after each test.
	 */
	public function tear_down() {
		\Newspack_Popups_Model::$has_above_header = false;
		WooCommerce_Content_Detector::reset_memo();
		parent::tear_down();
	}

	/**
	 * Without above-header prompts, the prompt reveal scripts stay in the JS delay queue.
	 */
	public function test_reveal_scripts_delayed_without_above_header_prompts() {
		\Newspack_Popups_Model::$has_above_header = false;

		$options = Perfmatters::set_defaults( [] );

		$this->assertContains( 'newspack-popups', $options['assets']['delay_js_inclusions'] );
		$this->assertContains( 'window.newspack', $options['assets']['delay_js_inclusions'] );
		$this->assertNotContains( 'newspack-popups', $options['assets']['js_exclusions'] );
		$this->assertNotContains( 'newspack-plugin', $options['assets']['js_exclusions'] );
	}

	/**
	 * With published above-header prompts, the reveal scripts are removed from the JS
	 * delay queue and excluded from deferral so the prompts appear immediately.
	 */
	public function test_reveal_scripts_undelayed_with_above_header_prompts() {
		\Newspack_Popups_Model::$has_above_header = true;

		$options = Perfmatters::set_defaults( [] );

		$this->assertNotContains( 'newspack-popups', $options['assets']['delay_js_inclusions'] );
		$this->assertNotContains( 'window.newspack', $options['assets']['delay_js_inclusions'] );
		$this->assertNotContains( 'newspack-plugin', $options['assets']['delay_js_inclusions'] );

		$this->assertContains( 'newspack-popups', $options['assets']['js_exclusions'] );
		$this->assertContains( 'newspack-plugin', $options['assets']['js_exclusions'] );

		// `window.newspack` is an inline token; deferral only applies to external <script src>
		// files, so it is intentionally kept out of the defer exclusions even while present in
		// the delay exclusions. Assert the asymmetry so the two lists cannot silently drift.
		$this->assertNotContains( 'window.newspack', $options['assets']['js_exclusions'] );
	}

	/**
	 * Perfmatters persists its delay list whenever its settings are saved through the UI,
	 * so on a configured site the stored option already contains the reveal scripts. They
	 * must be subtracted from the merged list, not merely omitted from Newspack's own
	 * contribution – otherwise the merge puts them back and the prompts stay delayed on
	 * exactly the sites this targets.
	 */
	public function test_reveal_scripts_undelayed_when_already_in_saved_option() {
		\Newspack_Popups_Model::$has_above_header = true;

		$options = Perfmatters::set_defaults(
			[
				'assets' => [
					'delay_js_inclusions' => [ 'newspack-popups', 'window.newspack', 'newspack-plugin', 'publisher-script' ],
				],
			]
		);

		$this->assertNotContains( 'newspack-popups', $options['assets']['delay_js_inclusions'] );
		$this->assertNotContains( 'window.newspack', $options['assets']['delay_js_inclusions'] );
		$this->assertNotContains( 'newspack-plugin', $options['assets']['delay_js_inclusions'] );

		// The publisher's own entries survive.
		$this->assertContains( 'publisher-script', $options['assets']['delay_js_inclusions'] );
	}

	/**
	 * Without above-header prompts, a saved delay list keeps the reveal scripts.
	 */
	public function test_saved_delay_list_is_preserved_without_above_header_prompts() {
		\Newspack_Popups_Model::$has_above_header = false;

		$options = Perfmatters::set_defaults(
			[
				'assets' => [
					'delay_js_inclusions' => [ 'newspack-popups', 'publisher-script' ],
				],
			]
		);

		$this->assertContains( 'newspack-popups', $options['assets']['delay_js_inclusions'] );
		$this->assertContains( 'publisher-script', $options['assets']['delay_js_inclusions'] );
	}

	/**
	 * Unrelated scripts remain delayed regardless of above-header prompts.
	 */
	public function test_other_scripts_still_delayed_with_above_header_prompts() {
		\Newspack_Popups_Model::$has_above_header = true;

		$options = Perfmatters::set_defaults( [] );

		$this->assertContains( 'newspack-blocks', $options['assets']['delay_js_inclusions'] );
		$this->assertContains( 'recaptcha', $options['assets']['delay_js_inclusions'] );
	}

	/**
	 * When WooCommerce content is present, the callback vetoes the strip
	 * (returns false) regardless of the incoming value.
	 */
	public function test_vetoes_when_wc_content_present() {
		$page = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<!-- wp:woocommerce/product-category /-->',
			]
		);
		$this->go_to( get_permalink( $page ) );
		WooCommerce_Content_Detector::reset_memo();
		$this->assertFalse( Perfmatters::maybe_keep_woocommerce_assets( true ) );
	}

	/**
	 * When no WooCommerce content is present, the callback passes the incoming
	 * value through unchanged.
	 */
	public function test_passes_through_when_no_wc_content() {
		$page = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
			]
		);
		$this->go_to( get_permalink( $page ) );
		WooCommerce_Content_Detector::reset_memo();
		$this->assertTrue( Perfmatters::maybe_keep_woocommerce_assets( true ) );
		$this->assertFalse( Perfmatters::maybe_keep_woocommerce_assets( false ) );
	}

	/**
	 * With NEWSPACK_IGNORE_PERFMATTERS_DEFAULTS defined, the callback returns the
	 * incoming value untouched and never consults the detector.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_ignore_defaults_leaves_backstop_untouched() {
		define( 'NEWSPACK_IGNORE_PERFMATTERS_DEFAULTS', true );

		$input = [ 'example-publisher-entry' ];

		$this->assertSame( $input, Perfmatters::add_rucss_excluded_stylesheets( $input ) );
	}

	/**
	 * A saved option that already carries the manually-applied workaround entry
	 * doesn't end up with duplicates after the defaults merge.
	 */
	public function test_no_duplicate_exclusions_after_merge() {
		$options = Perfmatters::set_defaults(
			[
				'assets' => [
					'rucss_excluded_stylesheets' => [ 'plugins/complianz-gdpr' ],
				],
			]
		);

		$this->assertSame(
			array_unique( $options['assets']['rucss_excluded_stylesheets'] ),
			$options['assets']['rucss_excluded_stylesheets']
		);
		$this->assertContains( 'plugins/complianz-gdpr', $options['assets']['rucss_excluded_stylesheets'] );
	}

	/**
	 * Perfmatters decides whether to delay a stylesheet by substring-matching each
	 * exclusion entry against the whole `<link>` tag (Perfmatters CSS.php, via
	 * Utilities::match_in_array/stripos). Asserting an entry is merely *present* in
	 * the list — as an assertContains check does — cannot tell whether it still
	 * matches anything Jetpack actually serves: NPPM-3167 sat broken across the
	 * fleet for four months because `_inc/social-logos` stopped matching when
	 * Jetpack 15.7 relocated the file to `_inc/build/social-logos/`, and no test
	 * noticed. These tests run the real matcher against the tag shapes Jetpack
	 * emits, so narrowing or dropping an entry fails here rather than on a
	 * publisher's homepage.
	 *
	 * @param string $tag   Full stylesheet link tag as WordPress would emit it.
	 * @param string $label Human-readable description used in the failure message.
	 *
	 * @dataProvider jetpack_share_stylesheet_tags
	 */
	public function test_jetpack_share_stylesheets_match_an_exclusion_entry( $tag, $label ) {
		$exclusions = Perfmatters::add_rucss_excluded_stylesheets( [] );

		$this->assertNotEmpty( $exclusions, 'The defaults must supply exclusion entries.' );

		foreach ( $exclusions as $entry ) {
			if ( '' !== $entry && false !== stripos( $tag, $entry ) ) {
				return;
			}
		}

		$this->fail(
			sprintf(
				'No RUCSS exclusion entry matches %s, so Perfmatters will delay it until the reader interacts with the page. Tag: %s',
				$label,
				$tag
			)
		);
	}

	/**
	 * Both asset URL shapes Jetpack can emit for its share-button styles.
	 *
	 * The CDN variants are what Jetpack's Asset CDN ("Speed up static file load
	 * times") serves, and they drop the `plugins/jetpack/` segment entirely — which
	 * is why an exclusion entry carrying that prefix silently stops matching the
	 * moment a publisher enables the setting. `c0.wp.com` is the CDN's real host and
	 * has to stay real for these rows to mean anything; the site host and the version
	 * numbers are invented.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function jetpack_share_stylesheet_tags() {
		$tag = function ( $handle, $href ) {
			return sprintf( "<link rel='stylesheet' id='%s' href='%s' media='all' />", $handle, $href );
		};

		return [
			'social logos, served from the plugin directory' => [
				$tag( 'social-logos-css', 'https://example.test/wp-content/plugins/jetpack/_inc/build/social-logos/social-logos.css?ver=16.2' ),
				"Jetpack's social logos CSS served from the plugin directory",
			],
			'social logos, served from the Jetpack CDN'  => [
				$tag( 'social-logos-css', 'https://c0.wp.com/p/jetpack/16.2/_inc/build/social-logos/social-logos.css' ),
				"Jetpack's social logos CSS served from the Jetpack CDN",
			],
			'share buttons, served from the plugin directory' => [
				$tag( 'sharedaddy-css', 'https://example.test/wp-content/plugins/jetpack/modules/sharedaddy/sharing.css?ver=16.2' ),
				"Jetpack's share button CSS served from the plugin directory",
			],
			'share buttons, served from the Jetpack CDN' => [
				$tag( 'sharedaddy-css', 'https://c0.wp.com/p/jetpack/16.2/modules/sharedaddy/sharing.css' ),
				"Jetpack's share button CSS served from the Jetpack CDN",
			],
		];
	}
}
