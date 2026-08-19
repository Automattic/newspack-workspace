<?php
/**
 * Tests for Block_Visibility class.
 *
 * @package Newspack\Tests
 * @group Block_Visibility
 */

use Newspack\Block_Visibility;

/**
 * Block_Visibility test case.
 */
class Newspack_Test_Block_Visibility extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	private $test_user_id;

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();
		$this->test_user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );

		// Register a simple test rule: passes only for our test user.
		// Guard against duplicate registration: Access_Rules::$rules is static and
		// persists across test methods within the same PHP process.
		$registered = \Newspack\Access_Rules::get_registered_rules();
		if ( ! isset( $registered['test_rule'] ) ) {
			\Newspack\Access_Rules::register_rule(
				[
					'id'       => 'test_rule',
					'name'     => 'Test Rule',
					'callback' => function( $user_id, $value ) {
						return intval( $user_id ) === intval( $value );
					},
				]
			);
		}
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		Block_Visibility::reset_cache_for_tests();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Test that the Block_Visibility class exists.
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'Newspack\Block_Visibility' ) );
	}

	/**
	 * Test that the render_block filter is registered.
	 */
	public function test_render_block_filter_registered() {
		$this->assertNotFalse(
			has_filter( 'render_block', [ 'Newspack\Block_Visibility', 'filter_render_block' ] )
		);
	}

	/**
	 * Test that the enqueue_block_editor_assets action is registered.
	 */
	public function test_enqueue_block_editor_assets_action_registered() {
		$this->assertNotFalse(
			has_action( 'enqueue_block_editor_assets', [ 'Newspack\Block_Visibility', 'enqueue_block_editor_assets' ] )
		);
	}

	/**
	 * Test that the register_block_type_args filter is registered.
	 */
	public function test_register_block_type_args_filter_registered() {
		$this->assertNotFalse(
			has_filter( 'register_block_type_args', [ 'Newspack\Block_Visibility', 'register_block_type_args' ] )
		);
	}

	/**
	 * Helper to build a mock block array.
	 *
	 * @param string $name  Block name.
	 * @param array  $attrs Block attributes.
	 * @return array
	 */
	private function make_block( $name, $attrs = [] ) {
		return [
			'blockName' => $name,
			'attrs'     => $attrs,
			'innerHTML' => '<div>content</div>',
		];
	}

	/**
	 * Test that non-target blocks pass through unchanged.
	 */
	public function test_non_target_block_passes_through() {
		$result = Block_Visibility::filter_render_block( '<p>hello</p>', $this->make_block( 'core/paragraph' ) );
		$this->assertSame( '<p>hello</p>', $result );
	}

	/**
	 * Test that a target block with no attrs passes through unchanged.
	 */
	public function test_target_block_with_no_rules_passes_through() {
		$result = Block_Visibility::filter_render_block( '<div>hi</div>', $this->make_block( 'core/group', [] ) );
		$this->assertSame( '<div>hi</div>', $result );
	}

	/**
	 * Test that a target block with an empty rules object passes through unchanged.
	 */
	public function test_target_block_with_empty_rules_object_passes_through() {
		$result = Block_Visibility::filter_render_block(
			'<div>hi</div>',
			$this->make_block( 'core/group', [ 'newspackAccessControlRules' => [] ] )
		);
		$this->assertSame( '<div>hi</div>', $result );
	}

	/**
	 * Test that a target block with only inactive rules passes through unchanged.
	 */
	public function test_target_block_with_inactive_rules_passes_through() {
		$result = Block_Visibility::filter_render_block(
			'<div>hi</div>',
			$this->make_block(
				'core/group',
				[
					'newspackAccessControlRules' => [
						'registration'  => [ 'active' => false ],
						'custom_access' => [
							'active'       => false,
							'access_rules' => [],
						],
					],
				]
			)
		);
		$this->assertSame( '<div>hi</div>', $result );
	}

	/**
	 * An admin-screen render shows a restricted block to someone who can author.
	 */
	public function test_target_block_with_rules_passes_through_in_admin() {
		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );
		set_current_screen( 'dashboard' );
		$block  = $this->make_block(
			'core/group',
			[
				'newspackAccessControlRules' => [
					'registration' => [ 'active' => true ],
				],
			]
		);
		$result = Block_Visibility::filter_render_block( '<div>admin view</div>', $block );
		$this->assertSame( '<div>admin view</div>', $result );
		unset( $GLOBALS['current_screen'] );
		wp_set_current_user( 0 );
	}

	/**
	 * The authoring bypass is for people who author. An admin-context request from
	 * a reader who cannot edit anything -- admin-ajax serving the front end -- is
	 * gated like any other read.
	 *
	 * This stands in for the REST half of the same condition, which shares the
	 * capability test: REST_REQUEST is a constant core defines per request, so an
	 * in-process test cannot set it for one case without setting it for the rest
	 * of the suite.
	 */
	public function test_admin_context_does_not_bypass_for_a_reader() {
		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();
		set_current_screen( 'dashboard' );

		$rules  = [ 'registration' => [ 'active' => true ] ];
		$block  = $this->make_block_with_rules( 'core/group', $rules, 'visible' );
		$result = Block_Visibility::filter_render_block( '<div>restricted</div>', $block );

		unset( $GLOBALS['current_screen'] );

		$this->assertSame( '', $result, 'An anonymous admin-context render must not bypass access control.' );
	}

	/**
	 * Code rendering a post for something other than a reader -- content
	 * distribution building a payload for a node site -- can take the whole post.
	 */
	public function test_apply_filter_can_suspend_block_visibility() {
		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();

		$rules = [ 'registration' => [ 'active' => true ] ];
		$block = $this->make_block_with_rules( 'core/group', $rules, 'visible' );

		add_filter( 'newspack_content_gate_apply_block_visibility', '__return_false' );
		$suspended = Block_Visibility::filter_render_block( '<div>restricted</div>', $block );
		remove_filter( 'newspack_content_gate_apply_block_visibility', '__return_false' );
		$applied = Block_Visibility::filter_render_block( '<div>restricted</div>', $block );

		$this->assertSame( '<div>restricted</div>', $suspended );
		$this->assertSame( '', $applied, 'The suspension lasts only as long as the filter does.' );
	}

	/**
	 * Registration: logged-out user does not match.
	 */
	public function test_registration_logged_out_does_not_match() {
		wp_set_current_user( 0 );
		$rules = [ 'registration' => [ 'active' => true ] ];
		$this->assertFalse( Block_Visibility::evaluate_rules_for_user_public( $rules, 0 ) );
	}

	/**
	 * Registration: logged-in user matches.
	 */
	public function test_registration_logged_in_matches() {
		$rules = [ 'registration' => [ 'active' => true ] ];
		$this->assertTrue( Block_Visibility::evaluate_rules_for_user_public( $rules, $this->test_user_id ) );
	}

	/**
	 * Registration + require_verification: unverified user does not match.
	 */
	public function test_registration_unverified_does_not_match() {
		$rules = [
			'registration' => [
				'active'               => true,
				'require_verification' => true,
			],
		];
		$this->assertFalse( Block_Visibility::evaluate_rules_for_user_public( $rules, $this->test_user_id ) );
	}

	/**
	 * Registration + require_verification: verified user matches.
	 */
	public function test_registration_verified_matches() {
		update_user_meta( $this->test_user_id, \Newspack\Reader_Activation::EMAIL_VERIFIED, true );
		$rules = [
			'registration' => [
				'active'               => true,
				'require_verification' => true,
			],
		];
		$this->assertTrue( Block_Visibility::evaluate_rules_for_user_public( $rules, $this->test_user_id ) );
	}

	/**
	 * Custom access rule: matching user passes.
	 */
	public function test_access_rule_matching_user_passes() {
		$rules = [
			'custom_access' => [
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'test_rule',
							'value' => $this->test_user_id,
						],
					],
				],
			],
		];
		$this->assertTrue( Block_Visibility::evaluate_rules_for_user_public( $rules, $this->test_user_id ) );
	}

	/**
	 * Custom access rule: non-matching user fails.
	 */
	public function test_access_rule_non_matching_user_fails() {
		$other_user = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$rules      = [
			'custom_access' => [
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'test_rule',
							'value' => $this->test_user_id,
						],
					],
				],
			],
		];
		$this->assertFalse( Block_Visibility::evaluate_rules_for_user_public( $rules, $other_user ) );
	}

	/**
	 * AND logic: registration + access rules — both must pass.
	 */
	public function test_and_logic_both_must_pass() {
		$rules = [
			'registration'  => [ 'active' => true ],
			'custom_access' => [
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'test_rule',
							'value' => $this->test_user_id,
						],
					],
				],
			],
		];
		// Logged-in user who matches the access rule: passes.
		$this->assertTrue( Block_Visibility::evaluate_rules_for_user_public( $rules, $this->test_user_id ) );

		// Logged-out user: fails (registration not met).
		Block_Visibility::reset_cache_for_tests();
		$this->assertFalse( Block_Visibility::evaluate_rules_for_user_public( $rules, 0 ) );
	}

	/**
	 * Helper: build a block with both control attributes.
	 *
	 * @param string $block_name Block type name.
	 * @param array  $rules      newspackAccessControlRules value.
	 * @param string $visibility 'visible' or 'hidden'.
	 * @return array
	 */
	private function make_block_with_rules( $block_name, $rules, $visibility = 'visible' ) {
		return $this->make_block(
			$block_name,
			[
				'newspackAccessControlMode'       => 'custom',
				'newspackAccessControlRules'      => $rules,
				'newspackAccessControlVisibility' => $visibility,
			]
		);
	}

	/**
	 * "visible" mode: matching user sees the block.
	 */
	public function test_visible_mode_matching_user_sees_block() {
		wp_set_current_user( $this->test_user_id );
		Block_Visibility::reset_cache_for_tests();
		$rules  = [ 'registration' => [ 'active' => true ] ];
		$block  = $this->make_block_with_rules( 'core/group', $rules, 'visible' );
		$result = Block_Visibility::filter_render_block( '<div>secret</div>', $block );
		$this->assertSame( '<div>secret</div>', $result );
	}

	/**
	 * "visible" mode: non-matching user does not see the block.
	 */
	public function test_visible_mode_non_matching_user_hidden() {
		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();
		$rules  = [ 'registration' => [ 'active' => true ] ];
		$block  = $this->make_block_with_rules( 'core/group', $rules, 'visible' );
		$result = Block_Visibility::filter_render_block( '<div>secret</div>', $block );
		$this->assertSame( '', $result );
	}

	/**
	 * "hidden" mode: matching user does not see the block.
	 */
	public function test_hidden_mode_matching_user_hidden() {
		wp_set_current_user( $this->test_user_id );
		Block_Visibility::reset_cache_for_tests();
		$rules  = [ 'registration' => [ 'active' => true ] ];
		$block  = $this->make_block_with_rules( 'core/group', $rules, 'hidden' );
		$result = Block_Visibility::filter_render_block( '<div>members only</div>', $block );
		$this->assertSame( '', $result );
	}

	/**
	 * "hidden" mode: non-matching user sees the block.
	 */
	public function test_hidden_mode_non_matching_user_sees_block() {
		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();
		$rules  = [ 'registration' => [ 'active' => true ] ];
		$block  = $this->make_block_with_rules( 'core/group', $rules, 'hidden' );
		$result = Block_Visibility::filter_render_block( '<div>non-member content</div>', $block );
		$this->assertSame( '<div>non-member content</div>', $result );
	}

	/**
	 * All three target block types are evaluated.
	 */
	public function test_all_target_block_types_evaluated() {
		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();
		$rules = [ 'registration' => [ 'active' => true ] ];
		foreach ( [ 'core/group', 'core/stack', 'core/row' ] as $block_name ) {
			Block_Visibility::reset_cache_for_tests();
			$block  = $this->make_block_with_rules( $block_name, $rules, 'visible' );
			$result = Block_Visibility::filter_render_block( '<div>x</div>', $block );
			$this->assertSame( '', $result, "Expected empty for $block_name" );
		}
	}

	/**
	 * Missing visibility attribute defaults to "visible".
	 */
	public function test_missing_visibility_attribute_defaults_to_visible() {
		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();
		$block = $this->make_block(
			'core/group',
			[
				'newspackAccessControlMode'  => 'custom',
				'newspackAccessControlRules' => [ 'registration' => [ 'active' => true ] ],
				// newspackAccessControlVisibility intentionally omitted.
			]
		);
		$result = Block_Visibility::filter_render_block( '<div>x</div>', $block );
		// Logged-out user: rules don't match, so hidden under default "visible" mode.
		$this->assertSame( '', $result );
	}

	/**
	 * A user who can edit the post sees restricted blocks on the front end.
	 */
	public function test_editor_bypasses_access_rules_on_front_end() {
		$editor_id       = $this->factory->user->create( [ 'role' => 'editor' ] );
		$post_id         = $this->factory->post->create();
		$GLOBALS['post'] = get_post( $post_id );

		wp_set_current_user( $editor_id );
		Block_Visibility::reset_cache_for_tests();

		$rules  = [ 'registration' => [ 'active' => true ] ];
		$block  = $this->make_block_with_rules( 'core/group', $rules, 'visible' );
		$result = Block_Visibility::filter_render_block( '<div>restricted</div>', $block );

		$this->assertSame( '<div>restricted</div>', $result );

		unset( $GLOBALS['post'] );
	}

	/**
	 * A user who cannot edit the post is still subject to access rules.
	 */
	public function test_non_editor_still_restricted_on_front_end() {
		$post_id         = $this->factory->post->create();
		$GLOBALS['post'] = get_post( $post_id );

		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();

		$rules  = [ 'registration' => [ 'active' => true ] ];
		$block  = $this->make_block_with_rules( 'core/group', $rules, 'visible' );
		$result = Block_Visibility::filter_render_block( '<div>restricted</div>', $block );

		$this->assertSame( '', $result );

		unset( $GLOBALS['post'] );
	}

	/**
	 * The hide-decision is callable directly with an explicit user, so the excerpt
	 * path can ask what an anonymous reader would see without changing the current user.
	 */
	public function test_is_hidden_for_user_is_callable_with_an_explicit_user() {
		$rules = [ 'registration' => [ 'active' => true ] ];
		$block = $this->make_block_with_rules( 'core/group', $rules, 'visible' );

		$this->assertTrue(
			Block_Visibility::is_hidden_for_user( $block, 0 ),
			'A registration-gated block is hidden from a logged-out reader.'
		);
		$this->assertFalse(
			Block_Visibility::is_hidden_for_user( $block, $this->test_user_id ),
			'The same block is visible to a reader who matches the rule.'
		);
	}

	/**
	 * The early-out's substring assumption holds for every gating shape.
	 *
	 * Sanitization returns early when the raw content does not contain the literal
	 * 'newspackAccessControl'. That is a performance guard sitting on a security
	 * boundary: if a gated block could ever serialize without the literal, the guard
	 * would skip sanitization rather than merely skip work. This pins the assumption
	 * instead of trusting it; removal itself is covered by the stripping tests above.
	 */
	public function test_gated_blocks_always_serialize_with_the_guard_literal() {
		$registration = [
			'registration' => [ 'active' => true ],
		];
		$custom_access = [
			'custom_access' => [
				'active'       => true,
				'access_rules' => [ 'registration' ],
			],
		];

		$shapes = [
			'custom / registration' => [
				'newspackAccessControlMode'       => 'custom',
				'newspackAccessControlRules'      => $registration,
				'newspackAccessControlVisibility' => 'visible',
			],
			'custom / access rules' => [
				'newspackAccessControlMode'       => 'custom',
				'newspackAccessControlRules'      => $custom_access,
				'newspackAccessControlVisibility' => 'visible',
			],
			'gate mode'             => [
				'newspackAccessControlMode'       => 'gate',
				'newspackAccessControlGateIds'    => [ 123 ],
				'newspackAccessControlVisibility' => 'visible',
			],
		];

		foreach ( $shapes as $label => $attrs ) {
			$block = [
				'blockName'    => 'core/group',
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '<div class="wp-block-group">GUARDMARK</div>',
				'innerContent' => [ '<div class="wp-block-group">GUARDMARK</div>' ],
			];

			$serialized = serialize_block( $block );

			$this->assertStringContainsString(
				'newspackAccessControl',
				$serialized,
				sprintf( 'A gated block must serialize with the literal the early-out searches for: %s', $label )
			);
		}
	}

	/**
	 * Core/group block has both visibility attributes registered server-side.
	 */
	public function test_group_block_has_visibility_attribute_registered() {
		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( 'core/group' );
		$this->assertArrayHasKey( 'newspackAccessControlVisibility', $block_type->attributes );
		$this->assertArrayHasKey( 'newspackAccessControlRules', $block_type->attributes );
	}

	/**
	 * Caching: second call returns cached result without re-evaluation.
	 */
	public function test_result_is_cached() {
		$call_count      = 0;
		$counting_rule_id = 'counting_rule_' . uniqid();
		\Newspack\Access_Rules::register_rule(
			[
				'id'       => $counting_rule_id,
				'name'     => 'Counting Rule',
				'callback' => function( $user_id, $value ) use ( &$call_count ) {
					$call_count++;
					return true;
				},
			]
		);
		$rules = [
			'custom_access' => [
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => $counting_rule_id,
							'value' => null,
						],
					],
				],
			],
		];
		Block_Visibility::evaluate_rules_for_user_public( $rules, $this->test_user_id );
		Block_Visibility::evaluate_rules_for_user_public( $rules, $this->test_user_id );
		// Callback fired only once despite two calls with identical rules + user.
		$this->assertSame( 1, $call_count );
	}

	/**
	 * The stripped-content memo does not survive a cache reset.
	 *
	 * The memo itself saves a parse/walk/serialize on repeat calls, which this suite
	 * cannot observe: rules_match_cache already suppresses the rule callbacks a
	 * counting test would measure, so such a test would pass with or without it. What
	 * is observable, and what matters for test isolation, is that the memo clears.
	 */
	public function test_strip_memo_clears_on_reset() {
		$content = '<!-- wp:group {"newspackAccessControlMode":"custom","newspackAccessControlRules":{"registration":{"active":true}},"newspackAccessControlVisibility":"visible"} -->'
			. '<div class="wp-block-group"><!-- wp:paragraph --><p>MEMOMARK</p><!-- /wp:paragraph --></div>'
			. '<!-- /wp:group -->';

		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();
		$first = Block_Visibility::strip_blocks_hidden_from_public( $content );

		Block_Visibility::reset_cache_for_tests();
		$second = Block_Visibility::strip_blocks_hidden_from_public( $content );

		$this->assertSame( $first, $second, 'A reset must not change what stripping produces.' );
		$this->assertStringNotContainsString( 'MEMOMARK', $first, 'The gated block is withheld from the anonymous reader.' );
	}

	// -----------------------------------------------------------------------
	// Gate mode tests
	// -----------------------------------------------------------------------

	/**
	 * Helper: create a published gate post and optionally set its registration meta.
	 *
	 * @param bool   $registration_active Whether to activate the registration rule.
	 * @param string $status            Post status. Default 'publish'.
	 * @return int Gate post ID.
	 */
	private function make_gate( $registration_active = true, $status = 'publish' ) {
		$gate_id = $this->factory->post->create(
			[
				'post_type'   => \Newspack\Content_Gate::GATE_CPT,
				'post_status' => $status,
			]
		);
		if ( $registration_active ) {
			update_post_meta( $gate_id, 'registration', [ 'active' => true ] );
		}
		return $gate_id;
	}

	/**
	 * Gate mode with no gates selected passes through regardless of user.
	 */
	public function test_gate_mode_no_gates_passes_through() {
		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();
		$block  = $this->make_block(
			'core/group',
			[
				'newspackAccessControlMode'    => 'gate',
				'newspackAccessControlGateIds' => [],
			]
		);
		$result = Block_Visibility::filter_render_block( '<div>x</div>', $block );
		$this->assertSame( '<div>x</div>', $result );
	}

	/**
	 * Gate mode: user matching an active gate's rules sees the block.
	 */
	public function test_gate_mode_matching_user_sees_block() {
		$gate_id = $this->make_gate();

		wp_set_current_user( $this->test_user_id );
		Block_Visibility::reset_cache_for_tests();

		$block  = $this->make_block(
			'core/group',
			[
				'newspackAccessControlMode'    => 'gate',
				'newspackAccessControlGateIds' => [ $gate_id ],
			]
		);
		$result = Block_Visibility::filter_render_block( '<div>members</div>', $block );
		$this->assertSame( '<div>members</div>', $result );
	}

	/**
	 * Gate mode: user not matching an active gate's rules does not see the block.
	 */
	public function test_gate_mode_non_matching_user_hidden() {
		$gate_id = $this->make_gate();

		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();

		$block  = $this->make_block(
			'core/group',
			[
				'newspackAccessControlMode'    => 'gate',
				'newspackAccessControlGateIds' => [ $gate_id ],
			]
		);
		$result = Block_Visibility::filter_render_block( '<div>members</div>', $block );
		$this->assertSame( '', $result );
	}

	/**
	 * Gate mode: an unpublished (draft) gate is skipped — results in pass-through.
	 */
	public function test_gate_mode_unpublished_gate_passes_through() {
		$gate_id = $this->make_gate( true, 'draft' );

		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();

		$block  = $this->make_block(
			'core/group',
			[
				'newspackAccessControlMode'    => 'gate',
				'newspackAccessControlGateIds' => [ $gate_id ],
			]
		);
		$result = Block_Visibility::filter_render_block( '<div>x</div>', $block );
		$this->assertSame( '<div>x</div>', $result );
	}

	/**
	 * Gate mode: a permanently deleted gate is skipped — results in pass-through.
	 */
	public function test_gate_mode_deleted_gate_passes_through_in_visible_mode() {
		$gate_id = $this->make_gate();
		wp_delete_post( $gate_id, true ); // Force-delete.

		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();

		$block  = $this->make_block(
			'core/group',
			[
				'newspackAccessControlMode'    => 'gate',
				'newspackAccessControlGateIds' => [ $gate_id ],
			]
		);
		$result = Block_Visibility::filter_render_block( '<div>x</div>', $block );
		$this->assertSame( '<div>x</div>', $result );
	}

	/**
	 * Gate mode: deleted gate in 'hidden' mode still passes through — no gate = no restriction.
	 *
	 * Regression: previously $user_matches = true (pass-through sentinel) combined with
	 * visibility = 'hidden' would hide the block from everyone instead of showing it.
	 */
	public function test_gate_mode_deleted_gate_passes_through_in_hidden_mode() {
		$gate_id = $this->make_gate();
		wp_delete_post( $gate_id, true ); // Force-delete.

		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();

		$block  = $this->make_block(
			'core/group',
			[
				'newspackAccessControlMode'       => 'gate',
				'newspackAccessControlGateIds'    => [ $gate_id ],
				'newspackAccessControlVisibility' => 'hidden',
			]
		);
		$result = Block_Visibility::filter_render_block( '<div>x</div>', $block );
		$this->assertSame( '<div>x</div>', $result );
	}

	/**
	 * Gate mode: a deleted gate alongside an active gate; only the active gate is evaluated.
	 */
	public function test_gate_mode_deleted_gate_does_not_affect_active_gate() {
		$active_gate_id  = $this->make_gate();
		$deleted_gate_id = $this->make_gate();
		wp_delete_post( $deleted_gate_id, true );

		// Logged-out user does not satisfy the active gate's registration rule.
		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();

		$block  = $this->make_block(
			'core/group',
			[
				'newspackAccessControlMode'    => 'gate',
				'newspackAccessControlGateIds' => [ $active_gate_id, $deleted_gate_id ],
			]
		);
		$result = Block_Visibility::filter_render_block( '<div>x</div>', $block );
		$this->assertSame( '', $result );
	}

	/**
	 * Gate mode: OR logic — user matching any one of multiple active gates sees the block.
	 */
	public function test_gate_mode_or_logic_any_matching_gate_passes() {
		// Gate A: requires custom access rule that only matches test_user_id.
		$gate_a = $this->make_gate( false ); // No registration rule.
		update_post_meta(
			$gate_a,
			'custom_access',
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'test_rule',
							'value' => $this->test_user_id,
						],
					],
				],
			]
		);

		// Gate B: requires registration (logged-in only).
		$gate_b = $this->make_gate( true );

		// A logged-out user matches neither gate.
		wp_set_current_user( 0 );
		Block_Visibility::reset_cache_for_tests();
		$block = $this->make_block(
			'core/group',
			[
				'newspackAccessControlMode'    => 'gate',
				'newspackAccessControlGateIds' => [ $gate_a, $gate_b ],
			]
		);
		$this->assertSame( '', Block_Visibility::filter_render_block( '<div>x</div>', $block ) );

		// The test user matches Gate A (custom rule), so they see the block.
		wp_set_current_user( $this->test_user_id );
		Block_Visibility::reset_cache_for_tests();
		$this->assertSame( '<div>x</div>', Block_Visibility::filter_render_block( '<div>x</div>', $block ) );
	}

	// -----------------------------------------------------------------------
	// strip_blocks_hidden_from_public() tests
	// -----------------------------------------------------------------------

	/**
	 * Gated blocks are removed from content at any nesting depth, and ungated
	 * siblings survive.
	 */
	public function test_strip_removes_gated_blocks_at_any_depth() {
		$gate    = '{"newspackAccessControlMode":"custom","newspackAccessControlRules":{"registration":{"active":true}},"newspackAccessControlVisibility":"visible"}';
		$gated   = '<!-- wp:group ' . $gate . ' --><div class="wp-block-group"><!-- wp:paragraph --><p>GATED</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		$ungated = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>ORDINARY</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		$plain   = '<!-- wp:paragraph --><p>PLAIN</p><!-- /wp:paragraph -->';

		$top    = Block_Visibility::strip_blocks_hidden_from_public( $plain . $ungated . $gated );
		$nested = Block_Visibility::strip_blocks_hidden_from_public(
			$plain . '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">'
			. $gated . '</div><!-- /wp:column --></div><!-- /wp:columns -->'
		);

		$this->assertStringNotContainsString( 'GATED', $top, 'A top-level gated block is removed.' );
		$this->assertStringContainsString( 'PLAIN', $top, 'Ungated content survives.' );
		$this->assertStringContainsString( 'ORDINARY', $top, 'An ungated group survives.' );
		$this->assertStringNotContainsString( 'GATED', $nested, 'A gated block nested in columns is removed.' );
		$this->assertStringContainsString( 'PLAIN', $nested, 'Ungated content survives the nested case.' );
	}

	/**
	 * Removing a nested block keeps innerContent's null markers in step with
	 * innerBlocks. serialize_block() consumes one innerBlocks entry per null, so a
	 * mismatch throws from inside core rather than returning wrong output.
	 */
	public function test_strip_keeps_inner_content_markers_in_step() {
		$gate  = '{"newspackAccessControlMode":"custom","newspackAccessControlRules":{"registration":{"active":true}},"newspackAccessControlVisibility":"visible"}';
		$mixed = '<!-- wp:columns --><div class="wp-block-columns">'
			. '<!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>KEEP</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
			. '<!-- wp:column --><div class="wp-block-column"><!-- wp:group ' . $gate . ' --><div class="wp-block-group"><!-- wp:paragraph --><p>GATED</p><!-- /wp:paragraph --></div><!-- /wp:group --></div><!-- /wp:column -->'
			. '</div><!-- /wp:columns -->';

		$out = Block_Visibility::strip_blocks_hidden_from_public( $mixed );

		$this->assertStringNotContainsString( 'GATED', $out );
		$this->assertStringContainsString( 'KEEP', $out );
		$this->assertNotEmpty( parse_blocks( $out ), 'Output is re-parseable.' );
	}
}
