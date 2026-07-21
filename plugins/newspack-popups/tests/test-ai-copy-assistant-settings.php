<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test the AI Copy Assistant settings section.
 *
 * The Audience wizard renders whatever Newspack_Popups_Settings::get_settings()
 * returns, so this section appearing there (with option-backed fields the
 * Manager reads) is the whole contract.
 *
 * @package Newspack_Popups
 */

/**
 * AI Copy Assistant settings test.
 */
class AiCopyAssistantSettingsTest extends WP_UnitTestCase {

	/**
	 * Reset the publisher-profile options between tests.
	 */
	public function tear_down() {
		foreach ( [ 'publisher_name', 'coverage_area', 'voice', 'additional_guidance' ] as $key ) {
			delete_option( 'newspack_contextual_prompts_' . $key );
		}
		parent::tear_down();
	}

	/**
	 * The grouped settings expose an ai_copy_assistant section with an always-on
	 * header and the four profile fields.
	 */
	public function test_section_is_exposed() {
		$grouped = Newspack_Popups_Settings::get_settings( true );

		$this->assertArrayHasKey( 'ai_copy_assistant', $grouped );

		$keys = wp_list_pluck( $grouped['ai_copy_assistant'], 'key' );
		$this->assertContains( 'active', $keys, 'Section header item present.' );
		$this->assertContains( 'newspack_contextual_prompts_publisher_name', $keys );
		$this->assertContains( 'newspack_contextual_prompts_coverage_area', $keys );
		$this->assertContains( 'newspack_contextual_prompts_voice', $keys );
		$this->assertContains( 'newspack_contextual_prompts_additional_guidance', $keys );

		// The header is always-on (no toggle): its value is null.
		$header = current(
			array_filter(
				$grouped['ai_copy_assistant'],
				function ( $item ) {
					return 'active' === $item['key'];
				}
			)
		);
		$this->assertNull( $header['value'] );
	}

	/**
	 * A field update persists to the option the Manager reads.
	 */
	public function test_field_update_persists_to_option() {
		$updated = Newspack_Popups_Settings::update_setting(
			'ai_copy_assistant',
			'newspack_contextual_prompts_coverage_area',
			'San Diego County'
		);

		$this->assertNotWPError( $updated );
		$this->assertSame( 'San Diego County', get_option( 'newspack_contextual_prompts_coverage_area' ) );
	}

	/**
	 * An update to an unknown field in the section is rejected.
	 */
	public function test_unknown_field_is_rejected() {
		$this->assertWPError(
			Newspack_Popups_Settings::update_setting( 'ai_copy_assistant', 'not_a_real_field', 'x' )
		);
	}
}
