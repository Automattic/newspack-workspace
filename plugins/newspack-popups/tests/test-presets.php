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
		parent::set_up();

		// Remove any popups (from previous tests).
		foreach ( Newspack_Popups_Model::retrieve_popups() as $popup ) {
			\wp_delete_post( $popup['id'] );
		}

		\delete_option( Newspack_Popups_Presets::NEWSPACK_POPUPS_RAS_PROMPTS_OPTION );
		Newspack_Segments_Model::delete_all_segments();
	}

	/**
	 * Set the current user to one holding the prompt-management capability.
	 *
	 * Deliberately an editor, not an administrator: the gate is `edit_others_pages`
	 * via a filterable capability, and an administrator would satisfy any capability
	 * this were later tightened to, hiding the regression from this suite.
	 */
	private function login_as_prompt_manager() {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	/**
	 * Render a preset the way generate_popup() does, and return the output.
	 *
	 * @param array $preset Preset popup object.
	 * @return string Rendered markup.
	 */
	private function render_preset( $preset ) {
		$body = '';
		foreach ( \parse_blocks( $preset['content'] ) as $block ) {
			$body .= \render_block( $block );
		}
		return \do_shortcode( $body );
	}

	/**
	 * Preset previews render request-supplied input, so they are limited to users
	 * who can manage prompts, the same way single-prompt previews are.
	 */
	public function test_preset_popup_requires_prompt_management_capability() {
		$this->assertNull(
			Newspack_Popups_Presets::retrieve_preset_popup( 'ras_registration_overlay' ),
			'A visitor must not be able to render a preset preview.'
		);

		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertNull(
			Newspack_Popups_Presets::retrieve_preset_popup( 'ras_registration_overlay' ),
			'A subscriber must not be able to render a preset preview.'
		);

		$this->login_as_prompt_manager();
		$preset = Newspack_Popups_Presets::retrieve_preset_popup( 'ras_registration_overlay' );
		$this->assertIsArray( $preset, 'A user who can manage prompts can still render a preset preview.' );
		$this->assertArrayHasKey( 'content', $preset );
	}

	/**
	 * A visitor appending ?preset= must not put the request into preview mode, which
	 * would suppress every prompt on the page and swap the reader data store.
	 */
	public function test_preset_param_is_inert_for_visitors() {
		$_GET['preset'] = 'ras_registration_overlay';
		try {
			\wp_set_current_user( 0 );
			$this->assertNull( Newspack_Popups::preset_popup_id(), 'A visitor gets no preset slug.' );
			$this->assertFalse( Newspack_Popups::is_preview_request(), 'A visitor does not enter preview mode.' );
			$this->assertSame(
				[],
				Newspack_Popups_Inserter::popups_for_post(),
				'A visitor gets an empty popup list, not [ null ].'
			);

			$this->login_as_prompt_manager();
			$this->assertSame( 'ras_registration_overlay', Newspack_Popups::preset_popup_id() );
			$this->assertTrue( Newspack_Popups::is_preview_request() );
		} finally {
			unset( $_GET['preset'] );
		}
	}

	/**
	 * Preview override values are spliced into content that is later rendered
	 * through do_shortcode(), so they must not be able to introduce a shortcode.
	 */
	public function test_preset_override_values_cannot_inject_shortcodes() {
		$ran = false;
		\add_shortcode(
			'newspack_test_injection',
			function () use ( &$ran ) {
				$ran = true;
				return 'INJECTED';
			}
		);

		try {
			$this->login_as_prompt_manager();
			// This preset puts both placeholders inside core blocks, so the override
			// value ends up in rendered body text — the same place generate_popup()
			// runs do_shortcode() over.
			$_GET['preset'] = 'ras_newsletter_overlay';
			$_GET['values'] = [
				'body'    => '[newspack_test_injection]',
				'heading' => 'Safe heading',
			];

			$preset = Newspack_Popups_Presets::retrieve_preset_popup( 'ras_newsletter_overlay' );
			$this->assertIsArray( $preset );
			$rendered = $this->render_preset( $preset );

			$this->assertFalse( $ran, 'An injected shortcode must not execute from a preview override value.' );
			$this->assertStringNotContainsString( 'INJECTED', $rendered, 'Injected shortcode output must not reach the page.' );
			$this->assertStringNotContainsString(
				'[newspack_test_injection]',
				$preset['content'],
				'Shortcode delimiters must not survive into preset content.'
			);
			// Asserted decoded, not as the entity: the reader still sees their brackets,
			// and a later change that double-encoded them would fail here.
			$this->assertStringContainsString(
				'[newspack_test_injection]',
				html_entity_decode( $rendered ),
				'The editor sees the brackets as literal text.'
			);
			$this->assertStringContainsString(
				'Safe heading',
				$preset['content'],
				'Ordinary override values still populate the preview.'
			);
		} finally {
			\remove_shortcode( 'newspack_test_injection' );
			unset( $_GET['values'], $_GET['preset'] );
		}
	}

	/**
	 * Most placeholders are substituted inside a block delimiter's JSON attribute
	 * object, which parse_blocks() runs through json_decode(). A JSON escape
	 * sequence must not be able to reconstitute a bracket after it was stripped.
	 */
	public function test_preset_override_values_cannot_inject_shortcodes_via_json_escape() {
		$ran = false;
		\add_shortcode(
			'newspack_test_injection',
			function () use ( &$ran ) {
				$ran = true;
				return 'INJECTED';
			}
		);

		try {
			$this->login_as_prompt_manager();
			$_GET['preset'] = 'ras_registration_overlay';
			// A backslash-u escape for each bracket. Built with chr( 92 ) so the
			// backslash is unambiguous in source: json_decode() inside parse_blocks()
			// would turn these back into real brackets after the strip has run.
			$escaped_open  = chr( 92 ) . 'u005B';
			$escaped_close = chr( 92 ) . 'u005D';
			// wp_slash() because WordPress slashes superglobals in wp_magic_quotes(),
			// and get_override_values() unslashes. Assigning the raw string here would
			// have the unslash eat the backslash and the test would prove nothing.
			$_GET['values'] = \wp_slash(
				[
					'body' => $escaped_open . 'newspack_test_injection' . $escaped_close,
				]
			);

			$preset = Newspack_Popups_Presets::retrieve_preset_popup( 'ras_registration_overlay' );
			$this->assertIsArray( $preset );

			$blocks = \parse_blocks( $preset['content'] );
			$this->assertNotEmpty( $blocks );
			$description = $blocks[0]['attrs']['description'] ?? '';
			$this->assertStringNotContainsString(
				'[newspack_test_injection]',
				$description,
				'A JSON escape sequence must not reconstitute shortcode delimiters after parsing.'
			);

			$rendered = $this->render_preset( $preset );
			$this->assertFalse( $ran, 'An injected shortcode must not execute via a JSON escape sequence.' );
			$this->assertStringNotContainsString( 'INJECTED', $rendered );
		} finally {
			\remove_shortcode( 'newspack_test_injection' );
			unset( $_GET['values'], $_GET['preset'] );
		}
	}

	/**
	 * A quote in ordinary editorial copy must not break the block it is substituted
	 * into, and must not let a value inject sibling block attributes.
	 */
	public function test_preset_override_values_cannot_break_out_of_block_attributes() {
		try {
			$this->login_as_prompt_manager();
			$_GET['preset'] = 'ras_registration_overlay';
			$_GET['values'] = [
				'heading'      => 'Say "hi" to us',
				'button_label' => 'Sign up","className":"INJECTED-CLASS',
			];

			$preset = Newspack_Popups_Presets::retrieve_preset_popup( 'ras_registration_overlay' );
			$this->assertIsArray( $preset );

			$blocks = \parse_blocks( $preset['content'] );
			$this->assertNotEmpty( $blocks );
			$attrs = $blocks[0]['attrs'];

			$this->assertIsArray(
				$attrs,
				'A quote in ordinary copy must not make the block attributes unparseable.'
			);
			$this->assertArrayNotHasKey(
				'className',
				$attrs,
				'An override value must not be able to add block attributes.'
			);
			$this->assertStringNotContainsString( 'INJECTED-CLASS', $this->render_preset( $preset ) );
		} finally {
			unset( $_GET['values'], $_GET['preset'] );
		}
	}

	/**
	 * The featured image override never passes through process_user_inputs(), so it
	 * is guarded on its own. It reaches the popup object under `options`, not at the
	 * top level.
	 */
	public function test_preset_featured_image_override_is_sanitized() {
		try {
			$this->login_as_prompt_manager();
			$_GET['preset'] = 'ras_registration_overlay';
			$_GET['values'] = [ 'featured_image_id' => '12[newspack_test_injection]' ];

			$preset = Newspack_Popups_Presets::retrieve_preset_popup( 'ras_registration_overlay' );
			$this->assertIsArray( $preset );
			$this->assertArrayHasKey( 'featured_image_id', $preset['options'] );
			$this->assertSame(
				12,
				$preset['options']['featured_image_id'],
				'The featured image override reaches the popup as an integer.'
			);
		} finally {
			unset( $_GET['values'], $_GET['preset'] );
		}
	}

	/**
	 * Array keys land in the same block-delimiter JSON as the values. A key carrying
	 * a bracket puts a live delimiter next to an attacker-chosen tag name, with the
	 * closing one supplied by the JSON array itself.
	 */
	public function test_preset_override_array_keys_cannot_inject_shortcodes() {
		$ran = false;
		\add_shortcode(
			'newspack_test_injection',
			function () use ( &$ran ) {
				$ran = true;
				return 'INJECTED';
			}
		);

		try {
			$this->login_as_prompt_manager();
			// Parsed the way PHP parses the query string, which permits `[` inside a key.
			\parse_str(
				'preset=ras_newsletter_inline&values[body]={{lists}}&values[lists][[newspack_test_injection%3D1][]=x',
				$parsed
			);
			$this->assertArrayHasKey(
				'[newspack_test_injection=1',
				$parsed['values']['lists'],
				'The payload parses into an array key, as PHP permits.'
			);

			$_GET['preset'] = $parsed['preset'];
			$_GET['values'] = \wp_slash( $parsed['values'] );

			$preset = Newspack_Popups_Presets::retrieve_preset_popup( 'ras_newsletter_inline' );
			$this->assertIsArray( $preset );

			// Positive control: the payload did reach the content, encoded. Without it
			// the assertions below would also pass if the override never arrived.
			$this->assertStringContainsString(
				'&#91;newspack_test_injection=1',
				$preset['content'],
				'The key reached the content with its bracket encoded.'
			);

			$rendered = $this->render_preset( $preset );
			$this->assertFalse( $ran, 'A bracket in an array key must not execute a shortcode.' );
			$this->assertStringNotContainsString( 'INJECTED', $rendered );
		} finally {
			\remove_shortcode( 'newspack_test_injection' );
			unset( $_GET['values'], $_GET['preset'] );
		}
	}

	/**
	 * A scalar sent to the array-typed field would be JSON-encoded as a string, and
	 * the subscribe block's render then calls array_intersect() on it.
	 */
	public function test_preset_scalar_override_for_a_list_field_falls_back() {
		try {
			$this->login_as_prompt_manager();
			$_GET['preset'] = 'ras_newsletter_inline';
			$_GET['values'] = [ 'lists' => 'x' ];

			$preset = Newspack_Popups_Presets::retrieve_preset_popup( 'ras_newsletter_inline' );
			$this->assertIsArray( $preset );
			$this->assertStringNotContainsString(
				'"lists": "x"',
				$preset['content'],
				'A scalar must not reach the list field.'
			);
			$this->assertNotEmpty(
				$this->render_preset( $preset ),
				'The preview still renders rather than throwing on the scalar.'
			);
		} finally {
			unset( $_GET['values'], $_GET['preset'] );
		}
	}

	/**
	 * `lists` is the one array-typed override, and it is substituted as JSON, so it
	 * has to stay a list rather than becoming an object.
	 */
	public function test_preset_list_override_stays_a_json_array() {
		try {
			$this->login_as_prompt_manager();
			$_GET['preset'] = 'ras_newsletter_overlay';
			$_GET['values'] = [ 'lists' => [ '1', '2' ] ];

			$preset = Newspack_Popups_Presets::retrieve_preset_popup( 'ras_newsletter_overlay' );
			$this->assertIsArray( $preset );
			$this->assertStringContainsString(
				'"lists": ["1","2"]',
				$preset['content'],
				'An array override is encoded as a JSON array, not an object.'
			);
			$subscribe = null;
			foreach ( \parse_blocks( $preset['content'] ) as $block ) {
				if ( 'newspack-newsletters/subscribe' === $block['blockName'] ) {
					$subscribe = $block;
					break;
				}
			}
			$this->assertNotNull( $subscribe, 'The subscribe block is present.' );
			$this->assertSame(
				[ '1', '2' ],
				$subscribe['attrs']['lists'] ?? null,
				'The block parses with lists as a PHP list.'
			);
		} finally {
			unset( $_GET['values'], $_GET['preset'] );
		}
	}

	/**
	 * "0" is falsy in PHP but a legitimate thing for an editor to preview, so it has
	 * to survive the whole path, not just the substitution.
	 */
	public function test_preset_override_of_zero_reaches_the_preview() {
		try {
			$this->login_as_prompt_manager();
			$_GET['preset'] = 'ras_newsletter_overlay';
			$_GET['values'] = [ 'heading' => '0' ];

			$preset = Newspack_Popups_Presets::retrieve_preset_popup( 'ras_newsletter_overlay' );
			$this->assertIsArray( $preset );
			$this->assertStringContainsString(
				'<h2>0</h2>',
				$preset['content'],
				'An override of "0" reaches the preview instead of falling back to the default.'
			);
		} finally {
			unset( $_GET['values'], $_GET['preset'] );
		}
	}

	/**
	 * Override values are request-scoped preview input. They must not reach
	 * get_ras_presets() on the paths that persist prompts.
	 */
	public function test_override_values_are_ignored_without_a_preset_request() {
		try {
			$this->login_as_prompt_manager();
			$_GET['values'] = [ 'heading' => 'Override heading' ];

			$presets = Newspack_Popups_Presets::get_ras_presets();
			$this->assertIsArray( $presets );
			foreach ( $presets['prompts'] as $prompt ) {
				$this->assertStringNotContainsString(
					'Override heading',
					$prompt['content'],
					'Request values must not be applied outside a preset preview.'
				);
			}
		} finally {
			unset( $_GET['values'] );
		}
	}

	/**
	 * The override value is encoded at the substitution point too, so any caller
	 * passing one gets the same treatment.
	 */
	public function test_process_user_inputs_encodes_delimiters_in_override() {
		$field = [
			'name'    => 'body',
			'type'    => 'string',
			'default' => 'Default body',
		];

		$this->assertSame(
			'<p>&#91;evil attr="x"&#93;</p>',
			Newspack_Popups_Presets::process_user_inputs( '<p>{{body}}</p>', $field, '[evil attr="x"]' ),
			'Square brackets are encoded in an override value, not removed.'
		);

		$this->assertSame(
			'<p>Default body</p>',
			Newspack_Popups_Presets::process_user_inputs( '<p>{{body}}</p>', $field ),
			'Without an override, the default value is used unchanged.'
		);

		$this->assertSame(
			'<p>0</p>',
			Newspack_Popups_Presets::process_user_inputs( '<p>{{body}}</p>', $field, '0' ),
			'An override of "0" is a value, not an absent one.'
		);

		$this->assertSame(
			'<p>Default body</p>',
			Newspack_Popups_Presets::process_user_inputs( '<p>{{body}}</p>', $field, '' ),
			'An empty override falls back to the default.'
		);
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
