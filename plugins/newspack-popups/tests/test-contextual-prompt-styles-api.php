<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class Contextual Prompt Styles API Test
 *
 * The wizard's Style section rides the existing status/profile endpoints:
 * status carries the style payload, profile save carries optional overrides.
 *
 * @package Newspack_Popups
 */

/**
 * Contextual Prompt styles API test case.
 */
class ContextualPromptStylesApiTest extends WP_UnitTestCase {
	/**
	 * Admin user, feature opted in, routes registered.
	 */
	public function set_up() {
		parent::set_up();
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
		delete_option( Newspack_Popups_Contextual_Prompt_Styles::OPTION_NAME );
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init' );
	}

	/**
	 * Status carries the style payload.
	 */
	public function test_status_includes_style_payload() {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/contextual-prompt/status' ) );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'is_block_theme', $data );
		// No overrides travels as an empty object, never an empty array.
		$this->assertEquals( (object) [], $data['styles'] );
		$this->assertSame( '{}', wp_json_encode( $data['styles'] ) );
		$this->assertSame( '#f7f7f7', $data['style_defaults']['color']['background'] );
		$this->assertNotEmpty( $data['style_palette'] );
		$this->assertArrayHasKey( 'color', $data['style_palette'][0] );
		$this->assertNotEmpty( $data['style_font_sizes'] );
		$this->assertArrayHasKey( 'size', $data['style_font_sizes'][0] );
		$this->assertStringContainsString( 'site-editor.php', $data['site_editor_styles_url'] );
	}

	/**
	 * Profile save with styles persists them and echoes them in the response.
	 */
	public function test_save_profile_with_styles() {
		$request = new WP_REST_Request( 'POST', '/newspack-popups/v1/contextual-prompt/profile' );
		$request->set_body_params(
			[
				'fields' => [],
				'styles' => [
					'color' => [ 'background' => '#123456' ],
					'evil'  => [ 'x' => 'y' ],
				],
			]
		);
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( '#123456', $data['styles']['color']['background'] );
		$this->assertArrayNotHasKey( 'evil', $data['styles'] );
		$this->assertSame(
			[ 'color' => [ 'background' => '#123456' ] ],
			Newspack_Popups_Contextual_Prompt_Styles::get_styles()
		);
	}

	/**
	 * Omitting styles leaves the stored option untouched; an explicit empty
	 * object clears it.
	 */
	public function test_save_profile_styles_semantics() {
		Newspack_Popups_Contextual_Prompt_Styles::save_styles( [ 'color' => [ 'text' => '#fedcba' ] ] );

		$request = new WP_REST_Request( 'POST', '/newspack-popups/v1/contextual-prompt/profile' );
		$request->set_body_params( [ 'fields' => [] ] );
		rest_do_request( $request );
		$this->assertNotEmpty( Newspack_Popups_Contextual_Prompt_Styles::get_styles() );

		$request = new WP_REST_Request( 'POST', '/newspack-popups/v1/contextual-prompt/profile' );
		$request->set_body_params(
			[
				'fields' => [],
				'styles' => [],
			]
		);
		rest_do_request( $request );
		$this->assertSame( [], Newspack_Popups_Contextual_Prompt_Styles::get_styles() );
	}
}
