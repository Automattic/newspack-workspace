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
		// Reset the memoized conversion-URL cache between tests.
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
		return sprintf(
			'<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"url":"%1$s"} --><div class="wp-block-button"><a class="wp-block-button__link" href="%1$s">Act now</a></div><!-- /wp:button --></div><!-- /wp:buttons -->',
			esc_url( $href )
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
		$page_id = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Support us',
			]
		);
		update_option( \Newspack\Donations::DONATION_PAGE_ID_OPTION, $page_id );

		$hit = Newspack_Popups_Data_Api::classify_href(
			strtolower( get_permalink( $page_id ) ),
			Newspack_Popups_Data_Api::get_site_conversion_urls()
		);
		$this->assertEquals(
			[
				'intent' => 'donation',
				'source' => 'site_config',
			],
			$hit
		);

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
