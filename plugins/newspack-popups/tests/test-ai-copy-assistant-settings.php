<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test the AI Copy Assistant opt-in + publisher-profile settings.
 *
 * The feature is hidden until an administrator opts in (union/AI-policy
 * requirement). Its profile lives behind a dedicated endpoint (not the generic
 * Campaigns settings list), which refuses to run until opted in.
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
		foreach ( wp_list_pluck( Newspack_Popups_Settings::get_ai_copy_assistant_fields(), 'key' ) as $key ) {
			delete_option( $key );
		}
		parent::tear_down();
	}

	/**
	 * Off by default.
	 */
	public function test_disabled_by_default() {
		$this->assertFalse( Newspack_Popups_Settings::is_ai_copy_assistant_enabled() );
	}

	/**
	 * The profile is NOT part of the generic Campaigns settings list — it has its
	 * own card/endpoint, so there is no duplicate "AI Copy Assistant" box.
	 */
	public function test_profile_not_in_general_settings_list() {
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
		$grouped = Newspack_Popups_Settings::get_settings( true );
		$this->assertArrayNotHasKey( 'ai_copy_assistant', $grouped );
	}

	/**
	 * The profile fields expose the keys the Manager reads, with current values.
	 */
	public function test_profile_fields() {
		update_option( 'newspack_contextual_prompts_coverage_area', 'San Diego County' );
		$fields = Newspack_Popups_Settings::get_ai_copy_assistant_fields();
		$keys   = wp_list_pluck( $fields, 'key' );

		$this->assertContains( 'newspack_contextual_prompts_publisher_name', $keys );
		$this->assertContains( 'newspack_contextual_prompts_coverage_area', $keys );
		$this->assertContains( 'newspack_contextual_prompts_voice', $keys );
		$this->assertContains( 'newspack_contextual_prompts_additional_guidance', $keys );

		$coverage = current(
			array_filter(
				$fields,
				function ( $f ) {
					return 'newspack_contextual_prompts_coverage_area' === $f['key'];
				}
			)
		);
		$this->assertSame( 'San Diego County', $coverage['value'] );
	}

	/**
	 * The override button label and URL are plain-button-only: they are offered when
	 * donations are off-site, and hidden when Newspack donations are native (the
	 * donate block owns its own destination, so they would do nothing).
	 */
	public function test_override_button_fields_are_plain_button_only() {
		$button_keys = [ 'newspack_contextual_prompts_override_label', 'newspack_contextual_prompts_override_url' ];

		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
		$plain_keys = wp_list_pluck( Newspack_Popups_Settings::get_ai_copy_assistant_fields(), 'key' );
		foreach ( $button_keys as $key ) {
			$this->assertContains( $key, $plain_keys, 'Plain-button sites expose the override button fields.' );
		}
		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );

		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );
		$native_keys = wp_list_pluck( Newspack_Popups_Settings::get_ai_copy_assistant_fields(), 'key' );
		foreach ( $button_keys as $key ) {
			$this->assertNotContains( $key, $native_keys, 'Native-donation sites hide the override button fields.' );
		}
		// The override toggle and copy still apply in native mode.
		$this->assertContains( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, $native_keys );
		$this->assertContains( 'newspack_contextual_prompts_override_body', $native_keys );
		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );
	}

	/**
	 * Saving persists known keys and ignores anything else.
	 */
	public function test_save_profile_fields() {
		Newspack_Popups_Settings::save_ai_copy_assistant_fields(
			[
				'newspack_contextual_prompts_voice' => 'plainspoken and investigative',
				'not_a_real_field'                  => 'ignored',
			]
		);

		$this->assertSame( 'plainspoken and investigative', get_option( 'newspack_contextual_prompts_voice' ) );
		$this->assertFalse( get_option( 'not_a_real_field' ) );
	}

	/**
	 * The status endpoint reports opt-in state, management capability, and fields.
	 */
	public function test_status_endpoint() {
		$response = Newspack_Popups_Api::api_get_contextual_prompt_status();
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'enabled', $data );
		$this->assertArrayHasKey( 'can_manage', $data );
		$this->assertArrayHasKey( 'fields', $data );
		$this->assertArrayHasKey( 'override_active', $data );

		// Assert on the contract (which fields, grouped how) rather than a count,
		// so adding a field doesn't fail this test spuriously.
		$by_section = [];
		foreach ( $data['fields'] as $field ) {
			$by_section[ $field['section'] ][] = $field['key'];
		}
		$this->assertContains( 'newspack_contextual_prompts_publisher_name', $by_section['profile'] );
		$this->assertContains( 'newspack_contextual_prompts_additional_guidance', $by_section['profile'] );
		$this->assertContains( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, $by_section['override'] );
		$this->assertContains( 'newspack_contextual_prompts_override_body', $by_section['override'] );
	}

	/**
	 * A malformed (non-scalar) value must not silently wipe a saved profile field —
	 * sanitize_textarea_field() would return '' for it without warning.
	 */
	public function test_profile_save_ignores_non_scalar_values() {
		update_option( 'newspack_contextual_prompts_coverage_area', 'San Diego County' );

		Newspack_Popups_Settings::save_ai_copy_assistant_fields(
			[
				'newspack_contextual_prompts_coverage_area' => [ 'unexpected', 'array' ],
			]
		);
		$this->assertSame(
			'San Diego County',
			get_option( 'newspack_contextual_prompts_coverage_area' ),
			'A non-scalar value must be skipped, not blank the existing setting.'
		);

		// A normal scalar still saves.
		Newspack_Popups_Settings::save_ai_copy_assistant_fields(
			[ 'newspack_contextual_prompts_coverage_area' => 'Riverside County' ]
		);
		$this->assertSame( 'Riverside County', get_option( 'newspack_contextual_prompts_coverage_area' ) );
	}

	/**
	 * The profile-save endpoint is inert before opt-in: the feature must read and
	 * write nothing until an administrator opts in.
	 */
	public function test_profile_endpoint_blocked_when_disabled() {
		$profile_request = new WP_REST_Request( 'POST', '/newspack-popups/v1/contextual-prompt/profile' );
		$profile_request->set_param( 'fields', [ 'newspack_contextual_prompts_coverage_area' => 'Somewhere' ] );
		$blocked_profile = Newspack_Popups_Api::api_save_contextual_prompt_profile( $profile_request );
		$this->assertWPError( $blocked_profile );
		$this->assertSame( 'newspack_contextual_prompts_disabled', $blocked_profile->get_error_code() );
		$this->assertSame(
			'',
			get_option( 'newspack_contextual_prompts_coverage_area', '' ),
			'A blocked profile save must not write.'
		);

		// It works once opted in.
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
		$this->assertNotWPError( Newspack_Popups_Api::api_save_contextual_prompt_profile( $profile_request ) );
		$this->assertSame( 'Somewhere', get_option( 'newspack_contextual_prompts_coverage_area', '' ) );
	}
}
