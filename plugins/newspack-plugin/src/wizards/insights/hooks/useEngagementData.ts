/**
 * useEngagementData (NPPD-1649).
 *
 * Tab 2's fetch lifecycle. Mirrors {@see useAudienceData}.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import { fetchEngagementData, type EngagementResponse } from '../api/engagement';

export type FetchStatus = 'idle' | 'loading' | 'success' | 'error';

export interface UseEngagementDataResult {
	status: FetchStatus;
	data: EngagementResponse | null;
	error: string | null;
	refetch: () => void;
}

const errorMessage = ( e: unknown ): string => {
	if ( e && typeof e === 'object' && 'message' in e && typeof ( e as { message: unknown } ).message === 'string' ) {
		return ( e as { message: string } ).message;
	}
	return String( e );
};

const useEngagementData = ( range: DateRange, previousRange: DateRange | null ): UseEngagementDataResult => {
	const [ status, setStatus ] = useState< FetchStatus >( 'idle' );
	const [ data, setData ] = useState< EngagementResponse | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	const requestIdRef = useRef( 0 );
	const [ refetchTick, setRefetchTick ] = useState( 0 );
	const refetch = useCallback( () => setRefetchTick( t => t + 1 ), [] );

	useEffect( () => {
		const myId = ++requestIdRef.current;
		setStatus( 'loading' );
		setError( null );

		fetchEngagementData( {
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

export default useEngagementData;
