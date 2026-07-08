/**
 * usePollWhileWarming (NEWS-2603).
 *
 * Shared poll used by the three insights tab hooks. While a tab payload's
 * `data_status` is `warming` (the hub snapshot is still computing), this
 * re-fetches quietly every ~20s so the warmed value fills in on its own.
 * If it is STILL warming after a cap (~2 min), it escalates by overriding
 * `data_status` to `incomplete` on a shallow copy — warming that never
 * resolves is a genuine failure, so the soft banner becomes the warning
 * banner. The store slot itself is never mutated.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useMemo, useState } from '@wordpress/element';

export const POLL_INTERVAL_MS = 20000; // ~20s between re-fetches.
export const MAX_POLL_ATTEMPTS = 6; // ~2 min total, then escalate.

type WithDataStatus = { data_status?: 'complete' | 'warming' | 'incomplete' };

/**
 * Poll while a payload is warming, escalating to `incomplete` at the cap.
 *
 * @param key     Slot key; changing it (new window/date range) resets the poll.
 * @param data    The current tab payload from the store, or null.
 * @param refetch Stable, unconditional re-fetch of the slot.
 * @return The payload unchanged, or — once escalated — a shallow copy with
 *         `data_status` set to `incomplete`.
 */
const usePollWhileWarming = < T extends WithDataStatus >( key: string, data: T | null, refetch: () => void ): T | null => {
	const [ attempts, setAttempts ] = useState( 0 );
	const [ escalated, setEscalated ] = useState( false );

	const dataStatus = data?.data_status;

	// A window/date-range change (new key) starts the poll fresh.
	useEffect( () => {
		setAttempts( 0 );
		setEscalated( false );
	}, [ key ] );

	// Scheduling effect. `attempts` is intentionally state, not a ref: the
	// payload stays `warming` across re-fetches, so a `dataStatus`-keyed effect
	// would not re-fire on its own — the `attempts` change is what re-triggers
	// scheduling after each poll tick.
	useEffect( () => {
		if ( dataStatus !== 'warming' ) {
			// No longer warming: clear any accumulated poll state (guarded so we
			// don't loop by setting an unchanged value).
			if ( attempts !== 0 ) {
				setAttempts( 0 );
			}
			if ( escalated ) {
				setEscalated( false );
			}
			return;
		}

		if ( attempts >= MAX_POLL_ATTEMPTS ) {
			// Warmed too long: escalate and stop polling until the key changes.
			if ( ! escalated ) {
				setEscalated( true );
			}
			return;
		}

		const id = setTimeout( () => {
			setAttempts( a => a + 1 );
			refetch();
		}, POLL_INTERVAL_MS );

		return () => clearTimeout( id );
	}, [ key, dataStatus, attempts, escalated, refetch ] );

	// Only escalate a copy; never touch the store slot. Returns the SAME `data`
	// reference unless escalated, so warming/complete payloads don't re-render.
	return useMemo( () => ( escalated && data ? { ...data, data_status: 'incomplete' } : data ), [ data, escalated ] );
};

export default usePollWhileWarming;
