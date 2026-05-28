<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test presets
 *
 * @package Newspack_Popups
 */

/**
 * Test Schemas
 */
class PresetsTest extends WP_UnitTestCase {
	/**
	 * Delete prompts, segments, and user inputs from prior tests.
	 */
	public function set_up() {
		// Remove any popups (from previous tests).
		foreach ( Newspack_Popups_Model::retrieve_popups() as $popup ) {
			\wp_delete_post( $popup['id'] );
		}

		\delete_option( Newspack_Popups_Presets::NEWSPACK_POPUPS_RAS_PROMPTS_OPTION );
		Newspack_Segments_Model::delete_all_segments();
	}

	/**
	 * Test fetching the raw presets data from JSON.
	 */
	public function test_fetch_presets() {
		$presets = Newspack_Popups_Presets::get_ras_presets();

		$this->assertTrue( isset( $presets['prompts'] ) && isset( $presets['segments'] ) && isset( $presets['campaigns'] ), 'The fetched presets match the JSON configuration.' );
		$this->assertEquals( 5, count( $presets['prompts'] ), 'The fetched presets match the JSON configuration.' );
		$this->assertEquals( 3, count( $presets['segments'] ), 'The fetched presets match the JSON configuration.' );
		$this->assertEquals( 1, count( $presets['campaigns'] ), 'The fetched presets match the JSON configuration.' );
	}

	/**
	 * Test fetching presets data with user inputs.
	 */
	public function test_preset_user_input() {
		$user_inputs = [
			'heading'           => 'Test Heading copy',
			'body'              => 'Test Body copy',
			'button_label'      => 'Test Button Label',
			'success_message'   => 'Test Success Message',
			'featured_image_id' => 123,
			'lists'             => [ 1, 2, 3 ],
			'invalid_field'     => 'invalid',
		];

		// Test with invalid preset slug.
		$this->assertTrue( \is_wp_error( Newspack_Popups_Presets::update_preset_prompt( 'invalid_slug', $user_inputs ) ), 'Invalid preset slug returns an error.' );

		// Test with invalid field in user inputs.
		$this->assertTrue( \is_wp_error( Newspack_Popups_Presets::update_preset_prompt( 'ras_registration_overlay', $user_inputs ) ), 'Invalid field name for a preset returns an error.' );

		// Remove invalid field.
		unset( $user_inputs['invalid_field'] );

		// Test that data is updated with user inputs.
		$presets = Newspack_Popups_Presets::update_preset_prompt( 'ras_registration_overlay', $user_inputs );
		$index   = 0;
		foreach ( $user_inputs as $field_name => $value ) {
			$this->assertEquals( $value, $presets['prompts'][0]['user_input_fields'][ $index ]['value'], 'Preset data is returned with user inputs attached to each field.' );
			$index++;
		}
	}

	/**
	 * Test activation of presets. Existing prompts and segments should be deactivated.
	 */
	public function test_preset_activation() {
		$post_data           = [
			'post_title'   => 'Preexisting Prompt',
			'post_content' => 'Preexisitng prompt body',
			'post_status'  => 'publish',
			'post_type'    => Newspack_Popups::NEWSPACK_POPUPS_CPT,
		];
		$preexisting_prompt  = \wp_insert_post( $post_data );
		$preexisting_segment = [
			'name'          => 'Preexisting Segment',
			'configuration' => [
				'is_subscribed' => true,
			],
		];
		Newspack_Popups_Segmentation::create_segment( $preexisting_segment );

		// Activate presets.
		$user_inputs = [
			'heading'           => 'Test Heading copy',
			'body'              => 'Test Body copy',
			'button_label'      => 'Test Button Label',
			'success_message'   => 'Test Success Message',
			'featured_image_id' => 123,
			'lists'             => [ 1, 2, 3 ],
		];
		$presets     = Newspack_Popups_Presets::update_preset_prompt( 'ras_registration_overlay', $user_inputs );
		$activated   = Newspack_Popups_Presets::activate_ras_presets();
		$all_prompts = Newspack_Popups_Model::retrieve_popups();

		$preexisting_prompt_object = Newspack_Popups_Model::retrieve_popup_by_id( $preexisting_prompt, false, true );
		$this->assertEquals(
			$preexisting_prompt_object['title'],
			$post_data['post_title']
		);
		$this->assertEquals(
			$preexisting_prompt_object['status'],
			'draft',
			'Preexisting prompt was deactivated'
		);

		$preset_titles        = array_map(
			function( $preset ) {
				return $preset['title'];
			},
			$presets['prompts']
		);
		$active_prompt_titles = array_map(
			function( $prompt ) {
				return $prompt['title'];
			},
			$all_prompts
		);
		$this->assertEmpty( array_diff( $preset_titles, $active_prompt_titles ) );
		$this->assertEquals( count( $presets['prompts'] ), count( $all_prompts ), 'Presets are the only published prompts' );

		$all_segments = Newspack_Popups_Segmentation::get_segments();
		$this->assertEquals( $all_segments[0]['name'], $preexisting_segment['name'] );
		$this->assertTrue( $all_segments[0]['configuration']['is_disabled'], 'Preexisting segment is deactivated' );

		$preset_segment_names    = array_map(
			function( $segment ) {
				return $segment['name'];
			},
			$presets['segments']
		);
		$activated_segment_names = array_map(
			function( $segment ) {
				return $segment['name'];
			},
			array_filter(
				$all_segments,
				function( $segment ) {
					return empty( $segment['options']['is_disabled'] );
				}
			)
		);

		$this->assertEmpty( array_diff( $preset_segment_names, $activated_segment_names ), 'Presets are the only active segments' );
	}
}
