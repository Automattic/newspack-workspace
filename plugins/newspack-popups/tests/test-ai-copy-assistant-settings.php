<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test the AI Copy Assistant settings section and its opt-in gate.
 *
 * The feature is hidden until an administrator opts in (union/AI-policy
 * requirement): the publisher-profile section only appears once opted in, and
 * the create endpoint refuses to run otherwise.
 *
 * @package Newspack_Popups
 */

/**
 * AI Copy Assistant settings test.
 */
class AiCopyAssistantSettingsTest extends WP_UnitTestCase {

	/**
	 * Reset the opt-in and profile options between tests.
	 */
	public function tear_down() {
		delete_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION );
		foreach ( [ 'publisher_name', 'coverage_area', 'voice', 'additional_guidance' ] as $key ) {
			delete_option( 'newspack_contextual_prompts_' . $key );
		}
		parent::tear_down();
	}

	/**
	 * Opt in for tests that need the section present.
	 */
	private function opt_in() {
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
	}

	/**
	 * The feature is off by default and the profile section is hidden.
	 */
	public function test_hidden_until_opted_in() {
		$this->assertFalse( Newspack_Popups_Settings::is_ai_copy_assistant_enabled() );

		$grouped = Newspack_Popups_Settings::get_settings( true );
		$this->assertArrayNotHasKey( 'ai_copy_assistant', $grouped, 'Profile fields are hidden until opt-in.' );
	}

	/**
	 * Once opted in, the section appears with an always-on header and the four
	 * profile fields.
	 */
	public function test_section_exposed_after_opt_in() {
		$this->opt_in();
		$this->assertTrue( Newspack_Popups_Settings::is_ai_copy_assistant_enabled() );

		$grouped = Newspack_Popups_Settings::get_settings( true );
		$this->assertArrayHasKey( 'ai_copy_assistant', $grouped );

		$keys = wp_list_pluck( $grouped['ai_copy_assistant'], 'key' );
		$this->assertContains( 'active', $keys );
		$this->assertContains( 'newspack_contextual_prompts_publisher_name', $keys );
		$this->assertContains( 'newspack_contextual_prompts_coverage_area', $keys );
		$this->assertContains( 'newspack_contextual_prompts_voice', $keys );
		$this->assertContains( 'newspack_contextual_prompts_additional_guidance', $keys );

		$header = current(
			array_filter(
				$grouped['ai_copy_assistant'],
				function ( $item ) {
					return 'active' === $item['key'];
				}
			)
		);
		$this->assertNull( $header['value'], 'Header is always-on (no toggle).' );
	}

	/**
	 * A field update persists to the option the Manager reads.
	 */
	public function test_field_update_persists_to_option() {
		$this->opt_in();

		$updated = Newspack_Popups_Settings::update_setting(
			'ai_copy_assistant',
			'newspack_contextual_prompts_coverage_area',
			'San Diego County'
		);

		$this->assertNotWPError( $updated );
		$this->assertSame( 'San Diego County', get_option( 'newspack_contextual_prompts_coverage_area' ) );
	}

	/**
	 * The create endpoint refuses to run when the feature is not opted into.
	 */
	public function test_create_endpoint_blocked_when_disabled() {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$request = new WP_REST_Request( 'POST', '/newspack-popups/v1/contextual-prompt' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'body', 'Fund the reporting.' );

		$blocked = Newspack_Popups_Api::api_create_contextual_prompt( $request );
		$this->assertWPError( $blocked );
		$this->assertSame( 'newspack_contextual_prompts_disabled', $blocked->get_error_code() );

		// After opting in, the same request creates a prompt.
		$this->opt_in();
		$created = Newspack_Popups_Api::api_create_contextual_prompt( $request );
		$this->assertNotWPError( $created );
	}
}
