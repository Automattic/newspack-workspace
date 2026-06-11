/**
 * useSubscribersData (NPPD-1616).
 *
 * React hook owning the Tab 6 data fetch lifecycle. Refetches whenever
 * the active range or comparison range changes; serializes overlapping
 * requests via a request-id guard so the latest call wins. Exposes
 * idle / loading / success / error state plus a manual `refetch()` for
 * future force-refresh actions.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import { fetchSubscribersData, type SubscribersResponse } from '../api/subscribers';

export type SubscribersFetchStatus = 'idle' | 'loading' | 'success' | 'error';

export interface UseSubscribersDataResult {
	status: SubscribersFetchStatus;
	data: SubscribersResponse | null;
	error: string | null;
	refetch: () => void;
}

const errorMessage = ( e: unknown ): string => {
	if ( e && typeof e === 'object' && 'message' in e && typeof ( e as { message: unknown } ).message === 'string' ) {
		return ( e as { message: string } ).message;
	}
	return String( e );
};

/**
 * Fetch Tab 6 data for the given current/comparison range pair.
 *
 * Refetches on:
 *   - range change (start or end)
 *   - previousRange change (toggle, or current range moved so the
 *     comparison window recomputes)
 *   - explicit refetch()
 */
const useSubscribersData = ( range: DateRange, previousRange: DateRange | null ): UseSubscribersDataResult => {
	const [ status, setStatus ] = useState< SubscribersFetchStatus >( 'idle' );
	const [ data, setData ] = useState< SubscribersResponse | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	// Request-id guard. Each call increments; only the latest id may write
	// to state. Prevents older slow calls from overwriting newer ones on
	// rapid range switches.
	const requestIdRef = useRef( 0 );

	// Bump on refetch() to retrigger the effect without changing inputs.
	const [ refetchTick, setRefetchTick ] = useState( 0 );
	const refetch = useCallback( () => setRefetchTick( t => t + 1 ), [] );

	useEffect( () => {
		const myId = ++requestIdRef.current;
		setStatus( 'loading' );
		setError( null );

		fetchSubscribersData( {
			start: range.start,
			end: range.end,
			compare_start: previousRange?.start,
			compare_end: previousRange?.end,
		} )
			.then( response => {
				if ( requestIdRef.current !== myId ) {
					return;
				}
				setData( response );
				setStatus( 'success' );
			} )
			.catch( e => {
				if ( requestIdRef.current !== myId ) {
					return;
				}
				setError( errorMessage( e ) );
				setStatus( 'error' );
			} );
	}, [ range.start, range.end, previousRange?.start, previousRange?.end, refetchTick ] );

	return { status, data, error, refetch };
};

export default useSubscribersData;
