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
				'post_content' => '<!-- wp:group {"newspackAccessControlMode":"custom"} --><div></div><!-- /wp:group -->',
			]
		);
		Inert_Gating_Notice::flush_cache();
		$this->disable_audience_management();

		$this->assertStringContainsString( 'are public for all readers', $this->render() );
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
				'post_content' => '<!-- wp:group {"newspackAccessControlVisibility":"visible"} --><div></div><!-- /wp:group -->',
			]
		);
		$this->assertFalse(
			get_option( Inert_Gating_Notice::CACHE_OPTION, false ),
			'A post carrying block rules must invalidate the cached answer.'
		);
		$this->assertTrue( Inert_Gating_Notice::has_surfaces() );
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
