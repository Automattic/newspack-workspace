<?php
/**
 * Tests for the configured-but-inert Access Control notice.
 *
 * NPPD-1846 follow-up. Switching Audience Management off makes every Access
 * Control surface stand down, which is intended — but it is one toggle away from
 * paid content going public, and after the confirmation dialog nothing says so.
 * This notice is the standing reminder.
 *
 * Two properties matter and are pinned separately: it appears exactly when
 * something is configured but not applying, and its cache is invalidated by the
 * writes that can change that answer and by nothing else. The second is what
 * keeps a `LIKE` over post content off every admin page load.
 *
 * Every case is mutation-tested: removing the behaviour it pins must fail it.
 *
 * @package Newspack\Tests
 */

use Newspack\Content_Gate;
use Newspack\Inert_Gating_Notice;

/**
 * Tests for Inert_Gating_Notice.
 */
class Inert_Gating_Notice_Test extends WP_UnitTestCase {

	/**
	 * Gates are flag-gated; the CPT must be registered for gate posts to exist.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Start each case from a known-empty cache.
	 */
	public function set_up() {
		parent::set_up();
		Inert_Gating_Notice::flush_cache();
		Content_Gate::flush_gates_cache();
	}

	/**
	 * Don't leak the disabled state into later cases.
	 */
	public function tear_down() {
		remove_all_filters( 'newspack_reader_activation_enabled' );
		Inert_Gating_Notice::flush_cache();
		parent::tear_down();
	}

	/**
	 * Turn Audience Management off for the current case.
	 */
	private function disable_audience_management() {
		add_filter( 'newspack_reader_activation_enabled', '__return_false' );
	}

	/**
	 * Capture the rendered notice.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		Inert_Gating_Notice::render();
		return (string) ob_get_clean();
	}

	/**
	 * Publish a gate.
	 *
	 * @return int Gate post ID.
	 */
	private function create_gate(): int {
		$gate_id = Content_Gate::create_gate(
			[
				'title'         => 'Test gate',
				'status'        => 'publish',
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => [ 'active' => true ],
			]
		);
		$this->assertNotWPError( $gate_id, 'Gate fixture could not be created.' );
		return $gate_id;
	}

	/**
	 * A publisher who never configured Access Control has nothing going public, so
	 * warning them would be noise. This is the case that keeps the notice off most
	 * sites entirely.
	 */
	public function test_no_notice_without_configured_surfaces() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->disable_audience_management();

		$this->assertStringNotContainsString( 'are public for all readers', $this->render() );
	}

	/**
	 * The notice fires on exactly the state it exists for, and only that state.
	 */
	public function test_notice_appears_only_while_configured_and_inert() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->create_gate();
		Inert_Gating_Notice::flush_cache();

		$this->assertStringNotContainsString(
			'are public for all readers',
			$this->render(),
			'Nothing to warn about while gating is doing its job.'
		);

		$this->disable_audience_management();

		$rendered = $this->render();
		$this->assertStringContainsString(
			'are public for all readers',
			$rendered,
			'A configured site with gating inactive should be told its content is public.'
		);
		// The copy exists to route the publisher somewhere, so the destinations are
		// part of the contract, not decoration.
		foreach ( [ 'page=newspack-audience-access-control', 'page=newspack-audience' ] as $destination ) {
			$this->assertStringContainsString( $destination, $rendered, "Expected the notice to link to $destination." );
		}
		$this->assertStringNotContainsString( '<accessControl>', $rendered, 'Interpolation tags must not reach the page.' );
		$this->assertStringContainsString( '<strong>disabled</strong>', $rendered, 'Expected the emphasis to survive wp_kses.' );
	}

	/**
	 * Block-level access control counts too. A publisher who only ever used it has
	 * just as much content quietly going public, and a gates-only check would leave
	 * them with no warning at all.
	 */
	public function test_block_level_rules_count_as_a_surface() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:group {"newspackAccessControlMode":"custom","newspackAccessControlRules":{"registration":{"active":true}}} --><div></div><!-- /wp:group -->',
			]
		);
		Inert_Gating_Notice::flush_cache();
		$this->disable_audience_management();

		$this->assertStringContainsString( 'are public for all readers', $this->render() );
	}

	/**
	 * A block that only records a mode choice is not configured.
	 *
	 * Someone who switches a block to custom mode and never adds a rule leaves
	 * `newspackAccessControlMode` in the content for good — the editor has no reason
	 * to remove it, and it is a preference rather than a rule. Counting it would tell
	 * that publisher their content is public when nothing was ever gated, and with no
	 * TTL nothing would correct it.
	 */
	public function test_a_mode_choice_alone_is_not_a_surface() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:group {"newspackAccessControlMode":"custom"} --><div></div><!-- /wp:group -->',
			]
		);
		Inert_Gating_Notice::flush_cache();
		$this->disable_audience_management();

		$this->assertFalse( Inert_Gating_Notice::has_surfaces(), 'A mode with no rules behind it gates nothing.' );
		$this->assertStringNotContainsString( 'are public for all readers', $this->render() );
	}

	/**
	 * The notice is for whoever can act on it.
	 */
	public function test_hidden_from_users_who_cannot_act_on_it() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->create_gate();
		Inert_Gating_Notice::flush_cache();
		$this->disable_audience_management();

		$this->assertStringNotContainsString( 'are public for all readers', $this->render() );
	}

	/**
	 * The wizard surface is the one most admins actually see, since wizards strip
	 * core notices. It gets its own capability check, so it needs its own case —
	 * the one on render() is pinned separately above.
	 */
	public function test_wizard_payload_tracks_capability_and_inertness() {
		$this->create_gate();
		Inert_Gating_Notice::flush_cache();
		$this->disable_audience_management();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertFalse(
			Inert_Gating_Notice::get_script_data()['show'],
			'An editor cannot turn Audience Management back on, so the wizard must not show them the notice.'
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( Inert_Gating_Notice::get_script_data()['show'] );

		remove_all_filters( 'newspack_reader_activation_enabled' );
		$this->assertFalse(
			Inert_Gating_Notice::get_script_data()['show'],
			'Nothing to warn about while gating is doing its job.'
		);
	}

	/**
	 * `has_surfaces` answers what `show` cannot.
	 *
	 * The confirmation dialog for switching Audience Management off runs while it is
	 * still on, so nothing is inert yet and `show` is false. The dialog still needs to
	 * know whether there is anything to warn about, which is a different question.
	 */
	public function test_wizard_payload_reports_configured_surfaces_while_gating_is_active() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->create_gate();
		Inert_Gating_Notice::flush_cache();

		$data = Inert_Gating_Notice::get_script_data();
		$this->assertFalse( $data['show'], 'Nothing is public while gating is doing its job.' );
		$this->assertTrue( $data['has_surfaces'], 'The dialog has to know what switching off would expose.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertFalse(
			Inert_Gating_Notice::get_script_data()['has_surfaces'],
			'An editor cannot reach that dialog, so the payload should tell them nothing.'
		);
	}

	/**
	 * The cached answer must actually be read back, not just written. Without this
	 * the early return in has_surfaces() can be deleted with every other case still
	 * green, and the `LIKE` runs on every admin page load.
	 */
	public function test_the_cached_answer_is_read_back() {
		$this->assertFalse( Inert_Gating_Notice::has_surfaces(), 'No gates, no block rules.' );

		// Nothing on this site says otherwise, so a `true` answer can only have come
		// from the cache.
		update_option( Inert_Gating_Notice::CACHE_OPTION, '1', false );
		$this->assertTrue( Inert_Gating_Notice::has_surfaces() );
	}

	/**
	 * Creating or deleting a gate changes the answer, so both must invalidate.
	 */
	public function test_cache_is_invalidated_by_gate_writes() {
		$this->assertFalse( Inert_Gating_Notice::has_surfaces(), 'Expected no surfaces on a clean site.' );

		$gate_id = $this->create_gate();
		$this->assertTrue( Inert_Gating_Notice::has_surfaces(), 'Creating a gate should invalidate the cached answer.' );

		wp_delete_post( $gate_id, true );
		$this->assertFalse( Inert_Gating_Notice::has_surfaces(), 'Deleting the last gate should invalidate it again.' );
	}

	/**
	 * A post carrying block rules changes the answer; an ordinary post does not.
	 *
	 * The negative half is the point of checking the content before invalidating:
	 * without it every post save on the site would clear a cache that could not
	 * have changed, and the `LIKE` would run again on the next admin page load.
	 */
	public function test_cache_invalidation_is_scoped_to_relevant_posts() {
		$this->assertFalse( Inert_Gating_Notice::has_surfaces() );

		self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => 'nothing to do with access control',
			]
		);
		$this->assertSame(
			'0',
			get_option( Inert_Gating_Notice::CACHE_OPTION ),
			'An unrelated post save must leave the cached answer in place.'
		);

		self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:group {"newspackAccessControlGateIds":[12]} --><div></div><!-- /wp:group -->',
			]
		);
		$this->assertFalse(
			get_option( Inert_Gating_Notice::CACHE_OPTION, false ),
			'A post carrying block rules must invalidate the cached answer.'
		);
		$this->assertTrue( Inert_Gating_Notice::has_surfaces() );
	}

	/**
	 * Removing the last block rules flips the answer from true to false, and only the
	 * pre-update content carries the evidence — the saved content no longer mentions
	 * access control at all, so it reads as an ordinary post save. Without this the
	 * notice keeps warning about content nobody has gated, with no later write to
	 * correct it.
	 */
	public function test_removing_block_rules_invalidates_the_cache() {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:group {"newspackAccessControlRules":{"registration":{"active":true}}} --><div></div><!-- /wp:group -->',
			]
		);
		Inert_Gating_Notice::flush_cache();
		$this->assertTrue( Inert_Gating_Notice::has_surfaces(), 'Expected the block rules to be found and cached.' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:group --><div></div><!-- /wp:group -->',
			]
		);

		$this->assertFalse(
			get_option( Inert_Gating_Notice::CACHE_OPTION, false ),
			'Stripping the last access-control attributes must invalidate the cached answer.'
		);
		$this->assertFalse( Inert_Gating_Notice::has_surfaces(), 'Nothing is configured any more.' );
	}

	/**
	 * The second and later autosaves update the same autosave row rather than
	 * inserting a new one, so they arrive on `post_updated` with a "before" that
	 * still carries the attributes. Without the revision guard on that handler the
	 * flush comes straight back through the new hook.
	 */
	public function test_repeated_autosaves_do_not_invalidate_the_cache() {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$content = '<!-- wp:group {"newspackAccessControlRules":{"registration":{"active":true}}} --><div></div><!-- /wp:group -->';
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_author'  => $user_id,
				'post_content' => $content,
			]
		);

		$autosave = [
			'post_ID'      => $post_id,
			'post_type'    => 'post',
			'post_title'   => 'Draft in progress',
			'post_content' => $content,
		];
		$first = wp_create_post_autosave( $autosave );
		$this->assertNotEmpty( $first, 'Fixture failed: no autosave was created.' );

		Inert_Gating_Notice::flush_cache();
		$this->assertTrue( Inert_Gating_Notice::has_surfaces() );

		$autosave['post_content'] = $content . '<!-- wp:paragraph --><p>Still typing.</p><!-- /wp:paragraph -->';
		$second                   = wp_create_post_autosave( $autosave );
		$this->assertSame( $first, $second, 'Fixture failed: the second autosave should reuse the same row.' );

		$this->assertSame(
			'1',
			get_option( Inert_Gating_Notice::CACHE_OPTION ),
			'An autosave cannot change the answer, so it must leave the cached value in place.'
		);
	}

	/**
	 * Revisions copy the parent's content verbatim, so without a guard every autosave
	 * of a gated post flushes — about once a minute while anyone has one open, on
	 * exactly the sites this cache exists for.
	 */
	public function test_revisions_do_not_invalidate_the_cache() {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:group {"newspackAccessControlMode":"custom","newspackAccessControlRules":{"registration":{"active":true}}} --><div></div><!-- /wp:group -->',
			]
		);
		Inert_Gating_Notice::flush_cache();
		$this->assertTrue( Inert_Gating_Notice::has_surfaces(), 'Expected the block rules to be found and cached.' );

		$revision_id = wp_save_post_revision( $post_id );
		$this->assertNotEmpty( $revision_id, 'Fixture failed: no revision was created.' );

		$this->assertSame(
			'1',
			get_option( Inert_Gating_Notice::CACHE_OPTION ),
			'A revision cannot change the answer, so it must leave the cached value in place.'
		);
	}

	/**
	 * A negative answer must cache too, or the `LIKE` runs on every admin page load
	 * of every site that never configured Access Control — which is most of them.
	 */
	public function test_a_negative_answer_is_cached() {
		$this->assertFalse( Inert_Gating_Notice::has_surfaces() );

		$this->assertSame(
			'0',
			get_option( Inert_Gating_Notice::CACHE_OPTION ),
			'Expected the negative answer to be stored as a distinguishable value.'
		);
	}
}
