<?php
/**
 * Class Data API Test
 *
 * Covers NPPD-1837: block-less button CTA classification (display-only).
 *
 * @package Newspack_Popups
 */

// The popups suite does not load newspack-plugin, so provide the constant that
// get_site_conversion_urls() reads for the configured donation page.
if ( ! class_exists( '\Newspack\Donations' ) ) {
	require_once __DIR__ . '/mocks/class-donations.php';
}

/**
 * Data API test case.
 */
class DataApiTest extends WP_UnitTestCase {

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		$this->reset_conversion_cache();
	}

	/**
	 * Reset the memoized conversion-URL cache (it is per-request; tests that change
	 * options or permalinks after a prior call must reset before re-reading).
	 */
	private function reset_conversion_cache() {
		$property = new \ReflectionProperty( 'Newspack_Popups_Data_Api', 'conversion_urls_cache' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/**
	 * Build a block-less prompt whose only content is a button linking to $href.
	 *
	 * @param string $href The button target.
	 * @return string Prompt post_content.
	 */
	private function button_prompt( $href ) {
		// Embed the raw URL, matching how Gutenberg serializes core/button attrs.
		// esc_url() would entity-encode characters like "&" and skew classification.
		return sprintf(
			'<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"url":"%1$s"} --><div class="wp-block-button"><a class="wp-block-button__link" href="%1$s">Act now</a></div><!-- /wp:button --></div><!-- /wp:buttons -->',
			$href
		);
	}

	/**
	 * Run get_popup_metadata() + prepare_popup_params_for_ga() on a prompt.
	 *
	 * @param string $content Prompt post_content.
	 * @return array The GA-ready params.
	 */
	private function ga_params_for( $content ) {
		$data = Newspack_Popups_Data_Api::get_popup_metadata(
			[
				'id'      => 123,
				'title'   => 'A prompt',
				'content' => $content,
			]
		);
		return Newspack_Popups_Data_Api::prepare_popup_params_for_ga( $data );
	}

	/**
	 * Assert the guardrail: no conversion flag was written for an inferred intent.
	 *
	 * @param array $params GA params.
	 */
	private function assertNoConversionFlags( $params ) {
		foreach ( array_keys( $params ) as $key ) {
			$this->assertStringStartsNotWith( 'prompt_has_', $key, "Unexpected conversion flag: $key" );
		}
		$this->assertEquals( 'undefined', $params['action_type'], 'action_type must stay undefined for block-less prompts.' );
	}

	/**
	 * A processor domain (fundjournalism) is a donation, and no conversion flag leaks.
	 */
	public function test_processor_donation_emits_display_only_intent() {
		$params = $this->ga_params_for( $this->button_prompt( 'https://fundjournalism.org/donate/campaign/' ) );
		$this->assertEquals( 'donation', $params['inferred_cta_intent'] );
		$this->assertEquals( 'processor', $params['inferred_cta_source'] );
		$this->assertNoConversionFlags( $params );
	}

	/**
	 * A button to the site's configured donation page (read via the option) is site_config.
	 */
	public function test_configured_donation_page_is_site_config() {
		// Pretty permalinks so get_permalink() yields a real path (not a bare host).
		$this->set_permalink_structure( '/%postname%/' );
		$page_id = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_name'  => 'support-us',
				'post_title' => 'Support us',
			]
		);
		update_option( \Newspack\Donations::DONATION_PAGE_ID_OPTION, $page_id );
		$this->reset_conversion_cache();

		$config = Newspack_Popups_Data_Api::get_site_conversion_urls();

		// The donation config entry carries the page's path, not a bare host.
		$this->assertNotEmpty( $config['donation'] );
		$this->assertStringContainsString( 'support-us', $config['donation'][0] );

		$hit = Newspack_Popups_Data_Api::classify_href( strtolower( get_permalink( $page_id ) ), $config );
		$this->assertEquals(
			[
				'intent' => 'donation',
				'source' => 'site_config',
			],
			$hit
		);

		// An unrelated own-domain URL does NOT match the configured donation URL.
		$other = Newspack_Popups_Data_Api::classify_href( 'https://example.org/some-other-page/', $config );
		$this->assertNotEquals( 'site_config', $other['source'] ?? null );

		delete_option( \Newspack\Donations::DONATION_PAGE_ID_OPTION );
		$this->set_permalink_structure( '' );
	}

	/**
	 * Fix 2: a configured page whose permalink normalizes to a bare host (plain
	 * permalinks) is filtered out, so it can't substring-match every same-host link.
	 */
	public function test_bare_host_config_url_is_filtered_out() {
		// Plain permalinks: get_permalink() is "host/?page_id=N", normalizing to bare "host/".
		$this->set_permalink_structure( '' );
		$page_id = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Support us',
			]
		);
		update_option( \Newspack\Donations::DONATION_PAGE_ID_OPTION, $page_id );
		$this->reset_conversion_cache();

		$config = Newspack_Popups_Data_Api::get_site_conversion_urls();
		$this->assertEmpty( $config['donation'], 'Bare-host donation URL should be filtered out.' );

		// With no usable config entry, an arbitrary own-domain URL does not classify as site_config.
		$hit = Newspack_Popups_Data_Api::classify_href( 'https://example.org/anything/', $config );
		$this->assertNotEquals( 'site_config', $hit['source'] ?? null );

		delete_option( \Newspack\Donations::DONATION_PAGE_ID_OPTION );
	}

	/**
	 * A newslettersignup slug is a newsletter (pattern).
	 */
	public function test_newsletter_signup_slug_is_pattern() {
		$config = Newspack_Popups_Data_Api::get_site_conversion_urls();
		$hit    = Newspack_Popups_Data_Api::classify_href( 'https://example.org/orlando-newslettersignup-page/', $config );
		$this->assertEquals(
			[
				'intent' => 'newsletter',
				'source' => 'pattern',
			],
			$hit
		);
	}

	/**
	 * An own-domain dated article slug is editorial (non-conversion).
	 */
	public function test_own_domain_article_is_editorial() {
		$config = Newspack_Popups_Data_Api::get_site_conversion_urls();
		$hit    = Newspack_Popups_Data_Api::classify_href( 'https://example.org/2026/06/12/some-long-slug/story', $config );
		$this->assertEquals( 'editorial', $hit['intent'] );
	}

	/**
	 * Fix 1: a donation keyword inside a longer word (e.g. "member" in "remember")
	 * must not false-positive as donation; the article falls through to editorial.
	 */
	public function test_donation_keyword_midword_does_not_false_positive() {
		$config = Newspack_Popups_Data_Api::get_site_conversion_urls();
		$hit    = Newspack_Popups_Data_Api::classify_href( 'https://example.org/2026/06/12/things-to-remember-this-summer/', $config );
		$this->assertEquals( 'editorial', $hit['intent'] );
	}

	/**
	 * Fix 1 keep-alive: a hyphen/fragment-preceded membership token still matches
	 * donation (the boundary is a non-letter, so the lookbehind allows it).
	 */
	public function test_hyphen_preceded_membership_still_donation() {
		$config = Newspack_Popups_Data_Api::get_site_conversion_urls();
		$hit    = Newspack_Popups_Data_Api::classify_href( 'https://example.org/about/#example-membership', $config );
		$this->assertEquals(
			[
				'intent' => 'donation',
				'source' => 'pattern',
			],
			$hit
		);
	}

	/**
	 * An outbound utm_medium=referral link is a sponsor (non-conversion).
	 */
	public function test_external_referral_is_sponsor() {
		$config = Newspack_Popups_Data_Api::get_site_conversion_urls();
		$hit    = Newspack_Popups_Data_Api::classify_href( 'https://statefarm.com/agents?utm_medium=referral&utm_source=partner', $config );
		$this->assertEquals( 'sponsor', $hit['intent'] );
	}

	/**
	 * Ambiguous targets abstain (null) — precision over recall.
	 *
	 * @dataProvider abstain_hrefs
	 * @param string $href The href that should not classify.
	 */
	public function test_ambiguous_targets_abstain( $href ) {
		$config = Newspack_Popups_Data_Api::get_site_conversion_urls();
		$this->assertNull( Newspack_Popups_Data_Api::classify_href( $href, $config ), "Should abstain: $href" );
	}

	/**
	 * Hrefs that must not classify.
	 *
	 * @return array
	 */
	public function abstain_hrefs() {
		return [
			'query-param form' => [ 'https://example.org/?form=123' ],
			'bare homepage'    => [ 'https://example.org/' ],
			'unknown external' => [ 'https://randomvendor.com/' ],
		];
	}

	/**
	 * A block-less prompt with an unrecognized target emits no inferred params.
	 */
	public function test_abstain_emits_nothing() {
		$params = $this->ga_params_for( $this->button_prompt( 'https://example.org/?form=123' ) );
		$this->assertArrayNotHasKey( 'inferred_cta_intent', $params );
		$this->assertArrayNotHasKey( 'inferred_cta_source', $params );
		$this->assertNoConversionFlags( $params );
	}

	/**
	 * Conflicting conversion intents across buttons abstain (don't guess).
	 */
	public function test_conflicting_conversion_intents_abstain() {
		$content  = $this->button_prompt( 'https://donorbox.org/give' );
		$content .= $this->button_prompt( 'https://account.example.com/subscribe' );
		$this->assertNull( Newspack_Popups_Data_Api::classify_blockless_cta( $content ) );
	}

	/**
	 * A conversion intent wins over a co-occurring non-conversion one.
	 */
	public function test_conversion_wins_over_non_conversion() {
		$content  = $this->button_prompt( 'https://example.org/2026/06/12/some-long-slug/story' );
		$content .= $this->button_prompt( 'https://fundjournalism.org/donate/' );
		$hit      = Newspack_Popups_Data_Api::classify_blockless_cta( $content );
		$this->assertEquals( 'donation', $hit['intent'] );
	}

	/**
	 * Same intent from different sources resolves deterministically to the
	 * highest-confidence source (processor beats pattern).
	 */
	public function test_same_intent_prefers_higher_confidence_source() {
		$content  = $this->button_prompt( 'https://example.org/donate/' ); // Donation via pattern.
		$content .= $this->button_prompt( 'https://fundjournalism.org/give/' ); // Donation via processor.
		$hit      = Newspack_Popups_Data_Api::classify_blockless_cta( $content );
		$this->assertEquals(
			[
				'intent' => 'donation',
				'source' => 'processor',
			],
			$hit
		);
	}

	/**
	 * Buttons nested in a core/buttons wrapper are found by extract_button_hrefs().
	 */
	public function test_extract_button_hrefs_covers_nested_buttons() {
		$hrefs = Newspack_Popups_Data_Api::extract_button_hrefs( $this->button_prompt( 'https://fundjournalism.org/DONATE/' ) );
		$this->assertEquals( [ 'https://fundjournalism.org/donate/' ], $hrefs );
	}

	/**
	 * A prompt WITH a recognized conversion block never runs the classifier.
	 */
	public function test_prompt_with_block_is_unchanged() {
		$content = '<!-- wp:newspack-blocks/donate /-->';
		$data    = Newspack_Popups_Data_Api::get_popup_metadata(
			[
				'id'      => 456,
				'title'   => 'Donate prompt',
				'content' => $content,
			]
		);
		$this->assertArrayNotHasKey( 'inferred_cta_intent', $data );
		$this->assertContains( 'donation', $data['prompt_blocks'] );

		$params = Newspack_Popups_Data_Api::prepare_popup_params_for_ga( $data );
		$this->assertEquals( 1, $params['prompt_has_donation'] );
		$this->assertEquals( 'donation', $params['action_type'] );
		$this->assertArrayNotHasKey( 'inferred_cta_intent', $params );
	}
}
