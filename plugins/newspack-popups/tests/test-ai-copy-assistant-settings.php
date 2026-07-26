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
	 * Native-CTA checks need the donate block registered — use_donate_block()
	 * falls back to a button when it isn't. Newspack Blocks is not loaded in
	 * this test env, so register a stub.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'newspack-blocks/donate' ) ) {
			register_block_type( 'newspack-blocks/donate' );
		}
	}

	/**
	 * Reset the opt-in, profile and override options between tests.
	 */
	public function tear_down() {
		delete_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION );
		delete_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION );
		delete_option( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION );
		delete_option( 'newspack_contextual_prompts_override_body' );
		delete_option( 'newspack_contextual_prompts_override_label' );
		delete_option( 'newspack_contextual_prompts_override_url' );
		foreach ( wp_list_pluck( Newspack_Popups_Settings::get_ai_copy_assistant_fields(), 'key' ) as $key ) {
			delete_option( $key );
		}
		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );
		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
		if ( WP_Block_Type_Registry::get_instance()->is_registered( 'newspack-blocks/donate' ) ) {
			unregister_block_type( 'newspack-blocks/donate' );
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
	 * The override CTA toggle exists only where a native donate form exists; the
	 * button label/URL fields are always present, and therefore always saveable.
	 */
	public function test_override_cta_toggle_only_on_native_sites() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );
		$native_keys = wp_list_pluck( Newspack_Popups_Settings::get_ai_copy_assistant_fields(), 'key' );
		$this->assertContains( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION, $native_keys );
		$this->assertContains( 'newspack_contextual_prompts_override_label', $native_keys );
		$this->assertContains( 'newspack_contextual_prompts_override_url', $native_keys );
		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );

		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
		$button_keys = wp_list_pluck( Newspack_Popups_Settings::get_ai_copy_assistant_fields(), 'key' );
		$this->assertNotContains( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION, $button_keys );
		$this->assertContains( 'newspack_contextual_prompts_override_label', $button_keys );
		$this->assertContains( 'newspack_contextual_prompts_override_url', $button_keys );
	}

	/**
	 * The CTA choice is whitelisted: anything but 'button' stores 'form'.
	 */
	public function test_override_cta_is_whitelisted() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );

		Newspack_Popups_Settings::save_ai_copy_assistant_fields( [ Newspack_Popups_Settings::OVERRIDE_CTA_OPTION => 'button' ] );
		$this->assertSame( 'button', get_option( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION ) );
		$this->assertSame( 'button', Newspack_Popups_Settings::get_override_cta() );

		Newspack_Popups_Settings::save_ai_copy_assistant_fields( [ Newspack_Popups_Settings::OVERRIDE_CTA_OPTION => '<script>alert(1)</script>' ] );
		$this->assertSame( 'form', get_option( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION ) );
		$this->assertSame( 'form', Newspack_Popups_Settings::get_override_cta() );
	}

	/**
	 * Gating follows the effective CTA: button mode requires a URL, form mode
	 * activates on body copy alone, off-site sites are always button mode.
	 */
	public function test_override_active_requires_url_only_in_button_mode() {
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( 'newspack_contextual_prompts_override_body', 'Fund us.' );

		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );
		update_option( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION, 'form' );
		$this->assertTrue( Newspack_Popups_Settings::is_override_active(), 'Native + form: body alone activates.' );

		update_option( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION, 'button' );
		$this->assertFalse( Newspack_Popups_Settings::is_override_active(), 'Button mode without a URL stays inactive.' );

		update_option( 'newspack_contextual_prompts_override_url', 'https://example.com/drive/' );
		$this->assertTrue( Newspack_Popups_Settings::is_override_active() );
		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );

		delete_option( 'newspack_contextual_prompts_override_url' );
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
		update_option( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION, 'form' );
		$this->assertFalse( Newspack_Popups_Settings::is_override_active(), 'Off-site sites are always button mode: no URL, no override.' );
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
	 * The publisher name is prefilled with the site title; saving it back stores ''
	 * so the name keeps following future site-title changes instead of freezing.
	 */
	public function test_publisher_name_matching_site_title_stores_empty() {
		update_option( 'blogname', 'The Daily Example' );

		Newspack_Popups_Settings::save_ai_copy_assistant_fields(
			[ 'newspack_contextual_prompts_publisher_name' => 'The Daily Example' ]
		);
		$this->assertSame(
			'',
			get_option( 'newspack_contextual_prompts_publisher_name', '' ),
			'Saving the site title as the publisher name stores nothing.'
		);

		// A distinct name is still persisted verbatim.
		Newspack_Popups_Settings::save_ai_copy_assistant_fields(
			[ 'newspack_contextual_prompts_publisher_name' => 'Example Newsroom' ]
		);
		$this->assertSame( 'Example Newsroom', get_option( 'newspack_contextual_prompts_publisher_name' ) );
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
