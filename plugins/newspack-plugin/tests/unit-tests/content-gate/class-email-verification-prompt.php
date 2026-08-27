<?php
/**
 * Tests for the content gate's email verification prompt (NPPD-2221).
 *
 * @package Newspack
 */

use Newspack\Access_Rules;
use Newspack\Content_Gate;
use Newspack\Content_Restriction_Control;
use Newspack\Email_Verification_Prompt;
use Newspack\Institution;
use Newspack\Reader_Activation;

/**
 * A reader whose email domain is whitelisted but unverified is denied by the
 * email-domain access rule with nothing on the paywall saying why. These tests
 * pin when the gate offers them the verification prompt instead, and — the part
 * that is easy to get wrong — when it must not, because verifying would leave
 * them looking at the same paywall.
 *
 * @group Content_Gate_Verification_Prompt
 */
class Test_Email_Verification_Prompt extends WP_UnitTestCase {

	/**
	 * Institution post IDs to clean up.
	 *
	 * @var int[]
	 */
	private $institution_ids = [];

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		add_filter( 'newspack_reader_activation_enabled', '__return_true' );
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		foreach ( $this->institution_ids as $institution_id ) {
			wp_delete_post( $institution_id, true );
		}
		$this->institution_ids = [];
		foreach ( Content_Gate::get_gates() as $gate ) {
			wp_delete_post( $gate['id'], true );
		}
		Institution::invalidate_cache();
		Institution::reset_matching_cache();
		Content_Gate::flush_gates_cache();
		$this->reset_caches();
		remove_filter( 'newspack_reader_activation_enabled', '__return_true' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Clear the request-scoped memos that would otherwise carry a previous test's
	 * gate resolution and prompt decision into the next one.
	 */
	private function reset_caches() {
		Email_Verification_Prompt::reset_cache();
		foreach ( [ 'post_gates_map', 'post_gate_id_map', 'post_gate_layout_id_map' ] as $property_name ) {
			$property = new ReflectionProperty( Content_Restriction_Control::class, $property_name );
			$property->setAccessible( true );
			$property->setValue( null, [] );
		}
	}

	/**
	 * Create a reader.
	 *
	 * @param string $email    Email address.
	 * @param bool   $verified Whether the address is verified.
	 *
	 * @return int User ID.
	 */
	private function create_reader( $email, $verified = false ) {
		$user_id = self::factory()->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => $email,
			]
		);
		update_user_meta( $user_id, Reader_Activation::READER, true );
		if ( $verified ) {
			update_user_meta( $user_id, Reader_Activation::EMAIL_VERIFIED, true );
		}
		return $user_id;
	}

	/**
	 * Create a published institution granting access by email domain.
	 *
	 * @param string $name    Institution name.
	 * @param string $domains Comma-delimited domain list.
	 *
	 * @return int Institution post ID.
	 */
	private function create_institution( $name, $domains ) {
		$institution_id          = Institution::create( $name, '', [ 'email_domain' => $domains ] );
		$this->institution_ids[] = $institution_id;
		Institution::invalidate_cache();
		return $institution_id;
	}

	/**
	 * Create a published gate over all posts, gating on the given access rules.
	 *
	 * @param array $access_rules Access rules in grouped format.
	 * @param int   $priority     Gate priority. Lower gates are evaluated first.
	 * @param array $registration Registration-mode settings, if the gate has any.
	 *
	 * @return int Gate ID.
	 */
	private function create_gate( $access_rules, $priority = 1, $registration = [] ) {
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Gate ' . $priority ] );
		Content_Gate::update_gate_settings(
			$gate_id,
			[
				'title'         => 'Gate ' . $priority,
				'status'        => 'publish',
				'priority'      => $priority,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => $registration,
				'custom_access' => [
					'active'       => true,
					'access_rules' => $access_rules,
				],
			]
		);
		Content_Gate::flush_gates_cache();
		$this->reset_caches();
		return $gate_id;
	}

	/**
	 * Put a reader in front of a gated post, as a front-end request would: on the
	 * singular view, with the restriction check having written the gate resolution
	 * that the prompt and the layout filter both read back.
	 *
	 * @param int $user_id Reader ID.
	 *
	 * @return bool Whether the post came out restricted.
	 */
	private function visit_gated_post_as( $user_id ) {
		$this->reset_caches();
		$gated_post_id = self::factory()->post->create();
		wp_set_current_user( $user_id );
		$this->go_to( get_permalink( $gated_post_id ) );
		wp_set_current_user( $user_id );
		return Content_Gate::is_post_restricted( $gated_post_id );
	}

	/**
	 * The headline names the institution, so the reader is told what verifying buys
	 * them rather than being asked for a code with no stated reason.
	 */
	public function test_prompts_unverified_reader_matching_an_institution() {
		$institution_id = $this->create_institution( 'Voyager Technologies', 'voyager.example' );
		$this->create_gate(
			[
				[
					[
						'slug'  => 'institution',
						'value' => [ $institution_id ],
					],
				],
			]
		);
		$reader_id = $this->create_reader( 'reader@voyager.example' );

		$this->assertTrue( $this->visit_gated_post_as( $reader_id ), 'Sanity: the unverified reader is denied.' );

		$prompt_context = Email_Verification_Prompt::get_prompt_context();
		$this->assertNotFalse( $prompt_context, 'The denied reader is offered the verification prompt.' );
		$this->assertSame( [ 'Voyager Technologies' ], $prompt_context['institutions'] );
		$this->assertSame( 'reader@voyager.example', $prompt_context['email'] );
	}

	/**
	 * A gate's own email-domain rule denies for the same reason and gets the same
	 * prompt, with no institution to name.
	 */
	public function test_prompts_on_a_bare_email_domain_rule() {
		$this->create_gate(
			[
				[
					[
						'slug'  => 'email_domain',
						'value' => 'voyager.example',
					],
				],
			]
		);
		$reader_id = $this->create_reader( 'reader@voyager.example' );

		$this->assertTrue( $this->visit_gated_post_as( $reader_id ), 'Sanity: the unverified reader is denied.' );

		$prompt_context = Email_Verification_Prompt::get_prompt_context();
		$this->assertNotFalse( $prompt_context, 'The denied reader is offered the verification prompt.' );
		$this->assertSame( [], $prompt_context['institutions'] );
	}

	/**
	 * The whole point of the hypothetical re-evaluation: the institution rule ANDed
	 * with a rule the reader also fails means verifying returns them to the same
	 * paywall, so the prompt must not promise otherwise.
	 */
	public function test_no_prompt_when_verification_would_not_unlock_the_gate() {
		$institution_id = $this->create_institution( 'Voyager Technologies', 'voyager.example' );
		$this->create_gate(
			[
				[
					[
						'slug'  => 'institution',
						'value' => [ $institution_id ],
					],
					[
						'slug'  => 'reader_data',
						'value' => 'plan=premium',
					],
				],
			]
		);
		$reader_id = $this->create_reader( 'reader@voyager.example' );

		$this->assertTrue( $this->visit_gated_post_as( $reader_id ), 'Sanity: the unverified reader is denied.' );
		$this->assertFalse(
			Email_Verification_Prompt::get_prompt_context(),
			'Verifying would not satisfy the rule ANDed with the institution, so no prompt is offered.'
		);
	}

	/**
	 * A domain the gate does not reference is not a reason to ask for verification —
	 * and where a reader's address matches two institutions but the gate grants access
	 * through only one, the prompt names that one alone.
	 */
	public function test_only_institutions_the_gate_grants_through_are_named() {
		$granted_institution_id = $this->create_institution( 'Voyager Technologies', 'voyager.example' );
		$this->create_institution( 'Unrelated Institute', 'voyager.example' );
		$this->create_gate(
			[
				[
					[
						'slug'  => 'institution',
						'value' => [ $granted_institution_id ],
					],
				],
			]
		);
		$matching_reader_id     = $this->create_reader( 'reader@voyager.example' );
		$non_matching_reader_id = $this->create_reader( 'reader@elsewhere.example' );

		$this->assertTrue( $this->visit_gated_post_as( $non_matching_reader_id ), 'Sanity: the unverified reader is denied.' );
		$this->assertFalse(
			Email_Verification_Prompt::get_prompt_context(),
			'An address on no institution the gate references is not a reason to prompt.'
		);

		$this->visit_gated_post_as( $matching_reader_id );
		$prompt_context = Email_Verification_Prompt::get_prompt_context();
		$this->assertNotFalse( $prompt_context );
		$this->assertSame(
			[ 'Voyager Technologies' ],
			$prompt_context['institutions'],
			'Only the institution this gate grants access through is named, not every institution the address matches.'
		);
	}

	/**
	 * The prompt names the institutions that are actually a route in. Where the reader's
	 * address matches institutions in two groups and only one group passes, naming both
	 * would tell them an institution unlocks the article when it never will.
	 */
	public function test_only_institutions_in_a_passing_group_are_named() {
		$unlocking_institution_id = $this->create_institution( 'Voyager Technologies', 'voyager.example' );
		$blocked_institution_id   = $this->create_institution( 'Grounded Institute', 'voyager.example' );
		$this->create_gate(
			[
				[
					[
						'slug'  => 'institution',
						'value' => [ $unlocking_institution_id ],
					],
				],
				[
					[
						'slug'  => 'institution',
						'value' => [ $blocked_institution_id ],
					],
					[
						'slug'  => 'reader_data',
						'value' => 'plan=premium',
					],
				],
			]
		);
		$reader_id = $this->create_reader( 'reader@voyager.example' );

		$this->assertTrue( $this->visit_gated_post_as( $reader_id ), 'Sanity: the unverified reader is denied.' );

		$prompt_context = Email_Verification_Prompt::get_prompt_context();
		$this->assertNotFalse( $prompt_context );
		$this->assertSame(
			[ 'Voyager Technologies' ],
			$prompt_context['institutions'],
			'Grounded Institute is ANDed with a rule the reader fails, so verifying never opens that route.'
		);
	}

	/**
	 * A gate can be one of several covering a post, and the restriction check stops at
	 * the first that denies. Verifying past this gate only to be held by the next one
	 * is the promise the prompt must not make.
	 */
	public function test_no_prompt_when_a_lower_priority_gate_still_denies() {
		$institution_id = $this->create_institution( 'Voyager Technologies', 'voyager.example' );
		$this->create_gate(
			[
				[
					[
						'slug'  => 'institution',
						'value' => [ $institution_id ],
					],
				],
			]
		);
		// Priority 2, so it is evaluated only once the institution gate starts granting.
		$this->create_gate(
			[
				[
					[
						'slug'  => 'reader_data',
						'value' => 'plan=premium',
					],
				],
			],
			2
		);
		$reader_id = $this->create_reader( 'reader@voyager.example' );

		$this->assertTrue( $this->visit_gated_post_as( $reader_id ), 'Sanity: the unverified reader is denied.' );
		$this->assertFalse(
			Email_Verification_Prompt::get_prompt_context(),
			'Verifying would clear the first gate and leave the reader held by the second, so no prompt is offered.'
		);
	}

	/**
	 * The rule keeps its verification requirement. Simulating verification decides
	 * whether to prompt and nothing else — an unverified reader is still denied.
	 */
	public function test_simulation_does_not_grant_access() {
		$institution_id = $this->create_institution( 'Voyager Technologies', 'voyager.example' );
		$this->create_gate(
			[
				[
					[
						'slug'  => 'institution',
						'value' => [ $institution_id ],
					],
				],
			]
		);
		$reader_id = $this->create_reader( 'reader@voyager.example' );
		$this->visit_gated_post_as( $reader_id );

		$this->assertNotFalse( Email_Verification_Prompt::get_prompt_context(), 'Sanity: the prompt evaluated, running the simulation.' );
		$this->assertFalse(
			Access_Rules::is_email_domain_whitelisted( $reader_id, 'voyager.example' ),
			'The email-domain rule still denies an unverified reader after the prompt has evaluated it.'
		);

		update_user_meta( $reader_id, Reader_Activation::EMAIL_VERIFIED, true );
		$this->assertTrue(
			Access_Rules::is_email_domain_whitelisted( $reader_id, 'voyager.example' ),
			'Verifying is what grants access.'
		);
	}

	/**
	 * The assumption covers the one reader the prompt is being evaluated for. Marking a
	 * reader verified destroys their other sessions, so a hypothetical that reached a
	 * second reader would be a real change to someone who never asked for one.
	 */
	public function test_assumption_does_not_reach_another_reader() {
		$reader_a_id = $this->create_reader( 'a@voyager.example' );
		$reader_b_id = $this->create_reader( 'b@voyager.example' );

		$verdicts = Access_Rules::with_assumed_verification(
			$reader_a_id,
			function () use ( $reader_a_id, $reader_b_id ) {
				return [
					'a' => Access_Rules::is_email_domain_whitelisted( $reader_a_id, 'voyager.example' ),
					'b' => Access_Rules::is_email_domain_whitelisted( $reader_b_id, 'voyager.example' ),
				];
			}
		);

		$this->assertTrue( $verdicts['a'], 'The reader the assumption is about passes the rule.' );
		$this->assertFalse( $verdicts['b'], 'Every other reader is still held to the verification requirement.' );
	}

	/**
	 * Rule callbacks are third-party-registerable, so one can throw mid-evaluation. The
	 * assumption must not survive that and leak into the next real access decision.
	 */
	public function test_assumption_is_cleared_when_the_callback_throws() {
		$reader_id = $this->create_reader( 'reader@voyager.example' );

		try {
			Access_Rules::with_assumed_verification(
				$reader_id,
				function () {
					throw new \RuntimeException( 'rule callback blew up' );
				}
			);
			$this->fail( 'The exception should propagate to the caller.' );
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected — what matters is the state left behind.
		}

		$this->assertFalse(
			Access_Rules::is_email_domain_whitelisted( $reader_id, 'voyager.example' ),
			'The rule denies the unverified reader again once the hypothetical has unwound.'
		);
	}

	/**
	 * A verified reader on a whitelisted domain is not denied in the first place, and a
	 * non-reader account on the same domain is out of the flow whatever its state.
	 */
	public function test_no_prompt_for_a_verified_reader_or_a_non_reader() {
		$institution_id = $this->create_institution( 'Voyager Technologies', 'voyager.example' );
		$this->create_gate(
			[
				[
					[
						'slug'  => 'institution',
						'value' => [ $institution_id ],
					],
				],
			]
		);
		$reader_id = $this->create_reader( 'reader@voyager.example', true );

		$this->assertFalse( $this->visit_gated_post_as( $reader_id ), 'A verified reader on the domain is granted access.' );
		$this->assertFalse( Email_Verification_Prompt::get_prompt_context() );

		$editor_id = self::factory()->user->create(
			[
				'role'       => 'editor',
				'user_email' => 'editor@voyager.example',
			]
		);
		$this->visit_gated_post_as( $editor_id );
		$this->assertFalse(
			Email_Verification_Prompt::get_prompt_context(),
			'A non-reader account is not asked to verify, whatever its email domain.'
		);
	}

	/**
	 * A gate can wall registration behind verification and grant by email domain at the
	 * same time. One act of verifying satisfies both, so asking what the reader would
	 * see afterwards has to assume it at both seams — otherwise the prompt is suppressed
	 * on the configuration it helps most.
	 */
	public function test_prompts_when_the_gate_also_walls_registration_behind_verification() {
		$institution_id = $this->create_institution( 'Voyager Technologies', 'voyager.example' );
		$this->create_gate(
			[
				[
					[
						'slug'  => 'institution',
						'value' => [ $institution_id ],
					],
				],
			],
			1,
			[
				'active'               => true,
				'require_verification' => true,
			]
		);
		$reader_id = $this->create_reader( 'reader@voyager.example' );

		$this->assertTrue( $this->visit_gated_post_as( $reader_id ), 'Sanity: the unverified reader is denied.' );
		$this->assertNotFalse(
			Email_Verification_Prompt::get_prompt_context(),
			'Verifying satisfies the registration wall and the institution rule together, so the prompt is offered.'
		);
	}

	/**
	 * Resolving the prompt replays the restriction decision, which is memoised. The
	 * request's own answer has to survive that: the gate render reads it back to decide
	 * what to show, and a second read finding it wiped would drop the gate.
	 */
	public function test_resolving_the_prompt_leaves_the_gate_resolution_intact() {
		$institution_id = $this->create_institution( 'Voyager Technologies', 'voyager.example' );
		$gate_id        = $this->create_gate(
			[
				[
					[
						'slug'  => 'institution',
						'value' => [ $institution_id ],
					],
				],
			]
		);
		$reader_id = $this->create_reader( 'reader@voyager.example' );
		$this->visit_gated_post_as( $reader_id );

		$resolved_gate_id   = Content_Gate::get_gate_post_id();
		$resolved_layout_id = Content_Gate::get_gate_layout_id();
		$this->assertSame( $gate_id, $resolved_gate_id, 'Sanity: the request resolved to the gate under test.' );

		$this->assertNotFalse( Email_Verification_Prompt::get_prompt_context() );

		$this->assertSame( $resolved_gate_id, Content_Gate::get_gate_post_id(), 'The gate the request resolved to is unchanged.' );
		$this->assertSame( $resolved_layout_id, Content_Gate::get_gate_layout_id(), 'The layout the request resolved to is unchanged.' );
	}

	/**
	 * The prompt sends a code and hands the reader to the auth modal to enter it. Where
	 * a site has filtered that modal off, offering the code would mail them something
	 * they have nowhere to type.
	 */
	public function test_no_prompt_where_the_auth_modal_is_filtered_off() {
		$institution_id = $this->create_institution( 'Voyager Technologies', 'voyager.example' );
		$this->create_gate(
			[
				[
					[
						'slug'  => 'institution',
						'value' => [ $institution_id ],
					],
				],
			]
		);
		$reader_id = $this->create_reader( 'reader@voyager.example' );

		$this->visit_gated_post_as( $reader_id );
		$this->assertNotFalse( Email_Verification_Prompt::get_prompt_context(), 'Sanity: the reader is offered the prompt.' );

		add_filter( 'newspack_reader_activation_should_render_auth', '__return_false' );
		Email_Verification_Prompt::reset_cache();
		$this->assertFalse(
			Email_Verification_Prompt::get_prompt_context(),
			'With no auth modal to enter the code into, no code is offered.'
		);
		remove_filter( 'newspack_reader_activation_should_render_auth', '__return_false' );
	}

	/**
	 * Anonymous visitors are out of scope: there is no address to verify.
	 */
	public function test_no_prompt_for_anonymous_visitors() {
		$institution_id = $this->create_institution( 'Voyager Technologies', 'voyager.example' );
		$this->create_gate(
			[
				[
					[
						'slug'  => 'institution',
						'value' => [ $institution_id ],
					],
				],
			]
		);

		$this->assertTrue( $this->visit_gated_post_as( 0 ), 'Sanity: an anonymous visitor is denied.' );
		$this->assertFalse( Email_Verification_Prompt::get_prompt_context() );
	}

	/**
	 * The prompt renders inside the gate wrapper, above the layout content, so the
	 * layout's own subscribe CTA remains the reader's alternative.
	 */
	public function test_prompt_is_prepended_to_the_gate_layout() {
		$institution_id = $this->create_institution( 'Voyager Technologies', 'voyager.example' );
		$this->create_gate(
			[
				[
					[
						'slug'  => 'institution',
						'value' => [ $institution_id ],
					],
				],
			]
		);
		$reader_id = $this->create_reader( 'reader@voyager.example' );
		$this->visit_gated_post_as( $reader_id );

		$layout_id      = Content_Gate::get_gate_layout_id();
		$layout_content = '<p>Subscribe to keep reading.</p>';
		$rendered       = apply_filters( 'newspack_gate_layout_content', $layout_content, $layout_id );

		$this->assertStringContainsString( 'newspack-content-gate__verification-prompt', $rendered );
		$this->assertStringContainsString( 'Voyager Technologies', $rendered );
		$this->assertStringEndsWith( $layout_content, $rendered, 'The layout content is kept, with the prompt above it.' );
	}

	/**
	 * A layout that is not the one denying this reader must not pick up the prompt.
	 */
	public function test_prompt_is_not_prepended_to_another_layout() {
		$institution_id = $this->create_institution( 'Voyager Technologies', 'voyager.example' );
		$this->create_gate(
			[
				[
					[
						'slug'  => 'institution',
						'value' => [ $institution_id ],
					],
				],
			]
		);
		$reader_id = $this->create_reader( 'reader@voyager.example' );
		$this->visit_gated_post_as( $reader_id );

		$other_layout_id = Content_Gate::get_gate_layout_id() + 1000;
		$rendered        = apply_filters( 'newspack_gate_layout_content', '<p>Another layout.</p>', $other_layout_id );

		$this->assertSame( '<p>Another layout.</p>', $rendered );
	}
}
