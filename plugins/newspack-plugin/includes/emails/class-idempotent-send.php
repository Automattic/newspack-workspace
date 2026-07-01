<?php
/**
 * Two-phase idempotent transactional-email send.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotent_Send.
 *
 * Sends a transactional email at most once per (entity, idempotency value)
 * using a durable two-phase claim stored on the entity's own metadata. This
 * closes the window the single-phase "send then mark" approach leaves open:
 * if a process dies after the mail is accepted but before the SENT marker is
 * persisted, a later pass would re-send.
 *
 * Flow:
 *
 *   1. SENT marker present and matching → already delivered, skip.
 *   2. PENDING claim present and matching:
 *        - recent (within the grace window) → another pass is mid-send;
 *          skip (best-effort guard — see the concurrency note below).
 *        - stale (older than the grace window) → the claiming pass died
 *          between send() and confirm. We cannot know whether the mail went
 *          out, so we RE-SEND and log it. This is a deliberate over-send
 *          policy: for these warnings a rare duplicate is far less harmful
 *          than a silent miss. Callers that prefer the opposite trade-off
 *          should not use this helper as-is.
 *   3. Otherwise: write the PENDING claim durably (never send without a
 *      durable claim), send, then promote the claim to SENT durably. A death
 *      between send and promote leaves a PENDING claim that the next
 *      post-grace pass reconciles per step 2.
 *
 * Concurrency: the PENDING claim is a *best-effort* guard, NOT a
 * mutual-exclusion lock. It is a non-atomic read-then-write, so two passes
 * that each read "no claim" before either persists one can still both send.
 * It reliably catches *overlapping* passes (e.g. a daily cron still running
 * when an operator starts a CLI backfill) but not perfectly *simultaneous*
 * ones, and per-process WC meta caches widen that window further. A hard
 * guarantee would need an atomic primitive (a DB unique index, MySQL
 * GET_LOCK, or wp_cache_add). For the card-expiry use case — a daily cron
 * plus occasional manual backfill — best-effort is sufficient: the residual
 * race is a rare duplicate, the accepted failure direction.
 *
 * The entity is any WC_Data-backed object (WC_Subscription, WC_Order, ...);
 * it is duck-typed on get_meta / update_meta_data / delete_meta_data / save,
 * so this helper carries no hard WooCommerce dependency.
 *
 * Value-match (`=== $idem_value`) on both markers is intentional: when the
 * idempotency value encodes mutable state (e.g. a card's expiry tuple), a
 * change in that state yields a new value which the stored marker no longer
 * matches — so the next cycle is correctly not blocked by a stale mark.
 * Callers should pass a string idem value; the markers are compared strictly.
 */
final class Idempotent_Send {

	/**
	 * Default bounded save attempts (a transient save() is retried).
	 */
	const DEFAULT_SAVE_ATTEMPTS = 3;

	/**
	 * Log header used when the caller does not supply one.
	 */
	const LOGGER_HEADER = 'NEWSPACK-IDEMPOTENT-SEND';

	/**
	 * Whether a send should be SKIPPED right now for this (entity, value):
	 * the SENT marker already matches, or a matching PENDING claim is still
	 * recent (within the grace window). A *stale* PENDING claim returns
	 * false — it does not block, it re-sends (see `send()`).
	 *
	 * This is the public predicate callers use to pre-filter or count work
	 * without sending (e.g. a CLI backfill estimate), so their view stays
	 * consistent with what `send()` will actually do. The PENDING marker's
	 * shape and grace logic live here, not in callers.
	 *
	 * @param object $entity The WC_Data-backed entity carrying the markers.
	 * @param array  $args {
	 *     Predicate arguments.
	 *
	 *     @type string $sent_key      Meta key for the SENT marker. Required.
	 *     @type string $pending_key   Meta key for the PENDING claim. Required.
	 *     @type string $idem_value    Idempotency value to match. Required.
	 *     @type int    $grace_seconds Seconds a claim is "in progress". Optional.
	 * }
	 * @return bool True if a send should be skipped right now.
	 */
	public static function is_claimed( $entity, array $args ): bool {
		if ( ! isset( $args['sent_key'], $args['pending_key'], $args['idem_value'] ) ) {
			return false;
		}
		if ( ! is_object( $entity ) || ! method_exists( $entity, 'get_meta' ) ) {
			return false;
		}
		$idem = $args['idem_value'];

		// SENT always blocks. Checked first and without computing grace, so
		// the common already-delivered case stays cheap.
		if ( $entity->get_meta( $args['sent_key'], true ) === $idem ) {
			return true;
		}

		// A matching PENDING claim blocks only while it is recent.
		$pending = $entity->get_meta( $args['pending_key'], true );
		if ( self::is_pending_for( $pending, $idem ) && isset( $pending['ts'] ) ) {
			return ( time() - (int) $pending['ts'] ) < self::grace_seconds( $args );
		}
		return false;
	}

	/**
	 * Perform a two-phase idempotent send.
	 *
	 * @param object $entity The WC_Data-backed entity carrying the markers.
	 * @param array  $args {
	 *     Send arguments.
	 *
	 *     @type string   $sent_key      Meta key for the SENT marker. Required.
	 *     @type string   $pending_key   Meta key for the PENDING claim. Required.
	 *     @type string   $idem_value    Idempotency value to match. Required.
	 *     @type callable $send          () => bool performing the actual send. Required.
	 *     @type string   $logger_header Log header. Default self::LOGGER_HEADER.
	 *     @type int      $grace_seconds Seconds a claim is "in progress". Default filterable HOUR_IN_SECONDS.
	 *     @type int      $save_attempts Bounded save retries. Default self::DEFAULT_SAVE_ATTEMPTS.
	 *     @type string[] $clear_on_send Extra meta keys to delete in the confirm save. Default [].
	 *     @type callable $read_fresh    fn( string $key ) => mixed. Re-reads a marker from
	 *                                   storage bypassing the in-memory cache, to VERIFY a
	 *                                   save actually persisted (WC swallows save failures).
	 *                                   Strongly recommended for WC entities. Default none.
	 *     @type array    $context       Extra data (e.g. subscription/order id) attached to
	 *                                   Manager-visible degraded-state logs. Default [].
	 * }
	 * @return bool True if a send was performed in this call, false otherwise.
	 * @throws \Throwable Re-thrown from the send callback (after the pending
	 *                    claim is released) so the caller's failure handling
	 *                    still sees the original error.
	 */
	public static function send( $entity, array $args ): bool {
		foreach ( [ 'sent_key', 'pending_key', 'idem_value', 'send' ] as $required ) {
			if ( ! isset( $args[ $required ] ) ) {
				return false;
			}
		}
		if (
			! is_object( $entity ) ||
			! method_exists( $entity, 'get_meta' ) ||
			! method_exists( $entity, 'update_meta_data' ) ||
			! method_exists( $entity, 'delete_meta_data' ) ||
			! method_exists( $entity, 'save' ) ||
			! is_callable( $args['send'] )
		) {
			return false;
		}

		// Already delivered, or a recent claim holds it → skip. Computing
		// grace lazily inside is_claimed keeps the already-sent path cheap.
		if ( self::is_claimed( $entity, $args ) ) {
			return false;
		}

		$pending_key   = $args['pending_key'];
		$sent_key      = $args['sent_key'];
		$idem          = $args['idem_value'];
		$header        = $args['logger_header'] ?? self::LOGGER_HEADER;
		$save_attempts = max( 1, (int) ( $args['save_attempts'] ?? self::DEFAULT_SAVE_ATTEMPTS ) );
		$clear_on_send = (array) ( $args['clear_on_send'] ?? [] );
		$grace         = self::grace_seconds( $args );

		// We are past is_claimed(), so any matching PENDING here is STALE:
		// the claiming pass died without confirming. Re-send per the
		// over-send policy and surface it in the log.
		$pending = $entity->get_meta( $pending_key, true );
		if ( self::is_pending_for( $pending, $idem ) && isset( $pending['ts'] ) ) {
			Logger::log(
				sprintf(
					'Stale pending send claim (age %ds, grace %ds) for "%s"; re-sending per over-send policy.',
					time() - (int) $pending['ts'],
					$grace,
					$idem
				),
				$header,
				'warning'
			);
		}

		// 1. Claim PENDING durably. Never send without a durable claim — and
		// "durable" means verified: WC swallows save() failures (see
		// persisted()), so a non-throwing save is not proof on its own.
		$claim = [
			'value' => $idem,
			'ts'    => time(),
		];
		$entity->update_meta_data( $pending_key, $claim );
		$result = self::persisted( $entity, $args, $save_attempts, $pending_key, $claim );
		if ( ! $result['ok'] ) {
			self::log_degraded(
				$args,
				'newspack_idempotent_send_claim_unpersisted',
				sprintf( 'Could not durably persist the pending claim for "%s"; skipping the send this pass.', $idem ),
				$result['last_error']
			);
			return false;
		}

		// 2. Send. A throwing callback (e.g. a wp_mail filter/action) would
		// orphan the durable claim; release it with the SAME verified-release
		// + degraded-log path as the false-return branch below, then rethrow
		// so the caller's per-pair Throwable handling still sees the original
		// error. The release is wrapped so a verifier or logging failure can
		// never mask that original send error.
		try {
			$sent = (bool) call_user_func( $args['send'] );
		} catch ( \Throwable $send_error ) {
			try {
				$entity->delete_meta_data( $pending_key );
				$release = self::persisted( $entity, $args, $save_attempts, $pending_key, '' );
				if ( ! $release['ok'] ) {
					self::log_degraded(
						$args,
						'newspack_idempotent_send_release_failed',
						sprintf( 'Send threw for "%s" and releasing the pending claim did not land; a retry may be delayed up to %ds.', $idem, $grace ),
						$release['last_error']
					);
				}
			} catch ( \Throwable $cleanup_error ) {
				// Intentionally swallowed: a cleanup, verifier, or logging
				// failure must not mask the original send error rethrown below.
				unset( $cleanup_error );
			}
			throw $send_error;
		}

		if ( ! $sent ) {
			// Release the claim so a later pass retries cleanly. If the
			// release does not land durably, the (now-recent) claim can
			// suppress the retry for up to the grace window — the wrong
			// failure direction — so surface it to Manager.
			$entity->delete_meta_data( $pending_key );
			$release = self::persisted( $entity, $args, $save_attempts, $pending_key, '' );
			if ( ! $release['ok'] ) {
				self::log_degraded(
					$args,
					'newspack_idempotent_send_release_failed',
					sprintf( 'Send failed for "%s" and releasing the pending claim did not land; a retry may be delayed up to %ds.', $idem, $grace ),
					$release['last_error']
				);
			}
			return false;
		}

		// 3. Confirm: promote PENDING → SENT (and clear companion keys).
		$entity->delete_meta_data( $pending_key );
		$entity->update_meta_data( $sent_key, $idem );
		foreach ( $clear_on_send as $key ) {
			$entity->delete_meta_data( $key );
		}
		$confirm = self::persisted( $entity, $args, $save_attempts, $sent_key, $idem );
		if ( ! $confirm['ok'] ) {
			self::log_degraded(
				$args,
				'newspack_idempotent_send_confirm_unpersisted',
				sprintf( 'Sent "%s" but the SENT marker did not durably persist; a single re-send is possible after the %ds grace window.', $idem, $grace ),
				$confirm['last_error']
			);
		}
		return true;
	}

	/**
	 * Whether a stored meta value is a PENDING claim for the given idem value.
	 * The marker shape (`['value' => ..., 'ts' => ...]`) lives here so callers
	 * and the verification paths agree on it.
	 *
	 * @param mixed  $value Stored meta value.
	 * @param string $idem  Idempotency value to match.
	 * @return bool
	 */
	private static function is_pending_for( $value, string $idem ): bool {
		return is_array( $value ) && isset( $value['value'] ) && $value['value'] === $idem;
	}

	/**
	 * Save the entity and confirm the marker landed DURABLY.
	 *
	 * WC swallows save() failures — `WC_Abstract_Order::save()` catches
	 * `Exception` and still returns the object id, and `save_meta_data()`
	 * does not propagate per-row failures — so a non-throwing save() is not
	 * proof of persistence. When the caller supplies a `read_fresh` callback
	 * we re-read the key from storage (bypassing the in-memory meta cache,
	 * e.g. `WC_Data::read_meta_data( true )`) and require it to match
	 * $expected; with no callback we fall back to "save() did not throw".
	 *
	 * @param object $entity        The entity (already mutated in memory).
	 * @param array  $args          Send args (reads `read_fresh`).
	 * @param int    $save_attempts Bounded save retries.
	 * @param string $key           Meta key to confirm.
	 * @param mixed  $expected      Expected durable value; the PENDING claim
	 *                              array, the SENT string, or '' for absence.
	 * @return array{ok: bool, last_error: string}
	 */
	private static function persisted( $entity, array $args, int $save_attempts, string $key, $expected ): array {
		$save = self::save_with_retry( $entity, $save_attempts );
		if ( ! $save['saved'] ) {
			return [
				'ok'         => false,
				'last_error' => $save['last_error'],
			];
		}

		$read_fresh = $args['read_fresh'] ?? null;
		if ( is_callable( $read_fresh ) ) {
			$fresh = call_user_func( $read_fresh, $key );
			// A claim is matched by value (its `ts` is volatile); SENT and the
			// empty "absence" sentinel are matched exactly.
			$matched = is_array( $expected )
				? self::is_pending_for( $fresh, (string) ( $expected['value'] ?? '' ) )
				: $fresh === $expected;
			if ( ! $matched ) {
				return [
					'ok'         => false,
					'last_error' => 'save() reported success but a fresh read did not match the expected marker (a swallowed WC write failure).',
				];
			}
		}
		return [
			'ok'         => true,
			'last_error' => '',
		];
	}

	/**
	 * Emit a Manager-visible log for an operator-actionable degraded state.
	 *
	 * Uses `Logger::newspack_log` (fires the `newspack_log` action consumed by
	 * Newspack Manager) so these rare failures are visible even when
	 * `NEWSPACK_LOG_LEVEL` gates the local error log off, and includes the
	 * caller-provided `context` (e.g. the subscription/order id) plus the
	 * underlying save/verify reason.
	 *
	 * @param array  $args       Send args (reads `context`, `logger_header`).
	 * @param string $code       Manager log code.
	 * @param string $message    Human-readable message.
	 * @param string $last_error The underlying save/verify failure detail.
	 */
	private static function log_degraded( array $args, string $code, string $message, string $last_error ): void {
		$data = array_merge(
			(array) ( $args['context'] ?? [] ),
			[
				'header'     => $args['logger_header'] ?? self::LOGGER_HEADER,
				'last_error' => $last_error,
			]
		);
		Logger::newspack_log( $code, $message, $data, 'error' );
	}

	/**
	 * Resolve the grace window (seconds) a PENDING claim is treated as
	 * in-progress, applying the filter. Kept private so the marker shape and
	 * the filter name live in one place.
	 *
	 * @param array $args Send/predicate args (reads `grace_seconds`, `sent_key`).
	 * @return int Grace window in seconds.
	 */
	private static function grace_seconds( array $args ): int {
		/**
		 * Filters how long (in seconds) a PENDING claim is treated as
		 * in-progress before a later pass considers it stale and re-sends.
		 *
		 * @param int    $grace_seconds Grace window in seconds.
		 * @param string $sent_key      The SENT meta key (identifies the flow).
		 */
		return (int) apply_filters(
			'newspack_idempotent_send_grace_seconds',
			(int) ( $args['grace_seconds'] ?? HOUR_IN_SECONDS ),
			$args['sent_key'] ?? ''
		);
	}

	/**
	 * Persist a WC_Data entity with a bounded immediate retry, swallowing
	 * throwables. Most save() failures are transient (a momentary lock, a
	 * DB blip), so an immediate retry narrows the window where a marker is
	 * missing. Bounded; never throws.
	 *
	 * @param object $entity   Entity to save (duck-typed on save()).
	 * @param int    $attempts Max attempts (>= 1).
	 * @return array{saved: bool, last_error: string} Whether the save landed,
	 *               and the last error message if it did not.
	 */
	public static function save_with_retry( $entity, int $attempts = self::DEFAULT_SAVE_ATTEMPTS ): array {
		$attempts   = max( 1, $attempts );
		$last_error = '';
		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			try {
				$entity->save();
				return [
					'saved'      => true,
					'last_error' => '',
				];
			} catch ( \Throwable $e ) {
				$last_error = $e->getMessage();
			}
		}
		return [
			'saved'      => false,
			'last_error' => $last_error,
		];
	}
}
