<?php
/**
 * Tests for the two-phase idempotent send helper (NPPD-1768).
 *
 * Covers the claim → send → confirm state machine and its reconciliation of
 * interrupted claims, plus the `is_claimed()` skip predicate.
 *
 * The fake entity models WC_Data's stage-then-commit semantics: writes go to a
 * `staged` copy that `get_meta()` reads (like WC's in-memory meta), and only a
 * successful `save()` commits `staged` into `persisted` (the durable view a
 * fresh load on the next pass would see). A `save()` that throws commits
 * nothing — which is exactly what lets these tests verify the DURABILITY the
 * two-phase claim exists to provide (a claim survives a failed confirm save).
 *
 * @package Newspack\Tests
 */

use Newspack\Idempotent_Send;

/**
 * Idempotent_Send test case.
 */
class Newspack_Test_Idempotent_Send extends WP_UnitTestCase {

	const SENT_KEY    = '_test_sent_42';
	const PENDING_KEY = '_test_pending_42';
	const SEEDED_KEY  = '_test_seeded_42';
	const IDEM        = '42:12/2026';

	/**
	 * Remove the grace filter after each test so a test that tightens it
	 * cannot leak into a later one (and survives an assertion that throws
	 * before an inline cleanup would run).
	 */
	public function tear_down() {
		remove_all_filters( 'newspack_idempotent_send_grace_seconds' );
		parent::tear_down();
	}

	/**
	 * Build a fake WC_Data-like entity with stage-then-commit meta. `save()`
	 * throws while `throw_saves` is true (commits nothing); otherwise it
	 * commits `staged` into `persisted`. Tests assert on `persisted` for the
	 * durable, cross-pass truth.
	 *
	 * @param array $persisted Initial persisted meta as key => value.
	 * @return object
	 */
	private function make_entity( array $persisted = [] ) {
		return new class( $persisted ) {
			/**
			 * Working copy (reads + writes land here, like WC in-memory meta).
			 *
			 * @var array
			 */
			public $staged = [];
			/**
			 * Committed copy (what a fresh load on the next pass would see).
			 *
			 * @var array
			 */
			public $persisted = [];
			/**
			 * Count of save() calls.
			 *
			 * @var int
			 */
			public $save_calls = 0;
			/**
			 * When true, save() throws and commits nothing.
			 *
			 * @var bool
			 */
			public $throw_saves = false;
			/**
			 * When true, save() returns success but commits nothing — models
			 * WC_Abstract_Order::save() swallowing a write failure and still
			 * returning the object id.
			 *
			 * @var bool
			 */
			public $swallow_saves = false;
			/**
			 * Constructor.
			 *
			 * @param array $persisted Initial persisted meta.
			 */
			public function __construct( array $persisted ) {
				$this->persisted = $persisted;
				$this->staged    = $persisted;
			}
			/**
			 * Get a single (staged) meta value.
			 *
			 * @param string $key    Meta key.
			 * @param bool   $single Single flag (ignored).
			 * @return mixed Stored value or '' if absent.
			 */
			public function get_meta( $key, $single = true ) {
				return $this->staged[ $key ] ?? '';
			}
			/**
			 * Stage a meta value.
			 *
			 * @param string $key   Meta key.
			 * @param mixed  $value Value.
			 */
			public function update_meta_data( $key, $value ) {
				$this->staged[ $key ] = $value;
			}
			/**
			 * Stage a meta deletion.
			 *
			 * @param string $key Meta key.
			 */
			public function delete_meta_data( $key ) {
				unset( $this->staged[ $key ] );
			}
			/**
			 * Commit staged → persisted; throws (committing nothing) while
			 * throw_saves is set.
			 *
			 * @return bool
			 * @throws \RuntimeException When throw_saves is true.
			 */
			public function save() {
				++$this->save_calls;
				if ( $this->throw_saves ) {
					throw new \RuntimeException( 'transient save failure' );
				}
				if ( ! $this->swallow_saves ) {
					$this->persisted = $this->staged;
				}
				return true;
			}
		};
	}

	/**
	 * Base send args with a counting send callback that returns $return. The
	 * callback records its invocation count via $calls (passed by reference).
	 *
	 * @param int|null $calls  By-ref counter of send invocations.
	 * @param bool     $return What the send callback returns.
	 * @return array
	 */
	private function args( &$calls = null, bool $return = true ): array {
		$calls = 0;
		return [
			'sent_key'      => self::SENT_KEY,
			'pending_key'   => self::PENDING_KEY,
			'idem_value'    => self::IDEM,
			'send'          => function () use ( &$calls, $return ) {
				++$calls;
				return $return;
			},
			'logger_header' => 'NEWSPACK-TEST',
			'clear_on_send' => [ self::SEEDED_KEY ],
		];
	}

	/**
	 * Attach a `read_fresh` verifier that reads the entity's DURABLE store
	 * (modelling a forced-fresh DB read), so the helper's persistence
	 * verification is exercised against what actually committed.
	 *
	 * @param array  $args   Send args.
	 * @param object $entity The fake entity.
	 * @return array
	 */
	private function with_read_fresh( array $args, $entity ): array {
		$args['read_fresh'] = function ( $key ) use ( $entity ) {
			return $entity->persisted[ $key ] ?? '';
		};
		return $args;
	}

	// --------------------------------------------------------------------
	// Guard rails.
	// --------------------------------------------------------------------

	/**
	 * A missing required arg returns false without sending.
	 */
	public function test_missing_required_arg_returns_false() {
		$entity = $this->make_entity();
		$args   = $this->args( $calls );
		unset( $args['send'] );

		$this->assertFalse( Idempotent_Send::send( $entity, $args ), 'Missing send callback must return false.' );
		$this->assertSame( 0, $calls, 'Nothing should be sent when a required arg is missing.' );
		$this->assertSame( 0, $entity->save_calls, 'Nothing should be saved when a required arg is missing.' );
	}

	/**
	 * An entity missing the WC_Data meta API returns false without sending.
	 */
	public function test_bad_entity_returns_false() {
		$this->assertFalse(
			Idempotent_Send::send( new \stdClass(), $this->args( $calls ) ),
			'An entity missing the WC_Data meta API must return false.'
		);
		$this->assertSame( 0, $calls, 'A bad entity must not invoke the send callback.' );
	}

	// --------------------------------------------------------------------
	// Skip paths (SENT + recent claim).
	// --------------------------------------------------------------------

	/**
	 * A matching SENT marker blocks: no send, nothing written.
	 */
	public function test_sent_marker_blocks_without_sending() {
		$entity = $this->make_entity( [ self::SENT_KEY => self::IDEM ] );
		$result = Idempotent_Send::send( $entity, $this->args( $calls ) );

		$this->assertFalse( $result, 'A matching SENT marker must short-circuit to false.' );
		$this->assertSame( 0, $calls, 'The send callback must not run when already sent.' );
		$this->assertSame( 0, $entity->save_calls, 'No save should occur when already sent.' );
		$this->assertArrayNotHasKey( self::PENDING_KEY, $entity->persisted, 'No pending claim should be written.' );
	}

	/**
	 * A recent matching PENDING claim is treated as a concurrent in-progress
	 * send and skipped — the best-effort guard.
	 */
	public function test_recent_pending_claim_skips() {
		$entity = $this->make_entity(
			[
				self::PENDING_KEY => [
					'value' => self::IDEM,
					'ts'    => time() - 10,
				],
			]
		);
		$result = Idempotent_Send::send( $entity, $this->args( $calls ) );

		$this->assertFalse( $result, 'A recent claim must skip.' );
		$this->assertSame( 0, $calls, 'No send while a recent claim is held.' );
		$this->assertSame( 0, $entity->save_calls );
	}

	/**
	 * A stale matching PENDING claim (older than the grace window) re-sends:
	 * the claiming pass died without confirming, and over-send beats a miss.
	 * The send completes and the claim is promoted to SENT.
	 */
	public function test_stale_pending_claim_resends() {
		$entity = $this->make_entity(
			[
				self::PENDING_KEY => [
					'value' => self::IDEM,
					'ts'    => time() - ( 2 * HOUR_IN_SECONDS ),
				],
			]
		);
		$result = Idempotent_Send::send( $entity, $this->args( $calls ) );

		$this->assertTrue( $result, 'A stale claim must re-send.' );
		$this->assertSame( 1, $calls, 'The send callback must run exactly once.' );
		$this->assertSame( self::IDEM, $entity->persisted[ self::SENT_KEY ], 'SENT must be durably written on confirm.' );
		$this->assertArrayNotHasKey( self::PENDING_KEY, $entity->persisted, 'The stale claim must be cleared.' );
	}

	// --------------------------------------------------------------------
	// Happy path + durability.
	// --------------------------------------------------------------------

	/**
	 * A clean send claims PENDING durably BEFORE sending, then promotes the
	 * claim to SENT and clears the companion (seeded) key. The send callback
	 * asserts the claim is already persisted when it runs.
	 */
	public function test_clean_send_claims_before_send_then_confirms() {
		$entity        = $this->make_entity( [ self::SEEDED_KEY => self::IDEM ] );
		$claim_seen    = null;
		$saves_at_send = null;
		$calls         = 0;
		// With a read_fresh verifier so the happy path exercises (and must not
		// false-fail) the persistence verification.
		$args          = $this->with_read_fresh( $this->args( $calls ), $entity );
		$args['send']  = function () use ( $entity, &$claim_seen, &$saves_at_send, &$calls ) {
			++$calls;
			$claim_seen    = $entity->persisted[ self::PENDING_KEY ] ?? null;
			$saves_at_send = $entity->save_calls;
			return true;
		};

		$result = Idempotent_Send::send( $entity, $args );

		$this->assertTrue( $result );
		$this->assertSame( 1, $calls );
		$this->assertIsArray( $claim_seen, 'The PENDING claim must be DURABLE (persisted) before the send runs.' );
		$this->assertSame( self::IDEM, $claim_seen['value'], 'The claim must carry the idempotency value.' );
		$this->assertSame( 1, $saves_at_send, 'Exactly one save (the claim) must precede the send.' );
		$this->assertSame( self::IDEM, $entity->persisted[ self::SENT_KEY ], 'SENT must be written on confirm.' );
		$this->assertArrayNotHasKey( self::PENDING_KEY, $entity->persisted, 'The claim must be cleared on confirm.' );
		$this->assertArrayNotHasKey( self::SEEDED_KEY, $entity->persisted, 'clear_on_send keys must be removed on confirm.' );
	}

	/**
	 * Durability invariant (the whole reason the two-phase claim exists):
	 * when the confirm save fails after the mail is accepted, the helper still
	 * returns true AND the PENDING claim SURVIVES in the durable store (SENT
	 * does not), so the next post-grace pass can reconcile it. With WC's
	 * stage-then-commit semantics the failed confirm commits nothing, so
	 * `persisted` keeps the claim from the earlier (successful) claim save.
	 */
	public function test_pending_claim_survives_failed_confirm_save() {
		$entity       = $this->make_entity();
		$calls        = 0;
		$args         = $this->args( $calls );
		$args['send'] = function () use ( $entity, &$calls ) {
			++$calls;
			$entity->throw_saves = true; // Fail only the confirm save (claim already committed).
			return true;
		};

		$result = Idempotent_Send::send( $entity, $args );

		$this->assertTrue( $result, 'A confirmed send returns true even if the SENT-marker save fails.' );
		$this->assertSame( 1, $calls );
		$this->assertSame(
			1 + Idempotent_Send::DEFAULT_SAVE_ATTEMPTS,
			$entity->save_calls,
			'One successful claim save, then the confirm save retried to the budget.'
		);
		$this->assertArrayHasKey( self::PENDING_KEY, $entity->persisted, 'The claim must SURVIVE a failed confirm so a later pass can reconcile it.' );
		$this->assertSame( self::IDEM, $entity->persisted[ self::PENDING_KEY ]['value'] );
		$this->assertArrayNotHasKey( self::SENT_KEY, $entity->persisted, 'SENT must NOT be durably set when its save failed.' );
	}

	// --------------------------------------------------------------------
	// Failure paths.
	// --------------------------------------------------------------------

	/**
	 * When the send returns false, the claim is durably released so a later
	 * pass retries cleanly, and no SENT marker is written.
	 */
	public function test_send_failure_releases_claim() {
		$entity = $this->make_entity();
		$result = Idempotent_Send::send( $entity, $this->args( $calls, false ) );

		$this->assertFalse( $result, 'A failed send returns false.' );
		$this->assertSame( 1, $calls );
		$this->assertArrayNotHasKey( self::PENDING_KEY, $entity->persisted, 'A failed send must durably release the claim.' );
		$this->assertArrayNotHasKey( self::SENT_KEY, $entity->persisted, 'A failed send must not mark SENT.' );
	}

	/**
	 * If the send fails AND the claim-release save also fails (a rare double
	 * failure), the claim persists — a known cost the helper logs. This pins
	 * the behavior so a future change doesn't silently make it worse or
	 * better without noticing.
	 */
	public function test_failed_release_leaves_claim_persisted() {
		$entity       = $this->make_entity();
		$calls        = 0;
		$args         = $this->args( $calls );
		$args['send'] = function () use ( $entity, &$calls ) {
			++$calls;
			$entity->throw_saves = true; // Fail the release save (claim already committed).
			return false;
		};

		$result = Idempotent_Send::send( $entity, $args );

		$this->assertFalse( $result, 'A failed send returns false.' );
		$this->assertSame( 1, $calls );
		$this->assertArrayHasKey( self::PENDING_KEY, $entity->persisted, 'When the release save also fails, the claim persists (logged).' );
	}

	/**
	 * If the PENDING claim cannot be persisted, the helper does NOT send —
	 * it never sends without a durable claim.
	 */
	public function test_send_skipped_when_claim_cannot_persist() {
		$entity              = $this->make_entity();
		$entity->throw_saves = true; // Every save() fails, including the claim.
		$result              = Idempotent_Send::send( $entity, $this->args( $calls ) );

		$this->assertFalse( $result, 'No send when the claim cannot be persisted.' );
		$this->assertSame( 0, $calls, 'The send callback must not run without a durable claim.' );
		$this->assertSame( Idempotent_Send::DEFAULT_SAVE_ATTEMPTS, $entity->save_calls, 'The claim save is retried to the budget.' );
		$this->assertArrayNotHasKey( self::PENDING_KEY, $entity->persisted, 'No claim is durably written when its save fails.' );
	}

	// --------------------------------------------------------------------
	// Verified persistence — WC swallows save() failures (a non-throwing
	// save() is not proof of persistence). With a read_fresh verifier the
	// helper re-reads the durable store and treats an unpersisted marker as
	// a failure, even though save() "succeeded".
	// --------------------------------------------------------------------

	/**
	 * Claim path: save() succeeds (no throw) but commits nothing — the WC
	 * swallow case. The read_fresh verifier catches that the claim never
	 * landed, so the helper does NOT send.
	 */
	public function test_no_send_when_claim_save_silently_swallows() {
		$entity                = $this->make_entity();
		$entity->swallow_saves = true; // save() returns success but persists nothing.
		$result                = Idempotent_Send::send( $entity, $this->with_read_fresh( $this->args( $calls ), $entity ) );

		$this->assertFalse( $result, 'A claim that did not durably persist must not lead to a send.' );
		$this->assertSame( 0, $calls, 'The send callback must not run when the claim is unverified.' );
		$this->assertArrayNotHasKey( self::PENDING_KEY, $entity->persisted );
	}

	/**
	 * Confirm path: the mail is accepted, but the SENT-marker save() succeeds
	 * without persisting (WC swallow). The verifier catches it: the helper
	 * still returns true (a send happened), the SENT marker is NOT durable,
	 * and the PENDING claim survives for the next post-grace pass to reconcile.
	 */
	public function test_confirm_unverified_keeps_claim_when_sent_marker_swallowed() {
		$entity       = $this->make_entity();
		$calls        = 0;
		$args         = $this->with_read_fresh( $this->args( $calls ), $entity );
		$args['send'] = function () use ( $entity, &$calls ) {
			++$calls;
			$entity->swallow_saves = true; // Confirm save returns ok but persists nothing.
			return true;
		};

		$result = Idempotent_Send::send( $entity, $args );

		$this->assertTrue( $result, 'A confirmed send returns true even if the SENT marker is not durable.' );
		$this->assertSame( 1, $calls );
		$this->assertArrayNotHasKey( self::SENT_KEY, $entity->persisted, 'SENT must not be durable when its save was swallowed.' );
		$this->assertArrayHasKey( self::PENDING_KEY, $entity->persisted, 'The claim must survive so a later pass can reconcile.' );
	}

	/**
	 * A throwing send callback (e.g. a wp_mail filter that throws) must
	 * release the durable claim and rethrow, so the caller's per-pair
	 * Throwable handling still sees the original error and no orphan claim is
	 * left to suppress retries.
	 */
	public function test_throwing_send_releases_claim_and_rethrows() {
		$entity       = $this->make_entity();
		$args         = $this->with_read_fresh( $this->args( $calls ), $entity );
		$args['send'] = function () {
			throw new \RuntimeException( 'wp_mail filter blew up' );
		};

		try {
			Idempotent_Send::send( $entity, $args );
			$this->fail( 'The original exception must propagate.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_mail filter blew up', $e->getMessage() );
		}
		$this->assertArrayNotHasKey( self::PENDING_KEY, $entity->persisted, 'A throwing send must release (not orphan) the claim.' );
		$this->assertArrayNotHasKey( self::SENT_KEY, $entity->persisted );
	}

	/**
	 * The throwing-send path uses the SAME verified release as the false-return
	 * path: if the claim-release save is swallowed by WC (returns success but
	 * persists nothing), the helper detects the un-landed release, emits a
	 * Manager-visible degraded log, and STILL rethrows the ORIGINAL send error.
	 * The secondary verify/log work must never mask that original error, and
	 * the surviving claim must not go unlogged.
	 */
	public function test_throwing_send_with_swallowed_release_logs_and_rethrows() {
		$entity   = $this->make_entity();
		$args     = $this->with_read_fresh( $this->args( $calls ), $entity );
		$logged   = [];
		$listener = function ( $code ) use ( &$logged ) {
			$logged[] = $code;
		};
		add_action( 'newspack_log', $listener );

		$args['send'] = function () use ( $entity ) {
			$entity->swallow_saves = true; // Release save "succeeds" but persists nothing.
			throw new \RuntimeException( 'wp_mail filter blew up' );
		};

		try {
			Idempotent_Send::send( $entity, $args );
			$this->fail( 'The original exception must propagate even when the release is swallowed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_mail filter blew up', $e->getMessage(), 'The ORIGINAL send error must be rethrown, not a cleanup error.' );
		} finally {
			remove_action( 'newspack_log', $listener );
		}

		$this->assertArrayHasKey( self::PENDING_KEY, $entity->persisted, 'A swallowed release leaves the claim; it must not be silently dropped.' );
		$this->assertContains(
			'newspack_idempotent_send_release_failed',
			$logged,
			'A swallowed release after a throwing send must emit a Manager-visible degraded log.'
		);
	}

	// --------------------------------------------------------------------
	// Value-match, non-array claim, multi-key clear, grace filter.
	// --------------------------------------------------------------------

	/**
	 * Value-match: a SENT marker carrying a DIFFERENT value (e.g. the card's
	 * prior expiry tuple) does not block a new idempotency value.
	 */
	public function test_value_change_is_not_blocked_by_stale_marker() {
		$entity = $this->make_entity( [ self::SENT_KEY => '42:01/2025' ] ); // Old expiry.
		$result = Idempotent_Send::send( $entity, $this->args( $calls ) ); // New IDEM = 42:12/2026.

		$this->assertTrue( $result, 'A SENT marker for a different value must not block the new value.' );
		$this->assertSame( 1, $calls );
		$this->assertSame( self::IDEM, $entity->persisted[ self::SENT_KEY ], 'SENT is updated to the new value.' );
	}

	/**
	 * A malformed (non-array) PENDING value — a legacy or corrupted marker —
	 * is ignored by the array/isset guard, and the send proceeds and
	 * overwrites it with a well-formed claim rather than fatal-ing.
	 */
	public function test_non_array_pending_is_overwritten_and_send_proceeds() {
		$entity = $this->make_entity( [ self::PENDING_KEY => 'corrupt-scalar' ] );
		$result = Idempotent_Send::send( $entity, $this->args( $calls ) );

		$this->assertTrue( $result, 'A non-array pending value must not block or fatal; the send proceeds.' );
		$this->assertSame( 1, $calls );
		$this->assertSame( self::IDEM, $entity->persisted[ self::SENT_KEY ] );
		$this->assertArrayNotHasKey( self::PENDING_KEY, $entity->persisted, 'The malformed claim is replaced and cleared on confirm.' );
	}

	/**
	 * The clear_on_send list removes EVERY companion key on confirm, not just the first.
	 */
	public function test_clear_on_send_clears_all_keys() {
		$entity                = $this->make_entity(
			[
				'_companion_a' => self::IDEM,
				'_companion_b' => self::IDEM,
			]
		);
		$args                  = $this->args( $calls );
		$args['clear_on_send'] = [ '_companion_a', '_companion_b' ];

		$this->assertTrue( Idempotent_Send::send( $entity, $args ) );
		$this->assertArrayNotHasKey( '_companion_a', $entity->persisted, 'First clear_on_send key must be removed.' );
		$this->assertArrayNotHasKey( '_companion_b', $entity->persisted, 'Second clear_on_send key must be removed.' );
	}

	/**
	 * The grace window is filterable: a 30-minute-old claim is "recent" under
	 * the default 1-hour grace (skip), but "stale" once the filter tightens
	 * the window to 10 minutes (re-send).
	 */
	public function test_grace_window_is_filterable() {
		$make = function () {
			return $this->make_entity(
				[
					self::PENDING_KEY => [
						'value' => self::IDEM,
						'ts'    => time() - ( 30 * MINUTE_IN_SECONDS ),
					],
				]
			);
		};

		$this->assertFalse(
			Idempotent_Send::send( $make(), $this->args( $calls ) ),
			'Under the default 1h grace, a 30m-old claim is recent → skip.'
		);
		$this->assertSame( 0, $calls );

		add_filter( 'newspack_idempotent_send_grace_seconds', fn() => 10 * MINUTE_IN_SECONDS );
		$this->assertTrue(
			Idempotent_Send::send( $make(), $this->args( $calls ) ),
			'With a 10m grace, the 30m-old claim is stale → re-send.'
		);
		$this->assertSame( 1, $calls );
	}

	// --------------------------------------------------------------------
	// is_claimed() predicate.
	// --------------------------------------------------------------------

	/**
	 * The is_claimed predicate reflects the skip decision: true for a matching
	 * SENT marker and a recent matching claim; false for stale, mismatched, or absent.
	 */
	public function test_is_claimed_predicate() {
		$args = [
			'sent_key'    => self::SENT_KEY,
			'pending_key' => self::PENDING_KEY,
			'idem_value'  => self::IDEM,
		];

		$this->assertTrue(
			Idempotent_Send::is_claimed( $this->make_entity( [ self::SENT_KEY => self::IDEM ] ), $args ),
			'A matching SENT marker is claimed.'
		);
		$this->assertTrue(
			Idempotent_Send::is_claimed(
				$this->make_entity(
					[
						self::PENDING_KEY => [
							'value' => self::IDEM,
							'ts'    => time() - 10,
						],
					] 
				),
				$args
			),
			'A recent matching claim is claimed.'
		);
		$this->assertFalse(
			Idempotent_Send::is_claimed(
				$this->make_entity(
					[
						self::PENDING_KEY => [
							'value' => self::IDEM,
							'ts'    => time() - ( 2 * HOUR_IN_SECONDS ),
						],
					] 
				),
				$args
			),
			'A stale claim is NOT claimed (it re-sends).'
		);
		$this->assertFalse(
			Idempotent_Send::is_claimed( $this->make_entity( [ self::SENT_KEY => '42:01/2025' ] ), $args ),
			'A SENT marker for a different value is not claimed.'
		);
		$this->assertFalse(
			Idempotent_Send::is_claimed( $this->make_entity(), $args ),
			'An unmarked entity is not claimed.'
		);
	}

	// --------------------------------------------------------------------
	// Bounded save retry.
	// --------------------------------------------------------------------

	/**
	 * The bounded save retry rides out transient failures within the budget.
	 */
	public function test_save_with_retry_succeeds_after_transient_failures() {
		$entity = new class() {
			/**
			 * Remaining throws.
			 *
			 * @var int
			 */
			public $remaining = 2;
			/**
			 * Save calls.
			 *
			 * @var int
			 */
			public $save_calls = 0;
			/**
			 * Save, throwing twice then succeeding.
			 *
			 * @return bool
			 * @throws \RuntimeException While throws remain.
			 */
			public function save() {
				++$this->save_calls;
				if ( $this->remaining > 0 ) {
					--$this->remaining;
					throw new \RuntimeException( 'transient' );
				}
				return true;
			}
		};

		$result = Idempotent_Send::save_with_retry( $entity, 3 );
		$this->assertTrue( $result['saved'] );
		$this->assertSame( 3, $entity->save_calls, '2 throws + 1 success.' );
	}

	/**
	 * The bounded save retry gives up after the budget and surfaces the error.
	 */
	public function test_save_with_retry_gives_up_after_budget() {
		$entity = new class() {
			/**
			 * Save calls.
			 *
			 * @var int
			 */
			public $save_calls = 0;
			/**
			 * Always throws.
			 *
			 * @throws \RuntimeException Always.
			 */
			public function save() {
				++$this->save_calls;
				throw new \RuntimeException( 'persistent failure' );
			}
		};

		$result = Idempotent_Send::save_with_retry( $entity, 3 );
		$this->assertFalse( $result['saved'] );
		$this->assertSame( 3, $entity->save_calls );
		$this->assertSame( 'persistent failure', $result['last_error'] );
	}
}
