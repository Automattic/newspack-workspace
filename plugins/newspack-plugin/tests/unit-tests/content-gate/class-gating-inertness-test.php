<?php
/**
 * Tests for the gating-inertness contract.
 *
 * NPPD-1846 — Audience Management is a hard prerequisite for Access Control.
 * Everything a gate hands the reader off to (registration, sign-in, account
 * emails, session hydration, My Account) is gated on it, so a gate enforced
 * without it locks readers out with no way in. Gating therefore stands down
 * rather than half-working: gates stay configured and restrict nothing.
 *
 * "Stands down" only means something if it holds on EVERY reader-facing
 * enforcement path. It did not before this suite existed: block-level access
 * control evaluated gate rules itself and kept hiding blocks, and the premium
 * newsletter access check read "not restricted" as "grant access" and pushed
 * that to the ESP. Both were reachable with the feature constant undefined too,
 * so neither was specific to Audience Management.
 *
 * Each test therefore pins one enforcement path against
 * {@see Content_Gate::is_gating_active()} in both directions. A new enforcement
 * surface that asks the question for itself is the failure this suite is
 * designed to catch, so a new path belongs here as a new case.
 *
 * Note that `is_gating_active()` is NOT the predicate everywhere: block-level
 * access control uses `Reader_Activation::is_enabled()` alone, because it is
 * flag-independent and ANDing the feature constant in would unhide blocks on
 * sites that never enabled content gates. See `Block_Visibility::filter_render_block()`.
 *
 * Every case here is mutation-tested: removing its guard must fail it. A case that
 * passes either way is worse than no case.
 *
 * One guard is deliberately NOT covered here: the one in
 * `Premium_Newsletters::check_access()`. Four attempts at it were all vacuous —
 * they passed with the guard deleted, because `add_and_remove_lists()` returns
 * before touching the ESP once `get_public_id()` yields nothing for a plain list
 * fixture, so the branch under test is never reached. Covering it needs a list
 * fixture carrying a real public ID, which `Newspack_Test_Premium_Newsletters`
 * already builds; that is where the case belongs. The guard itself is defence in
 * depth — the chokepoint and renewal guards below stop the queue filling in the
 * first place — but it is the one whose failure would write to the ESP, so it
 * deserves its own test.
 *
 * @package Newspack\Tests
 */

use Newspack\Content_Gate;
use Newspack\Content_Restriction_Control;
use Newspack\Block_Visibility;
use Newspack\Premium_Newsletters;

/**
 * Tests that gating goes inert consistently across enforcement paths.
 */
class Gating_Inertness_Test extends WP_UnitTestCase {

	/**
	 * Content gates are flag-gated; the CPT must be registered for gate posts to exist.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Reset per-request state between cases.
	 *
	 * Content_Restriction_Control memoises its gate lookups in static maps for the
	 * life of a request; without clearing them a later case reads the previous
	 * case's verdict rather than re-evaluating under the new setting.
	 */
	public function set_up() {
		parent::set_up();
		$this->reset_restriction_caches();
	}

	/**
	 * Remove any filter a case added, so cases don't leak into each other.
	 */
	public function tear_down() {
		remove_all_filters( 'newspack_reader_activation_enabled' );
		delete_option( Premium_Newsletters::QUEUE_OPTION );
		wp_clear_scheduled_hook( Premium_Newsletters::SCHEDULED_HOOK );
		parent::tear_down();
	}

	/**
	 * Clear Content_Restriction_Control's per-request memoisation.
	 */
	private function reset_restriction_caches() {
		foreach ( [ 'post_gates_map', 'post_gate_id_map', 'post_gate_layout_id_map' ] as $property ) {
			$reflected = new ReflectionProperty( Content_Restriction_Control::class, $property );
			$reflected->setAccessible( true );
			$reflected->setValue( null, [] );
		}
		Content_Gate::flush_gates_cache();
	}

	/**
	 * Turn Audience Management off for the current test.
	 *
	 * Filter rather than option: `Reader_Activation::is_enabled()` defaults to true
	 * under IS_TEST_ENV, and applies the filter over that default precisely so the
	 * disabled path is reachable from tests.
	 */
	private function disable_audience_management() {
		add_filter( 'newspack_reader_activation_enabled', '__return_false' );
		$this->reset_restriction_caches();
	}

	/**
	 * Create a published gate that restricts all posts behind registration.
	 *
	 * @param bool $is_newsletter Whether this is a premium newsletter gate.
	 *
	 * @return int The gate post ID.
	 */
	private function create_registration_gate( $is_newsletter = false ) {
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
			],
			Content_Gate::GATE_CPT,
			$is_newsletter
		);
		$this->assertNotWPError( $gate_id, 'Gate fixture could not be created.' );
		$this->reset_restriction_caches();
		return $gate_id;
	}

	/**
	 * The predicate itself: both conditions independently stand gating down.
	 */
	public function test_is_gating_active_requires_audience_management() {
		$this->assertTrue( Content_Gate::is_gating_active(), 'Expected gating active with the feature flag and Audience Management on.' );

		$this->disable_audience_management();

		$this->assertFalse( Content_Gate::is_gating_active(), 'Expected gating inactive with Audience Management off.' );
	}

	/**
	 * The renewal handler bails before the snapshot it would otherwise take, which
	 * costs a remote ESP round-trip and a user-meta write for an access check that
	 * cannot run. (The chokepoint guard that also covers this handler is exercised by
	 * test_premium_newsletter_access_checks_are_not_enqueued, which reaches it.)
	 */
	public function test_renewal_events_are_not_enqueued() {
		$user_id = self::factory()->user->create();
		$this->disable_audience_management();

		Premium_Newsletters::set_subscribed_lists( time(), [ 'user_id' => $user_id ], 0 );

		$this->assertEmpty(
			get_option( Premium_Newsletters::QUEUE_OPTION, [] ),
			'A renewal event should not queue an access check while gating is inactive.'
		);
		$this->assertEmpty(
			get_user_meta( $user_id, Premium_Newsletters::SUBSCRIBED_LISTS_META_KEY, true ),
			'The renewal snapshot should not be taken while gating is inactive.'
		);
	}

	/**
	 * Signup forms follow the same rule as everything else: with gating inactive a
	 * restricted list stops being filtered out and becomes joinable by anyone. That
	 * is intended — "Audience Management off" means Access Control restricts nothing
	 * at all — and the disable confirmation states it before the publisher commits.
	 */
	public function test_premium_lists_reappear_in_signup_forms_while_inert() {
		$this->create_registration_gate();
		$list_id = self::factory()->post->create();
		// filter_subscription_lists() only ever calls get_id() on each list.
		$list = new class( $list_id ) {
			/**
			 * List ID.
			 *
			 * @var int
			 */
			private $id;

			/**
			 * Constructor.
			 *
			 * @param int $id List ID.
			 */
			public function __construct( $id ) {
				$this->id = $id;
			}

			/**
			 * Get the list ID.
			 *
			 * @return int
			 */
			public function get_id() {
				return $this->id;
			}
		};

		$this->assertSame(
			[],
			Premium_Newsletters::filter_subscription_lists( [ $list ] ),
			'A gated list should be hidden from signup forms while gating is active.'
		);

		$this->disable_audience_management();

		$this->assertSame(
			[ $list ],
			Premium_Newsletters::filter_subscription_lists( [ $list ] ),
			'A gated list should be offered again once gating is inactive.'
		);
	}

	/**
	 * The article path: a gate that restricts stops restricting.
	 */
	public function test_post_restriction_goes_inert() {
		$this->create_registration_gate();
		$post_id = self::factory()->post->create();

		$this->assertTrue(
			Content_Restriction_Control::is_post_restricted( false, $post_id, 0 ),
			'Expected the gate to restrict an anonymous reader while gating is active.'
		);

		$this->disable_audience_management();

		$this->assertFalse(
			Content_Restriction_Control::is_post_restricted( false, $post_id, 0 ),
			'Expected the gate to stop restricting once gating is inactive.'
		);
	}

	/**
	 * Pass-through, not `false`: this callback decides only whether *our* gates
	 * restrict, and must never overturn a verdict another callback already reached.
	 */
	public function test_post_restriction_passes_an_existing_verdict_through() {
		$this->create_registration_gate();
		$post_id = self::factory()->post->create();
		$this->disable_audience_management();

		$this->assertTrue(
			Content_Restriction_Control::is_post_restricted( true, $post_id, 0 ),
			'An incoming restricted verdict must survive the inert short-circuit.'
		);
	}

	/**
	 * Block-level access control evaluates gate rules itself rather than asking the
	 * restriction filter, so it needs the predicate explicitly. Without it a
	 * members-only block stayed hidden while the article around it rendered in full.
	 */
	public function test_block_visibility_goes_inert() {
		$gate_id = $this->create_registration_gate();
		$block   = [
			'blockName' => 'core/group',
			'attrs'     => [
				'newspackAccessControlMode'       => 'gate',
				'newspackAccessControlGateIds'    => [ $gate_id ],
				'newspackAccessControlVisibility' => 'visible',
			],
		];
		$content = '<div class="wp-block-group">members only</div>';

		$this->assertSame(
			'',
			Block_Visibility::filter_render_block( $content, $block ),
			'Expected the block to be hidden from an anonymous reader while gating is active.'
		);

		$this->disable_audience_management();

		$this->assertSame(
			$content,
			Block_Visibility::filter_render_block( $content, $block ),
			'Expected the block to render untouched once gating is inactive.'
		);
	}

	/**
	 * The premium newsletter queue must not fill while gating is inactive, so
	 * re-enabling processes current events rather than a backlog of stale ones.
	 */
	public function test_premium_newsletter_access_checks_are_not_enqueued() {
		$user_id = self::factory()->user->create();
		$this->disable_audience_management();

		Premium_Newsletters::maybe_enqueue_access_check( time(), [ 'user_id' => $user_id ], 0, '' );

		$this->assertEmpty(
			get_option( Premium_Newsletters::QUEUE_OPTION, [] ),
			'Nothing should be queued for an access check while gating is inactive.'
		);
	}

	/**
	 * The scheduled event is cleared on the first request after gating goes
	 * inactive, and re-armed on the first request after it comes back — whatever
	 * changed the setting, including a direct option write.
	 */
	public function test_access_check_event_unschedules_and_rearms() {
		Premium_Newsletters::register_access_check_event();
		$this->assertNotFalse(
			wp_next_scheduled( Premium_Newsletters::SCHEDULED_HOOK ),
			'Expected the access-check event to be scheduled while gating is active.'
		);

		$this->disable_audience_management();
		update_option( Premium_Newsletters::QUEUE_OPTION, [ 1 ] );
		Premium_Newsletters::register_access_check_event();

		$this->assertFalse(
			wp_next_scheduled( Premium_Newsletters::SCHEDULED_HOOK ),
			'Expected the access-check event to be unscheduled once gating is inactive.'
		);
		$this->assertEmpty(
			get_option( Premium_Newsletters::QUEUE_OPTION, [] ),
			'Expected the pending queue to be discarded rather than held while gating is inactive.'
		);

		remove_filter( 'newspack_reader_activation_enabled', '__return_false' );
		Premium_Newsletters::register_access_check_event();

		$this->assertNotFalse(
			wp_next_scheduled( Premium_Newsletters::SCHEDULED_HOOK ),
			'Expected the access-check event to be re-armed once gating is active again.'
		);
	}
}
