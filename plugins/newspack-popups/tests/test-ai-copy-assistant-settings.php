<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test the AI Copy Assistant opt-in + publisher-profile settings.
 *
 * The feature is hidden until an administrator opts in (union/AI-policy
 * requirement). Its profile lives behind a dedicated endpoint (not the generic
 * Campaigns settings list), and the create endpoint refuses to run until opted in.
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
	 * Design colors are validated: a bad value is stored empty so the render
	 * falls back to the default rather than emitting a broken CSS declaration.
	 */
	public function test_design_colors_are_sanitized() {
		Newspack_Popups_Settings::save_ai_copy_assistant_fields(
			[
				Newspack_Popups_Settings::DESIGN_BACKGROUND_OPTION => '#abcdef',
				Newspack_Popups_Settings::DESIGN_ACCENT_OPTION => 'red; } body { display:none',
			]
		);

		$this->assertSame( '#abcdef', get_option( Newspack_Popups_Settings::DESIGN_BACKGROUND_OPTION ) );
		$this->assertSame( '', get_option( Newspack_Popups_Settings::DESIGN_ACCENT_OPTION ), 'A non-hex value is rejected.' );

		// The rejected value must not reach the stylesheet.
		$css = Newspack_Popups_Post_Scope::get_design_css();
		$this->assertStringNotContainsString( 'display:none', $css );
		$this->assertStringContainsString( '#abcdef', $css );
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

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
		$created = Newspack_Popups_Api::api_create_contextual_prompt( $request );
		$this->assertNotWPError( $created );
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
	 * A prompt_id is authorized against the article that prompt actually belongs to,
	 * not the post_id the caller supplied. Otherwise anyone able to edit a single
	 * post of their own could pass another story's prompt_id and rewrite its copy or
	 * repoint its donate URL.
	 */
	public function test_prompt_id_is_authorized_against_its_own_parent() {
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );

		$owner   = self::factory()->user->create( [ 'role' => 'editor' ] );
		$outsider = self::factory()->user->create( [ 'role' => 'author' ] );

		// A story owned by someone else, carrying a prompt.
		wp_set_current_user( $owner );
		$victim_post = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_author' => $owner,
			]
		);
		$victim_prompt = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'  => $victim_post,
				'body'     => 'Original copy.',
				'position' => 1,
			]
		);

		// The outsider's own post — which they legitimately may edit.
		wp_set_current_user( $outsider );
		$own_post = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_author' => $outsider,
			]
		);

		$request = new WP_REST_Request( 'POST', '/newspack-popups/v1/contextual-prompt' );
		$request->set_param( 'post_id', $own_post ); // Authorized.
		$request->set_param( 'prompt_id', $victim_prompt ); // Someone else's prompt.
		$request->set_param( 'body', 'Hijacked copy.' );
		$request->set_param( 'button_url', 'https://attacker.example/donate' );

		$allowed = Newspack_Popups_Api::contextual_prompt_permission_callback( $request );
		$this->assertWPError( $allowed, 'Cross-post prompt edits are refused.' );
		$this->assertSame( 'newspack_rest_forbidden', $allowed->get_error_code() );

		// The victim's prompt is untouched.
		$this->assertStringContainsString( 'Original copy.', get_post_field( 'post_content', $victim_prompt ) );

		// The owner is still allowed to edit their own story's prompt.
		wp_set_current_user( $owner );
		$ok_request = new WP_REST_Request( 'POST', '/newspack-popups/v1/contextual-prompt' );
		$ok_request->set_param( 'post_id', $victim_post );
		$ok_request->set_param( 'prompt_id', $victim_prompt );
		$ok_request->set_param( 'body', 'Legitimate edit.' );
		$this->assertTrue( Newspack_Popups_Api::contextual_prompt_permission_callback( $ok_request ) );
	}

	/**
	 * Opt-in gating is symmetric: the profile-save and scoped-prompt-read endpoints
	 * are inert before opt-in too, not just the create endpoint. The feature must
	 * read and write nothing until an administrator opts in.
	 */
	public function test_profile_and_read_endpoints_blocked_when_disabled() {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

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

		$get_request = new WP_REST_Request( 'GET', '/newspack-popups/v1/contextual-prompt' );
		$get_request->set_param( 'post_id', $post_id );
		$blocked_get = Newspack_Popups_Api::api_get_scoped_prompt( $get_request );
		$this->assertWPError( $blocked_get );
		$this->assertSame( 'newspack_contextual_prompts_disabled', $blocked_get->get_error_code() );

		// Both work once opted in.
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
		$this->assertNotWPError( Newspack_Popups_Api::api_save_contextual_prompt_profile( $profile_request ) );
		$this->assertSame( 'Somewhere', get_option( 'newspack_contextual_prompts_coverage_area', '' ) );
		$this->assertNotWPError( Newspack_Popups_Api::api_get_scoped_prompt( $get_request ) );
	}
}
